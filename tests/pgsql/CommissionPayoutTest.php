<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerCommissionPayout;
use App\Services\CommissionStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Monthly dealer commission payouts (money going out).
 *   GET  admin.commission-payouts            list for a month
 *   POST admin.commission-payouts.pay        record a month as paid for one dealer
 *   GET  dealer.commission-statement         a dealer's own PAID statement
 *
 * Commission lines are inserted directly into package_payments / commission_entries (the same rows a paid
 * activation writes), so these tests do not depend on the activation flow. Every row is rolled back by
 * DatabaseTransactions; assertions look only at the tagged dealers created here.
 */
class CommissionPayoutTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    /** One admin per test (a second admin mid-test would log the session out, see PaidActivationTest). */
    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function makeDealer(string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Payout ' . $suffix,
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+payout-{$suffix}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    /** The last month that has fully ended, in Sri Lanka time ('YYYY-MM'). */
    private function endedMonth(): string
    {
        return CommissionStatement::lastEndedMonth();
    }

    /** The month we are in right now: not finished, so it can never be paid. */
    private function currentMonth(): string
    {
        return now(CommissionStatement::TZ)->format('Y-m');
    }

    /**
     * One commission line for a dealer: a package_payments row plus the commission_entries row that points at it.
     * $amount is positive for "earned" and negative for "clawback". Returns the commission_entries id.
     */
    private function seedLine(Dealer $dealer, string $month, float $amount, string $imei, string $type = 'earned'): int
    {
        $occurred = Carbon::createFromFormat('Y-m-d H:i:s', $month . '-10 12:00:00', CommissionStatement::TZ)->utc();

        $paymentId = DB::table('package_payments')->insertGetId([
            'imei_number'        => $imei,
            'source'             => 'activation',
            'package_model'      => '1 Year',
            'package_label'      => '1 Year',
            'months'             => 12,
            'customer_price'     => 2999,
            'retailer_margin'    => 500,
            'retailer_dealer_id' => $dealer->id,
            'status'             => 'valid',
            'created_at'         => $occurred,
            'updated_at'         => $occurred,
        ]);

        return (int) DB::table('commission_entries')->insertGetId([
            'payment_id'   => $paymentId,
            'dealer_id'    => $dealer->id,
            'role'         => 'retailer',
            'entry_type'   => $type,
            'amount'       => $amount,
            'period_month' => $month,
            'occurred_at'  => $occurred,
            'created_at'   => $occurred,
        ]);
    }


    /** Mark a month as already paid for a dealer, directly in the database (no admin request needed). */
    private function seedPaidPayout(Dealer $dealer, string $month, int $entryId, float $net): int
    {
        $payoutId = (int) DB::table('dealer_commission_payouts')->insertGetId([
            'dealer_id'          => $dealer->id,
            'period'             => $month,
            'period_end'         => CommissionStatement::period($month)['endExclusive'],
            'payable_after_days' => CommissionStatement::PAYABLE_AFTER_DAYS,
            'gross_amount'       => $net,
            'clawback_amount'    => 0,
            'net_amount'         => $net,
            'line_count'         => 1,
            'reference'          => 'AUTOTEST-SEEDED',
            'paid_on'            => $this->today(),
            'paid_by_admin_id'   => 'autotest',
            'paid_by_name'       => 'Autotest',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('commission_entries')->where('id', $entryId)->update(['payout_id' => $payoutId]);

        return $payoutId;
    }

    private function today(): string
    {
        return now(CommissionStatement::TZ)->toDateString();
    }

    private function pay(Dealer $dealer, string $month, array $overrides = [])
    {
        return $this->actingAs($this->admin())->post(
            route('admin.commission-payouts.pay', $dealer),
            array_merge(['month' => $month, 'reference' => 'AUTOTEST-REF-1', 'paid_on' => $this->today()], $overrides)
        );
    }

    private function payoutsFor(Dealer $dealer, string $month)
    {
        return DealerCommissionPayout::where('dealer_id', $dealer->id)->where('period', $month);
    }

    public function test_admin_pays_a_finished_month_and_the_lines_are_claimed(): void
    {
        $dealer = $this->makeDealer('a');
        $month  = $this->endedMonth();
        $e1 = $this->seedLine($dealer, $month, 500, '99900000101');
        $e2 = $this->seedLine($dealer, $month, 300, '99900000102');

        $this->pay($dealer, $month)->assertSessionHasNoErrors()->assertSessionHas('success');

        $payout = $this->payoutsFor($dealer, $month)->first();
        $this->assertNotNull($payout, 'payout row was not created');
        $this->assertEquals(800.00, (float) $payout->net_amount);
        $this->assertEquals(800.00, (float) $payout->gross_amount);
        $this->assertSame(2, (int) $payout->line_count);
        $this->assertSame('AUTOTEST-REF-1', $payout->reference);
        $this->assertSame($this->admin()->admin_id, $payout->paid_by_admin_id);

        // Both lines now point at this payout, which is what stops them being paid again.
        $this->assertSame(2, DB::table('commission_entries')->whereIn('id', [$e1, $e2])->where('payout_id', $payout->id)->count());
    }

    public function test_the_same_month_cannot_be_paid_twice(): void
    {
        $dealer = $this->makeDealer('b');
        $month  = $this->endedMonth();
        $this->seedLine($dealer, $month, 500, '99900000111');

        $this->pay($dealer, $month)->assertSessionHasNoErrors();
        $this->pay($dealer, $month, ['reference' => 'AUTOTEST-REF-2'])->assertSessionHasErrors('payout');

        $this->assertSame(1, $this->payoutsFor($dealer, $month)->count());
        $this->assertSame('AUTOTEST-REF-1', $this->payoutsFor($dealer, $month)->first()->reference);
    }

    public function test_a_month_that_has_not_ended_cannot_be_paid(): void
    {
        $dealer = $this->makeDealer('c');
        $month  = $this->currentMonth();
        $entry  = $this->seedLine($dealer, $month, 500, '99900000121');

        $this->pay($dealer, $month)->assertSessionHasErrors('payout');

        $this->assertSame(0, $this->payoutsFor($dealer, $month)->count());
        $this->assertNull(DB::table('commission_entries')->where('id', $entry)->value('payout_id'), 'line must stay unclaimed');
    }

    public function test_a_dealer_with_nothing_payable_is_refused(): void
    {
        $dealer = $this->makeDealer('d');
        $month  = $this->endedMonth();

        $this->pay($dealer, $month)->assertSessionHasErrors('payout');

        $this->assertSame(0, $this->payoutsFor($dealer, $month)->count());
    }

    public function test_a_net_balance_of_zero_is_not_paid_and_the_lines_stay_unpaid(): void
    {
        $dealer = $this->makeDealer('e');
        $month  = $this->endedMonth();
        $earned   = $this->seedLine($dealer, $month, 500, '99900000131');
        $clawback = $this->seedLine($dealer, $month, -500, '99900000131', 'clawback');

        $this->pay($dealer, $month)->assertSessionHasErrors('payout');

        $this->assertSame(0, $this->payoutsFor($dealer, $month)->count());
        $this->assertSame(0, DB::table('commission_entries')->whereIn('id', [$earned, $clawback])->whereNotNull('payout_id')->count());
    }

    public function test_reference_and_a_valid_paid_date_are_required(): void
    {
        $dealer = $this->makeDealer('f');
        $month  = $this->endedMonth();
        $this->seedLine($dealer, $month, 500, '99900000141');

        $this->pay($dealer, $month, ['reference' => ''])->assertSessionHasErrors('reference');

        $tomorrow = now(CommissionStatement::TZ)->addDay()->toDateString();
        $this->pay($dealer, $month, ['paid_on' => $tomorrow])->assertSessionHasErrors('paid_on');

        $this->pay($dealer, $month, ['paid_on' => ''])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, $this->payoutsFor($dealer, $month)->count());
    }

    public function test_a_dealer_login_cannot_pay(): void
    {
        $this->assertLoginCannotPay('DEALER', 'k');
    }

    public function test_a_finance_login_cannot_pay(): void
    {
        $this->assertLoginCannotPay('FINANCE', 'g');
    }

    private function assertLoginCannotPay(string $role, string $suffix): void
    {
        $dealer = $this->makeDealer($suffix);
        $month  = $this->endedMonth();
        $this->seedLine($dealer, $month, 500, '99900000151');

        $login = $this->makeAdmin($role, $role === 'DEALER' ? ['dealer_id' => $dealer->id] : []);

        $status = $this->actingAs($login)
            ->post(route('admin.commission-payouts.pay', $dealer), [
                'month' => $month, 'reference' => 'AUTOTEST-HACK', 'paid_on' => $this->today(),
            ])->getStatusCode();

        $this->assertContains($status, [302, 403], "{$role} must not be able to pay");
        $this->assertSame(0, $this->payoutsFor($dealer, $month)->count());
    }

    public function test_a_dealer_opens_their_own_paid_statement(): void
    {
        $dealer = $this->makeDealer('h');
        $month  = $this->endedMonth();
        $entry  = $this->seedLine($dealer, $month, 500, '99900000161');
        $this->seedPaidPayout($dealer, $month, $entry, 500);

        $login = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);

        $this->actingAs($login)->get(route('dealer.commission-statement', $month))->assertOk();
    }

    public function test_a_dealer_never_sees_an_unpaid_month_or_a_bad_month(): void
    {
        $dealer = $this->makeDealer('i');
        $month  = $this->endedMonth();
        $this->seedLine($dealer, $month, 700, '99900000162'); // earned, but never paid out

        $login = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);

        // An unpaid month looks exactly like a month that does not exist (no previews for dealers).
        $this->actingAs($login)->get(route('dealer.commission-statement', $month))->assertNotFound();

        // Not a real YYYY-MM.
        $this->actingAs($login)->get(route('dealer.commission-statement', '2026-13'))->assertNotFound();
    }

    public function test_a_dealer_cannot_see_another_dealers_paid_statement(): void
    {
        $paidDealer  = $this->makeDealer('l');
        $otherDealer = $this->makeDealer('m');
        $month       = $this->endedMonth();

        $entry = $this->seedLine($paidDealer, $month, 500, '99900000163');
        $this->seedPaidPayout($paidDealer, $month, $entry, 500);

        // The other dealer has nothing paid for that month, so the same URL is "not found" for them.
        $login = $this->makeAdmin('DEALER', ['dealer_id' => $otherDealer->id]);

        $this->actingAs($login)->get(route('dealer.commission-statement', $month))->assertNotFound();
    }

    public function test_a_dealer_cannot_open_the_admin_payout_pages(): void
    {
        $dealer = $this->makeDealer('j');
        $login  = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);

        $status = $this->actingAs($login)->get(route('admin.commission-payouts'))->getStatusCode();

        $this->assertContains($status, [302, 403]);
    }
}