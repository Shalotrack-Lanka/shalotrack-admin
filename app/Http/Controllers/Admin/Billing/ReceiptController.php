<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingPdf;
use App\Services\Billing\BillingSettings;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Admin: record payments received, approve / reject (when switched on), reverse. */
class ReceiptController extends Controller
{
    public function __construct(private Billing $billing, private BillingSettings $settings)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'dealer' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,pending,valid,reversed,rejected'],
        ]);
        $status = $f['status'] ?? 'all';

        $receipts = PaymentReceipt::query()->with('buyer')->orderByDesc('id')
            ->when($f['dealer'] ?? null, fn ($q, $d) => $q->where('buyer_dealer_id', $d))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->paginate(25)->withQueryString();

        return view('billing.admin.receipts', [
            'receipts' => $receipts,
            'filters'  => ['dealer' => $f['dealer'] ?? null, 'status' => $status],
            'buyers'   => Dealer::whereIn('id', PaymentReceipt::query()->select('buyer_dealer_id'))->orderBy('full_name')->get(['id', 'full_name']),
            'approval' => $this->settings->approvalRequired(),
            'pending'  => PaymentReceipt::where('status', PaymentReceipt::PENDING)->count(),
            'me'       => (string) auth()->user()->admin_id,
        ]);
    }

    public function create(Request $request)
    {
        $buyers = Dealer::whereIn('id', Invoice::query()->select('buyer_dealer_id'))->orderBy('full_name')->get(['id', 'full_name']);
        $buyer = null;
        $open = collect();
        $account = null;
        if ($request->filled('dealer')) {
            $buyer = Dealer::find((int) $request->query('dealer'));
            if ($buyer) {
                $account = $this->billing->account($buyer->id);
                $open = Invoice::where('buyer_dealer_id', $buyer->id)->orderBy('due_date')->get()
                    ->map(fn ($i) => tap($i, fn ($x) => $x->outstanding_cents = $this->billing->invoiceOutstanding($x)))
                    ->filter(fn ($i) => $i->outstanding_cents > 0)->values();
            }
        }

        return view('billing.admin.receipt-new', [
            'buyers' => $buyers, 'buyer' => $buyer, 'open' => $open, 'account' => $account,
            'approval' => $this->settings->approvalRequired(),
            'today' => now(Billing::TZ)->toDateString(),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'dealer_id'  => ['required', 'integer', 'exists:dealers,id'],
            'amount'     => ['required', 'string', 'max:20'],
            'method'     => ['required', 'in:' . implode(',', array_keys(PaymentReceipt::METHODS))],
            'reference'  => ['nullable', 'string', 'max:120'],
            'received_date' => ['required', 'date'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'notes'      => ['nullable', 'string', 'max:500'],
            'token'      => ['required', 'uuid'],
        ]);

        try {
            $receipt = $this->billing->recordReceipt(
                Dealer::findOrFail($data['dealer_id']), $data['amount'], $data['method'], $data['reference'] ?? null,
                $data['received_date'], $data['invoice_id'] ?? null, $data['notes'] ?? null, $data['token'],
            );
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $msg = $receipt->status === PaymentReceipt::PENDING
            ? "Payment {$receipt->number} recorded and is waiting for approval by another administrator."
            : "Payment {$receipt->number} recorded.";

        return redirect()->route('admin.billing.receipts.show', $receipt)->with('success', $msg);
    }

    public function show(PaymentReceipt $receipt)
    {
        return view('billing.admin.receipt-show', [
            'receipt' => $receipt->load('buyer', 'allocations.invoice'),
            'snap'    => $receipt->snapshot,
            'me'      => (string) auth()->user()->admin_id,
        ]);
    }

    public function pdf(PaymentReceipt $receipt, BillingPdf $pdf)
    {
        abort_unless(in_array($receipt->status, [PaymentReceipt::VALID, PaymentReceipt::REVERSED], true), 404);

        return $pdf->download('billing.pdf.receipt', ['snap' => $receipt->snapshot, 'number' => $receipt->number, 'status' => $receipt->status], $receipt->number . '.pdf', 'Receipt ' . $receipt->number);
    }

    public function approve(PaymentReceipt $receipt)
    {
        return $this->act(fn () => $this->billing->approveReceipt($receipt), $receipt, 'Payment approved.');
    }

    public function reject(Request $request, PaymentReceipt $receipt)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return $this->act(fn () => $this->billing->rejectReceipt($receipt, $reason), $receipt, 'Payment rejected.');
    }

    public function reverse(Request $request, PaymentReceipt $receipt)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return $this->act(fn () => $this->billing->reverseReceipt($receipt, $reason), $receipt, 'Payment reversed. The buyer\'s balance was restored.');
    }

    private function act(callable $do, PaymentReceipt $receipt, string $ok)
    {
        try {
            $do();
        } catch (DomainException $e) {
            return redirect()->route('admin.billing.receipts.show', $receipt)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('admin.billing.receipts.show', $receipt)->with('success', $ok);
    }
}
