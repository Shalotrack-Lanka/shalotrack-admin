<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingParty;
use App\Models\Dealer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\Audit;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingPdf;
use App\Services\Billing\BillingSettings;
use App\Services\RenewalsReport;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;

/** Admin: billing home, accounts and statements, buyer billing details, settings, integrity check. */
class BillingController extends Controller
{
    public function __construct(private Billing $billing, private BillingSettings $settings)
    {
    }

    public function home()
    {
        $buyers = Dealer::query()
            ->where(fn ($q) => $q->whereIn('id', Invoice::query()->select('buyer_dealer_id'))
                ->orWhereIn('id', PaymentReceipt::query()->select('buyer_dealer_id')))
            ->orderBy('full_name')->get(['id', 'full_name', 'dealer_status']);

        $rows = $buyers->map(fn ($d) => ['dealer' => $d] + $this->billing->account($d->id));
        $ready = true;
        try {
            $this->billing->company();
        } catch (DomainException) {
            $ready = false;
        }

        return view('billing.admin.home', [
            'ready'      => $ready,
            'rows'       => $rows->sortByDesc('balance')->values(),
            'totals'     => [
                'balance' => $rows->sum('balance'), 'overdue' => $rows->sum('overdue'), 'pending' => $rows->sum('pending'),
                'invoiced' => $rows->sum('invoiced'), 'paid' => $rows->sum('paid'),
            ],
            'unbilled'   => $this->billing->unbilledTransfers()->count(),
            'pendingReceipts' => PaymentReceipt::where('status', PaymentReceipt::PENDING)->count(),
            'recentInvoices'  => Invoice::with('buyer')->orderByDesc('id')->limit(6)->get(),
            'recentReceipts'  => PaymentReceipt::with('buyer')->orderByDesc('id')->limit(6)->get(),
            'approval'   => $this->settings->approvalRequired(),
        ]);
    }

    public function accounts()
    {
        return redirect()->route('admin.billing.home');
    }

    public function statement(Request $request, Dealer $dealer)
    {
        [$from, $to] = $this->range($request);
        $party = $this->billing->partyFor($dealer);

        return view('billing.shared.statement', [
            'layout' => 'layouts.admin', 'routePrefix' => 'admin.billing', 'dealer' => $dealer, 'party' => $party,
            'account' => $this->billing->account($dealer->id), 'stmt' => $this->billing->statement($dealer->id, $from, $to),
            'from' => $from, 'to' => $to, 'invoices' => Invoice::where('buyer_dealer_id', $dealer->id)->orderByDesc('id')->get()
                ->map(fn ($i) => tap($i, fn ($x) => $x->outstanding_cents = $this->billing->invoiceOutstanding($x))),
            'receipts' => PaymentReceipt::where('buyer_dealer_id', $dealer->id)->orderByDesc('id')->get(),
            'canManage' => true,
        ]);
    }

    public function statementCsv(Request $request, Dealer $dealer)
    {
        [$from, $to] = $this->range($request);
        $s = $this->billing->statement($dealer->id, $from, $to);
        Audit::record('billing.document_downloaded', 'statement', $dealer->id, 'Statement CSV ' . $dealer->full_name, [], ['from' => $from, 'to' => $to]);

        return response()->streamDownload(function () use ($s, $dealer, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Statement', RenewalsReport::csvSafe($dealer->full_name), $from, $to]);
            fputcsv($out, ['Date', 'Type', 'Number', 'Note', 'Amount (LKR)', 'Balance (LKR)']);
            fputcsv($out, ['', 'Opening balance', '', '', '', Money::fromCents($s['opening'])]);
            foreach ($s['rows'] as $r) {
                fputcsv($out, [$r['date'], $r['type'], $r['number'], RenewalsReport::csvSafe($r['note']), Money::fromCents($r['amount']), Money::fromCents($r['balance'])]);
            }
            fputcsv($out, ['', 'Closing balance', '', '', '', Money::fromCents($s['closing'])]);
            fclose($out);
        }, 'statement-' . $dealer->id . '-' . $from . '_' . $to . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function statementPdf(Request $request, Dealer $dealer, BillingPdf $pdf)
    {
        [$from, $to] = $this->range($request);
        try {
            $company = $this->billing->company()->toSnapshot();
        } catch (DomainException $e) {
            return back()->withErrors(['pdf' => $e->getMessage()]);
        }

        return $pdf->download('billing.pdf.statement', [
            'company' => $company, 'party' => $this->billing->partyFor($dealer)->toSnapshot(),
            'stmt' => $this->billing->statement($dealer->id, $from, $to), 'from' => $from, 'to' => $to,
        ], 'statement-' . $dealer->id . '-' . $from . '_' . $to . '.pdf', 'Statement ' . $dealer->full_name);
    }

    public function partyUpdate(Request $request, Dealer $dealer)
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'address'    => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'phone'      => ['nullable', 'string', 'max:40'],
            'email'      => ['nullable', 'email', 'max:150'],
        ]);
        $party = $this->billing->partyFor($dealer);
        $before = $party->only(array_keys($data));
        $party->update($data);
        Audit::record('billing.party_updated', 'billing_party', $party->id, $dealer->full_name, Audit::diff($before, $data, array_keys($data)));

