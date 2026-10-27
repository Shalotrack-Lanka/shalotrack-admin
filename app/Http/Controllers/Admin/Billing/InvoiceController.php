<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\Invoice;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingPdf;
use App\Services\Billing\BillingSettings;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Admin: build and issue invoices from transfers, credit notes, document pages and PDFs. */
class InvoiceController extends Controller
{
    public function __construct(private Billing $billing, private BillingSettings $settings)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'dealer' => ['nullable', 'integer'],
            'q'      => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:all,open,overdue,paid'],
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date'],
        ]);

        $query = Invoice::query()->with('buyer')->orderByDesc('id')
            ->when($f['dealer'] ?? null, fn ($q, $d) => $q->where('buyer_dealer_id', $d))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where('number', 'like', '%' . addcslashes($t, '%_\\') . '%'))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '<=', $d));

        $invoices = $query->get()->map(function (Invoice $i) {
            $i->outstanding_cents = $this->billing->invoiceOutstanding($i);

            return $i;
        });
        $today = now(Billing::TZ)->toDateString();
        $status = $f['status'] ?? 'all';
        $invoices = $invoices->filter(fn ($i) => match ($status) {
            'open'    => $i->outstanding_cents > 0,
            'overdue' => $i->outstanding_cents > 0 && $i->due_date->toDateString() < $today,
            'paid'    => $i->outstanding_cents <= 0,
            default   => true,
        })->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $invoices->forPage($page, 25), $invoices->count(), 25, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('billing.admin.invoices', [
            'invoices' => $paginated,
            'filters'  => $f + ['status' => $status],
            'buyers'   => Dealer::whereIn('id', Invoice::query()->select('buyer_dealer_id'))->orderBy('full_name')->get(['id', 'full_name']),
            'today'    => $today,
        ]);
    }

    /** Pick a buyer, then their unbilled transfers with a price box each. */
    public function create(Request $request)
    {
        $buyers = Dealer::query()
            ->whereIn('id', $this->billing->unbilledTransfers()->select('dealer_transfer_ledgers.dealer_id'))
            ->orderBy('full_name')->get(['id', 'full_name', 'dealer_status']);

        $buyer = null;
        $transfers = collect();
        if ($request->filled('dealer')) {
            $buyer = Dealer::find((int) $request->query('dealer'));
            if ($buyer) {
                $transfers = $this->billing->unbilledTransfers($buyer->id)->with('fromDealer')->orderBy('id')->get();
            }
        }

        return view('billing.admin.invoice-new', [
            'buyers'    => $buyers,
            'buyer'     => $buyer,
            'transfers' => $transfers,
            'tax'       => $this->settings->taxEnabled() ? ['rate' => $this->settings->get('tax_rate'), 'label' => $this->settings->get('tax_label')] : null,
            'terms'     => $this->settings->termsDays(),
            'ready'     => $this->companyReady(),
        ]);
    }

    /** Shows exactly what will be issued. Nothing is written. */
    public function review(Request $request)
    {
        $data = $request->validate([
            'dealer_id'    => ['required', 'integer', 'exists:dealers,id'],
            'prices'       => ['required', 'array', 'min:1', 'max:200'],
            'prices.*'     => ['nullable', 'string', 'max:20'],
            'include'      => ['required', 'array', 'min:1', 'max:200'],
            'include.*'    => ['integer', 'min:1'],
            'notes'        => ['nullable', 'string', 'max:500'],
            'zero_reason'  => ['nullable', 'string', 'max:255'],
        ], ['include.required' => 'Tick at least one transfer to invoice.']);

        $buyer = Dealer::findOrFail($data['dealer_id']);
        $prices = [];
        foreach ($data['include'] as $id) {
            $prices[(int) $id] = $data['prices'][$id] ?? '';
        }

        try {
            $quote = $this->billing->quote($buyer, $prices);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['prices' => $e->getMessage()]);
        }

        return view('billing.admin.invoice-review', [
            'buyer'  => $buyer,
            'party'  => $this->billing->partyFor($buyer),
            'quote'  => $quote,
            'prices' => $prices,
            'notes'  => $data['notes'] ?? null,
            'zero_reason' => $data['zero_reason'] ?? null,
            'terms'  => $this->settings->termsDays(),
            'token'  => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'dealer_id'   => ['required', 'integer', 'exists:dealers,id'],
            'prices'      => ['required', 'array', 'min:1', 'max:200'],
            'prices.*'    => ['required', 'string', 'max:20'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'zero_reason' => ['nullable', 'string', 'max:255'],
            'token'       => ['required', 'uuid'],
        ]);

        try {
            $invoice = $this->billing->issueInvoice(
                Dealer::findOrFail($data['dealer_id']),
                collect($data['prices'])->mapWithKeys(fn ($p, $id) => [(int) $id => $p])->all(),
                $data['notes'] ?? null,
                $data['zero_reason'] ?? null,
                $data['token'],
            );
        } catch (DomainException $e) {
            return redirect()->route('admin.billing.invoices.new', ['dealer' => $data['dealer_id']])->withErrors(['prices' => $e->getMessage()]);
        }

        return redirect()->route('admin.billing.invoices.show', $invoice)->with('success', "Invoice {$invoice->number} issued.");
    }

    public function show(Invoice $invoice)
    {
        return view('billing.shared.invoice', $this->invoiceData($invoice, 'layouts.admin', 'admin.billing'));
    }

    public function pdf(Invoice $invoice, BillingPdf $pdf)
    {
        return $pdf->download('billing.pdf.invoice', $this->pdfData($invoice), $invoice->number . '.pdf', 'Invoice ' . $invoice->number);
    }

    public function creditForm(Invoice $invoice)
    {
        $invoice->load('lines');

        return view('billing.admin.credit-form', [
            'invoice'    => $invoice,
            'creditable' => $this->billing->creditableLines($invoice),
            'token'      => (string) Str::uuid(),
        ]);
    }

    public function creditStore(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'qty'    => ['required', 'array', 'max:500'],
            'qty.*'  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:255'],
            'token'  => ['required', 'uuid'],
        ]);

        try {
            $note = $this->billing->issueCreditNote($invoice, $data['qty'], $data['reason'], $data['token']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('admin.billing.credit-notes.show', $note)->with('success', "Credit note {$note->number} issued.");
    }

    public function creditShow(CreditNote $creditNote)
    {
        return view('billing.shared.credit-note', [
            'layout' => 'layouts.admin', 'note' => $creditNote->load('invoice'),
            'snap' => $creditNote->snapshot, 'pdfRoute' => route('admin.billing.credit-notes.pdf', $creditNote),
            'invoiceRoute' => route('admin.billing.invoices.show', $creditNote->invoice_id),
        ]);
    }

    public function creditPdf(CreditNote $creditNote, BillingPdf $pdf)
    {
        return $pdf->download('billing.pdf.credit-note', ['snap' => $creditNote->snapshot, 'number' => $creditNote->number], $creditNote->number . '.pdf', 'Credit note ' . $creditNote->number);
    }

    // ------------------------------------------------------------------ shared with the portals

    public function invoiceData(Invoice $invoice, string $layout, string $routePrefix): array
    {
        $invoice->load(['lines', 'creditNotes', 'buyer']);
        $paid = \Illuminate\Support\Facades\DB::table('payment_allocations as a')
            ->join('payment_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('a.invoice_id', $invoice->id)->where('r.status', 'valid')
            ->orderBy('r.received_date')
            ->get(['r.number', 'r.received_date', 'r.method', 'r.reference', 'a.amount']);

        return [
            'layout'      => $layout,
            'routePrefix' => $routePrefix,
            'invoice'     => $invoice,
            'snap'        => $invoice->snapshot,
            'outstanding' => $this->billing->invoiceOutstanding($invoice),
            'payments'    => $paid,
            'today'       => now(Billing::TZ)->toDateString(),
            'creditable'  => array_sum($this->billing->creditableLines($invoice)),
        ];
    }

    public function pdfData(Invoice $invoice): array
    {
        return ['snap' => $invoice->snapshot, 'number' => $invoice->number, 'due' => $invoice->due_date->toDateString()];
    }

    private function companyReady(): bool
    {
        try {
            $this->billing->company();

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
