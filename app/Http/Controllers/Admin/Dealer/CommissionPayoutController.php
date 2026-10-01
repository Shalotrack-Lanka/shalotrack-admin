<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Exceptions\CommissionPayoutException;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\CommissionStatement;
use App\Services\RenewalsReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly dealer commission statements.
 *
 *  - ADMIN sees every dealer, can export a statement,
 *    and can mark a finished month as paid.
 *  - A DEALER sees only statements that have actually been PAID to them: never a preview, never
 *    anything unapproved, never another dealer's.
 */
class CommissionPayoutController extends Controller
{
    public function __construct(private CommissionStatement $statements)
    {
    }

    public function index(Request $request)
    {
        $month = $this->month($request);
        $period = CommissionStatement::period($month);
        $rows = $this->statements->summary($month);

        $readyCents = array_sum(array_map(fn ($r) => $r['status'] === 'ready' ? $r['net_cents'] : 0, $rows));

        return view('commission-payouts.index', [
            'month'           => $month,
            'period'          => $period,
            'rows'            => $rows,
            'readyCents'      => $readyCents,
            'ratesConfigured' => $this->statements->ratesConfigured(),
            'months'          => $this->monthChoices(),
        ]);
    }

    public function show(Request $request, Dealer $dealer)
    {
        $month = $this->month($request);
        $statement = $this->statements->forDealer($dealer, $month);

        if ($request->query('export') === 'csv') {
            return $this->csv($statement);
        }

        return view('commission-payouts.show', [
            'month'           => $month,
            's'               => $statement,
            'ratesConfigured' => $this->statements->ratesConfigured(),
            'today'           => now(CommissionStatement::TZ)->toDateString(),
        ]);
    }

    public function pay(Request $request, Dealer $dealer)
    {
        $month = $this->month($request);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            // The latest allowed date is "today" in Sri Lanka, not in the server's timezone.
            'paid_on'   => ['required', 'date', 'before_or_equal:' . now(CommissionStatement::TZ)->toDateString()],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $user = auth()->user();

        try {
            $payout = $this->statements->markPaid(
                $dealer,
                $month,
                $data['reference'],
                Carbon::parse($data['paid_on']),
                (string) $user->admin_id,
                (string) ($user->full_name ?: $user->username),
                $data['notes'] ?? null
            );
        } catch (CommissionPayoutException $e) {
            return redirect()->route('admin.commission-payouts.show', [$dealer, 'month' => $month])
                ->withErrors(['payout' => $e->getMessage()]);
        }

        // Audit trail beyond the payout row itself.
        Log::info('Commission payout recorded', [
            'payout_id' => $payout->id, 'dealer_id' => $dealer->id, 'period' => $month,
            'net' => (string) $payout->net_amount, 'reference' => $payout->reference, 'by' => $user->admin_id,
        ]);

        return redirect()->route('admin.commission-payouts.show', [$dealer, 'month' => $month])
            ->with('success', 'Recorded: ' . $payout->period . ' paid, LKR ' . number_format((float) $payout->net_amount, 2) . '.');
    }

    // ---- dealer (read only, paid statements only) -------------------------------------------

    public function dealerIndex()
    {
        $dealer = auth()->user()->dealer;

        return view('dealer.commission-statements', [
            // A dealer login with no dealer record sees nothing, never "everyone".
            'payouts' => $dealer ? $this->statements->paidFor($dealer) : collect(),
        ]);
    }

    public function dealerShow(string $month)
    {
        $dealer = auth()->user()->dealer;
        abort_if($dealer === null || CommissionStatement::period($month) === null, 404);

        $statement = $this->statements->forDealer($dealer, $month);

        // Only a statement that has really been paid. Anything else (a preview, a month not yet paid)
        // is not the dealer's to see, so it looks exactly like a month that does not exist.
        abort_unless($statement['payout'] !== null, 404);

        return view('dealer.commission-statement', ['s' => $statement, 'month' => $month]);
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function month(Request $request): string
    {
        $month = $request->input('month');

        return CommissionStatement::period(is_string($month) ? $month : null) ? $month : CommissionStatement::lastEndedMonth();
    }

    /** The last 12 months, newest first (this month first: it is a live preview until it ends). */
    private function monthChoices(): array
    {
        $out = [];
        $cursor = now(CommissionStatement::TZ)->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $out[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->subMonth();
        }

        return $out;
    }

    private function csv(array $s): StreamedResponse
    {
        $name = 'commission-' . $s['dealer']->id . '-' . $s['period']['month'] . '.csv';

        return response()->streamDownload(function () use ($s) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Dealer', RenewalsReport::csvSafe($s['dealer']->full_name)]);
            fputcsv($out, ['Period', $s['period']['label']]);
            fputcsv($out, ['Status', $s['status']]);
            if ($s['payout']) {
                fputcsv($out, ['Paid on', $s['payout']->paid_on->format('Y-m-d')]);
                fputcsv($out, ['Reference', RenewalsReport::csvSafe($s['payout']->reference)]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Type', 'IMEI', 'Customer', 'Vehicle', 'Recorded on', 'Subscription start', 'Payable from', 'Amount (LKR)']);

            foreach ($s['lines'] as $l) {
                fputcsv($out, [
                    $l['kind'] === 'sale' ? 'Sale' : 'Clawback',
                    RenewalsReport::csvSafe($l['imei']),
                    RenewalsReport::csvSafe($l['customer_name']),
                    RenewalsReport::csvSafe($l['vehicle_number']),
                    $l['sold_on']->copy()->setTimezone(CommissionStatement::TZ)->format('Y-m-d'),
                    $l['sub_start'] ? $l['sub_start']->copy()->setTimezone(CommissionStatement::TZ)->format('Y-m-d') : '',
                    $l['payable_from'] ? $l['payable_from']->copy()->setTimezone(CommissionStatement::TZ)->format('Y-m-d') : '',
                    number_format($l['amount_cents'] / 100, 2, '.', ''),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Gross sales', '', '', '', '', '', '', number_format($s['gross_cents'] / 100, 2, '.', '')]);
            fputcsv($out, ['Clawbacks', '', '', '', '', '', '', number_format($s['clawback_cents'] / 100, 2, '.', '')]);
            fputcsv($out, ['Net', '', '', '', '', '', '', number_format($s['net_cents'] / 100, 2, '.', '')]);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}