        return back()->with('success', 'Billing details saved. Invoices already issued keep the details they were issued with.');
    }

    public function settings()
    {
        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();

        return view('billing.admin.settings', ['s' => $this->settings->all(), 'company' => $company, 'verify' => session('verify')]);
    }

    public function settingsUpdate(Request $request)
    {
        $data = $request->validate([
            'legal_name'  => ['required', 'string', 'max:200'],
            'address'     => ['required', 'string', 'max:500'],
            'tax_number'  => ['nullable', 'string', 'max:60'],
            'phone'       => ['nullable', 'string', 'max:40'],
            'email'       => ['nullable', 'email', 'max:150'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
            'tax_enabled' => ['nullable', 'boolean'],
            'tax_rate'    => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'tax_label'   => ['nullable', 'string', 'max:30'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'approval_required' => ['nullable', 'boolean'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->boolean('tax_enabled') && (float) ($data['tax_rate'] ?? 0) <= 0) {
            return back()->withInput()->withErrors(['tax_rate' => 'Enter the tax rate your accountant confirmed, or switch tax off.']);
        }

        $partyFields = ['legal_name', 'address', 'tax_number', 'phone', 'email', 'bank_details'];
        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        $partyData = array_intersect_key($data, array_flip($partyFields));
        if ($company) {
            $before = $company->only($partyFields);
            $company->update($partyData);
            $diff = Audit::diff($before, $partyData, $partyFields);
            if ($diff !== []) {
                Audit::record('billing.party_updated', 'billing_party', $company->id, 'Company details', $diff);
            }
        } else {
            $company = BillingParty::create($partyData + ['kind' => BillingParty::KIND_COMPANY]);
            Audit::record('billing.party_updated', 'billing_party', $company->id, 'Company details created');
        }

        $this->settings->save([
            'tax_enabled' => $request->boolean('tax_enabled') ? '1' : '0',
            'tax_rate'    => Money::fromCents(Money::toCents($data['tax_rate'] ?? '0')),
            'tax_label'   => $data['tax_label'] ?: 'VAT',
            'payment_terms_days' => (string) $data['payment_terms_days'],
            'approval_required' => $request->boolean('approval_required') ? '1' : '0',
            'invoice_footer' => (string) ($data['invoice_footer'] ?? ''),
        ], auth()->user()->admin_id);

        return back()->with('success', 'Billing settings saved.');
    }

    public function verify()
    {
        $problems = $this->billing->verify();
        Audit::record('billing.document_downloaded', 'integrity', 'verify', 'Integrity check run', [], ['problems' => count($problems)]);

        return back()->with('verify', ['problems' => $problems, 'at' => now(Billing::TZ)->format('Y-m-d H:i')]);
    }

    /** @return array{0:string,1:string} Sri Lanka days, default: this calendar month so far */
    private function range(Request $request): array
    {
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $now = now(Billing::TZ);
        $from = $v['from'] ?? $now->copy()->startOfMonth()->toDateString();
        $to = $v['to'] ?? $now->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
