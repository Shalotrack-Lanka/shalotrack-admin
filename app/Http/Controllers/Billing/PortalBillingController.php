<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Admin\Billing\InvoiceController;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingPdf;
use App\Services\Billing\PortalScope;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * READ-ONLY billing for distributors and dealers. What a login may see is decided by PortalScope on the
 * server (dealer: own account; distributor: own + their dealers'). Anything else is a 404.
 * Nobody here can issue, record, edit or reverse anything.
 */
class PortalBillingController extends Controller
{
    public function __construct(private Billing $billing, private InvoiceController $invoices)
    {
    }

    public function index()
    {
        $scope = PortalScope::forUser(auth()->user());
        $own = Dealer::findOrFail($scope->ownId);

        $network = collect();
        if ($scope->role === 'DISTRIBUTOR') {
            $network = Dealer::whereIn('id', $scope->dealerIds)->orderBy('full_name')->get(['id', 'full_name', 'status'])
                ->map(fn ($d) => ['dealer' => $d] + $this->billing->account($d->id));
        }

        return view('billing.portal.index', $this->frame($scope) + [
            'dealer'   => $own,
            'account'  => $this->billing->account($own->id),
            'invoices' => Invoice::where('buyer_dealer_id', $own->id)->orderByDesc('id')->limit(100)->get()
                ->map(fn ($i) => tap($i, fn ($x) => $x->outstanding_cents = $this->billing->invoiceOutstanding($x))),
            'receipts' => PaymentReceipt::where('buyer_dealer_id', $own->id)->whereIn('status', ['valid', 'reversed'])->orderByDesc('id')->limit(100)->get(),
            'network'  => $network,
            'today'    => now(Billing::TZ)->toDateString(),
        ]);
    }

    public function invoice(Invoice $invoice)
    {
        $scope = $this->allowed($invoice->buyer_dealer_id);

        return view('billing.shared.invoice', $this->invoices->invoiceData($invoice, $this->frame($scope)['layout'], $this->frame($scope)['routePrefix']) + ['readOnly' => true]);
    }

    public function invoicePdf(Invoice $invoice, BillingPdf $pdf)
    {
        $this->allowed($invoice->buyer_dealer_id);

        return $pdf->download('billing.pdf.invoice', $this->invoices->pdfData($invoice), $invoice->number . '.pdf', 'Invoice ' . $invoice->number);
    }

    public function statement(Request $request, ?Dealer $dealer = null)
    {
        $scope = PortalScope::forUser(auth()->user());
        $target = $dealer ?? Dealer::findOrFail($scope->ownId);
        abort_unless($scope->mayView($target->id), 404);

        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $now = now(Billing::TZ);
        $from = $v['from'] ?? $now->copy()->startOfMonth()->toDateString();
        $to = $v['to'] ?? $now->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return view('billing.shared.statement', $this->frame($scope) + [
            'dealer' => $target, 'party' => $this->billing->partyFor($target),
            'account' => $this->billing->account($target->id), 'stmt' => $this->billing->statement($target->id, $from, $to),
            'from' => $from, 'to' => $to,
            'invoices' => Invoice::where('buyer_dealer_id', $target->id)->orderByDesc('id')->limit(100)->get()
                ->map(fn ($i) => tap($i, fn ($x) => $x->outstanding_cents = $this->billing->invoiceOutstanding($x))),
            'receipts' => PaymentReceipt::where('buyer_dealer_id', $target->id)->whereIn('status', ['valid', 'reversed'])->orderByDesc('id')->limit(100)->get(),
            'canManage' => false,
        ]);
    }

    private function allowed(int $buyerDealerId): PortalScope
    {
        $scope = PortalScope::forUser(auth()->user());
        abort_unless($scope->mayView($buyerDealerId), 404);

        return $scope;
    }

    /** layout + route names for the current portal */
    private function frame(PortalScope $scope): array
    {
        return $scope->role === 'DISTRIBUTOR'
            ? ['layout' => 'layouts.distributor', 'routePrefix' => 'distributor.billing', 'scope' => $scope]
            : ['layout' => 'layouts.dealer', 'routePrefix' => 'dealer.billing', 'scope' => $scope];
    }
}
