<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\Dealer;
use App\Models\DealerCommissionLedger;
use App\Models\DealerCommissionRate;
use App\Models\PackagePayment;
use App\Models\PasswordResetRequest;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Stock;
use App\Services\AddressResolver;
use App\Services\AuthAudit;
use App\Services\Billing\PortalScope;
use App\Services\DealerCommissionService;
use App\Services\DeviceRegistrationService;
use App\Services\DeviceWarranty;
use App\Services\PackagePayments;
use App\Services\StaleDeviceService;
use App\Services\TemporaryPassword;
use App\Support\PasswordPolicy;
use App\Support\ReauthGuard;
use Carbon\Carbon;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE BUSINESS SERVICES BEHIND THE SCREENS, called directly on real PostgreSQL (autotest schema):
 *
 *   PortalScope              which buyers a dealer / distributor login may read (anything else is refused)
 *   PackagePayments          records a package payment, freezes its prices, pays the retailer and distributor
 *                            margins, and undoes it with negative lines (never deleting)
 *   DealerCommissionService  the older per-device commission ledger (rate order, reversal, totals)
 *   DeviceRegistrationService one device in: stock down by one, SIM used up, all-or-nothing
 *   TemporaryPassword        an administrator sets someone's temporary password
 *   ReauthGuard              "type your password again" with a 5-tries-per-10-minutes limit
 *   AuthAudit                the sign-in / sign-out / lock-out / failed-sign-in trail
 *   DeviceWarranty           warranty expiry from the ORIGINAL activation date
 *   AddressResolver          coordinates to an address (Google, then OpenStreetMap, then plain numbers)
 *   StaleDeviceService       how many tracked devices sent no GPS point in 24 hours
 *
 * Everything made here is rolled back after each test; the outside world is faked.
 */
class ServicesPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shalotrack_api.base_url' => 'https://api.example.test', 'services.shalotrack_api.sync_key' => 'autotest-key']);
        Cache::forget(StaleDeviceService::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(StaleDeviceService::CACHE_KEY);
        parent::tearDown();
    }

    // ================================================================== PortalScope

    private function distributorWithDealers(): array
    {
        $distributor = $this->makeDealer(['dealer_status' => 'distributor']);
        $under = $this->makeDealer(['distributor_id' => $distributor->id]);
        $other = $this->makeDealer();
        $login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]);

        return compact('distributor', 'under', 'other', 'login');
    }

    public function test_a_dealer_may_read_only_their_own_account(): void
    {
        $dealer = $this->makeDealer();
        $other = $this->makeDealer();

        $scope = PortalScope::forUser($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->assertSame($dealer->id, $scope->ownId);
        $this->assertSame('DEALER', $scope->role);
        $this->assertSame([$dealer->id], $scope->viewable());
        $this->assertTrue($scope->mayView($dealer->id));
        $this->assertFalse($scope->mayView($other->id));
    }

    public function test_a_distributor_may_read_their_own_account_and_their_dealers_only(): void
    {
        ['distributor' => $d, 'under' => $under, 'other' => $other, 'login' => $login] = $this->distributorWithDealers();

        $scope = PortalScope::forUser($login);

        $this->assertSame('DISTRIBUTOR', $scope->role);
        $this->assertEqualsCanonicalizing([$d->id, $under->id], $scope->viewable());
        $this->assertTrue($scope->mayView($under->id));
        $this->assertFalse($scope->mayView($other->id), 'a direct dealer or another distributor\'s dealer is not visible');
    }

    public function test_a_distributor_does_not_see_another_distributors_dealers(): void
    {
        ['login' => $login] = $this->distributorWithDealers();
        $rival = $this->makeDealer(['dealer_status' => 'distributor']);
        $rivalDealer = $this->makeDealer(['distributor_id' => $rival->id]);

        $scope = PortalScope::forUser($login);

        $this->assertFalse($scope->mayView($rival->id));
        $this->assertFalse($scope->mayView($rivalDealer->id));
    }

    private function assertNoScope(?\App\Models\Admin $user): void
    {
        try {
            PortalScope::forUser($user);
            $this->fail('expected 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('This login is not linked to a dealer account.', $e->getMessage());
        }
    }

    public function test_a_login_not_linked_to_a_dealer_account_is_refused(): void
    {
        $this->assertNoScope(null);
        $this->assertNoScope($this->makeAdmin('DEALER', ['dealer_id' => null]));
        $this->assertNoScope($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => null]));
    }

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['ADMIN', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_are_refused_even_when_a_dealer_is_attached(string $role): void
    {
        $this->assertNoScope($this->makeAdmin($role, ['dealer_id' => $this->makeDealer()->id]));
    }

    public function test_a_distributor_login_pointing_at_a_plain_or_inactive_dealer_is_refused(): void
    {
        $this->assertNoScope($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $this->makeDealer(['dealer_status' => 'retailer'])->id]));
        $this->assertNoScope($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $this->makeDealer(['dealer_status' => 'distributor', 'status' => 'inactive'])->id]));
    }

    // ================================================================== PackagePayments

    private function packages(): void
    {
        RenewalPackage::updateOrCreate(['model' => '1 Year'], ['code' => 'ONE_YEAR', 'label' => '1 Year', 'months' => 12, 'customer_price' => 2999,
            'company_margin' => 2199, 'distributor_margin' => 300, 'retailer_margin' => 500, 'warranty_months' => 12, 'is_active' => true]);
        RenewalPackage::updateOrCreate(['model' => 'Free Margin'], ['code' => 'FREE_MARGIN', 'label' => 'Free Margin', 'months' => 6, 'customer_price' => 1000,
            'company_margin' => 1000, 'distributor_margin' => 0, 'retailer_margin' => 0, 'warranty_months' => 0, 'is_active' => true]);
        RenewalPackage::updateOrCreate(['model' => 'No Price'], ['code' => 'NO_PRICE', 'label' => 'No Price', 'months' => 3, 'customer_price' => null,
            'company_margin' => null, 'distributor_margin' => null, 'retailer_margin' => null, 'warranty_months' => 0, 'is_active' => true]);
    }

    private function paidDevice(array $o = [], ?Dealer $seller = null): ActivatedDevice
    {
        return $this->makeBound(array_merge([
            'payment_status' => 'Paid', 'subscription_model' => '1 Year', 'subscription_start_date' => now()->startOfDay(),
            'subscription_end_date' => now()->addYear(), 'bank_invoice' => 'INV-' . $this->nextIntakeNumber(),
        ], $o), $seller);
    }

    private function earned(PackagePayment $p)
    {
        return CommissionEntry::where('payment_id', $p->id)->orderBy('id')->get();
    }

    public function test_a_payment_is_recorded_with_frozen_prices_and_pays_the_retailer_and_distributor(): void
    {
        $this->packages();
        $distributor = $this->makeDealer(['dealer_status' => 'distributor']);
        $retailer = $this->makeDealer(['distributor_id' => $distributor->id]);
        $device = $this->paidDevice([], $retailer);
        $admin = $this->makeAdmin('ADMIN', ['full_name' => 'Autotest Recorder']);
        $this->actingAs($admin);

        $p = app(PackagePayments::class)->record($device, PackagePayments::SOURCE_ACTIVATION);

        $this->assertSame('valid', $p->status);
        $this->assertSame($device->imei_number, $p->imei_number);
        $this->assertSame('1 Year', $p->package_model);
        $this->assertSame('1 Year', $p->package_label);
        $this->assertSame(12, (int) $p->months);
        $this->assertEquals([2999, 2199, 300, 500], [$p->customer_price, $p->company_margin, $p->distributor_margin, $p->retailer_margin]);
        $this->assertSame($retailer->id, $p->retailer_dealer_id);
        $this->assertSame($distributor->id, $p->distributor_dealer_id);
        $this->assertSame($admin->admin_id, $p->recorded_by);
        $this->assertSame('Autotest Recorder', $p->recorded_by_name);
        $this->assertSame('activation', $p->source);

        $lines = $this->earned($p)->keyBy('role');
        $this->assertCount(2, $lines);
        $this->assertSame($retailer->id, $lines['retailer']->dealer_id);
        $this->assertEquals(500, $lines['retailer']->amount);
        $this->assertSame($distributor->id, $lines['distributor']->dealer_id);
        $this->assertEquals(300, $lines['distributor']->amount);
        $this->assertSame('earned', $lines['retailer']->entry_type);
    }

    public function test_a_dealer_with_no_distributor_earns_only_the_retailer_margin(): void
    {
        $this->packages();
        $retailer = $this->makeDealer();

        $p = app(PackagePayments::class)->record($this->paidDevice([], $retailer), 'activation');

        $this->assertNull($p->distributor_dealer_id);
        $this->assertSame(['retailer'], $this->earned($p)->pluck('role')->all());
    }

    public function test_a_distributor_link_to_a_dealer_that_is_not_a_distributor_earns_nothing(): void
    {
        $this->packages();
        $notADistributor = $this->makeDealer(['dealer_status' => 'retailer']);
        $retailer = $this->makeDealer(['distributor_id' => $notADistributor->id]);

        $p = app(PackagePayments::class)->record($this->paidDevice([], $retailer), 'activation');

        $this->assertNull($p->distributor_dealer_id);
        $this->assertSame(['retailer'], $this->earned($p)->pluck('role')->all());
    }

    public static function notRecordable(): array
    {
        return ['not paid' => [['payment_status' => 'not-Paid']], 'no package' => [['subscription_model' => null]], 'no start date' => [['subscription_start_date' => null]]];
    }

    #[DataProvider('notRecordable')]
    public function test_a_device_that_is_not_really_paid_records_nothing(array $override): void
    {
        $this->packages();
        $before = PackagePayment::count();

        $this->assertNull(app(PackagePayments::class)->record($this->paidDevice($override, $this->makeDealer()), 'activation'));
        $this->assertSame($before, PackagePayment::count());
    }

    public function test_recording_the_same_payment_twice_returns_the_first_one_and_pays_once(): void
    {
        $this->packages();
        $device = $this->paidDevice([], $this->makeDealer());
        $service = app(PackagePayments::class);

        $first = $service->record($device, 'activation');
        $second = $service->record($device->fresh(), 'admin_edit');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('activation', $second->source, 'the original source is kept');
        $this->assertCount(1, $this->earned($first));
        $this->assertSame($first->id, $service->current($device)->id);
    }

    public function test_a_different_invoice_or_start_date_or_package_is_a_new_payment(): void
    {
        $this->packages();
        $service = app(PackagePayments::class);
        $device = $this->paidDevice([], $this->makeDealer());
        $first = $service->record($device, 'activation');

        $device->update(['bank_invoice' => 'INV-OTHER']);
        $second = $service->record($device->fresh(), 'admin_edit');
        $device->update(['subscription_start_date' => now()->addDays(40)->startOfDay()]);
        $third = $service->record($device->fresh(), 'reactivation');
        $device->update(['subscription_model' => 'Free Margin']);
        $fourth = $service->record($device->fresh(), 'admin_edit');

        $this->assertCount(4, array_unique([$first->id, $second->id, $third->id, $fourth->id]));
    }

    public function test_current_is_empty_before_anything_is_recorded(): void
    {
        $this->packages();

        $this->assertNull(app(PackagePayments::class)->current($this->paidDevice()));
    }

    public function test_the_prices_stay_frozen_when_the_price_master_changes_later(): void
    {
        $this->packages();
        $p = app(PackagePayments::class)->record($this->paidDevice([], $this->makeDealer()), 'activation');

        RenewalPackage::where('model', '1 Year')->update(['customer_price' => 9999, 'retailer_margin' => 999]);

        $this->assertEquals(2999, $p->fresh()->customer_price);
        $this->assertEquals(500, $p->fresh()->retailer_margin);
        $this->assertEquals(500, $this->earned($p)->first()->amount);
    }

    public function test_a_package_with_no_price_is_recorded_as_proof_but_earns_nothing(): void
    {
        $this->packages();

        $p = app(PackagePayments::class)->record($this->paidDevice(['subscription_model' => 'No Price'], $this->makeDealer()), 'activation');

        $this->assertNotNull($p);
        $this->assertNull($p->customer_price);
        $this->assertCount(0, $this->earned($p));
    }

    public function test_an_unknown_package_name_is_recorded_without_prices_or_commission(): void
    {
        $this->packages();

        $p = app(PackagePayments::class)->record($this->paidDevice(['subscription_model' => 'Mystery Plan'], $this->makeDealer()), 'activation');

        $this->assertNotNull($p);
        $this->assertNull($p->package_label);
        $this->assertNull($p->customer_price);
        $this->assertCount(0, $this->earned($p));
    }

    public function test_a_zero_margin_earns_no_line(): void
    {
        $this->packages();
        $distributor = $this->makeDealer(['dealer_status' => 'distributor']);

        $p = app(PackagePayments::class)->record($this->paidDevice(['subscription_model' => 'Free Margin'], $this->makeDealer(['distributor_id' => $distributor->id])), 'activation');

        $this->assertCount(0, $this->earned($p));
    }

    public function test_a_device_with_no_selling_dealer_is_recorded_but_earns_nothing(): void
    {
        $this->packages();

        $p = app(PackagePayments::class)->record($this->paidDevice(), 'activation');

        $this->assertNotNull($p);
        $this->assertNull($p->retailer_dealer_id);
        $this->assertCount(0, $this->earned($p));
    }

    public function test_the_commission_month_is_the_sri_lanka_month(): void
    {
        $this->packages();
        Carbon::setTestNow('2026-10-31 20:00:00');   // 01:30 on 1 November in Colombo
        $p = app(PackagePayments::class)->record($this->paidDevice([], $this->makeDealer()), 'activation');

        $this->assertSame('2026-11', $this->earned($p)->first()->period_month);
    }

    public function test_undoing_a_payment_marks_it_reversed_and_adds_negative_lines_without_deleting(): void
    {
        $this->packages();
        $distributor = $this->makeDealer(['dealer_status' => 'distributor']);
        $device = $this->paidDevice([], $this->makeDealer(['distributor_id' => $distributor->id]));
        $service = app(PackagePayments::class);
        $p = $service->record($device, 'activation');
        $this->actingAs($admin = $this->makeAdmin('ADMIN'));

        $service->reverse($p, 'Customer refunded');

        $p = $p->fresh();
        $this->assertSame('reversed', $p->status);
        $this->assertNull($p->event_key, 'the key is freed so the same payment can be recorded again');
        $this->assertNotNull($p->reversed_at);
        $this->assertSame($admin->admin_id, $p->reversed_by);
        $this->assertSame('Customer refunded', $p->reversal_reason);
        $lines = $this->earned($p);
        $this->assertCount(4, $lines, 'two earned lines stay, two clawbacks are added');
        $this->assertEquals(0.0, (float) $lines->sum('amount'));
        $this->assertEqualsCanonicalizing([-500.0, -300.0], $lines->where('entry_type', 'clawback')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame('Customer refunded', $lines->where('entry_type', 'clawback')->first()->reason);
    }

    public function test_undoing_twice_does_not_claw_back_twice(): void
    {
        $this->packages();
        $service = app(PackagePayments::class);
        $p = $service->record($this->paidDevice([], $this->makeDealer()), 'activation');

        $service->reverse($p, 'first');
        $service->reverse($p->fresh(), 'second');

        $this->assertCount(2, $this->earned($p));
        $this->assertSame('first', $p->fresh()->reversal_reason);
    }

    public function test_a_long_reversal_reason_is_cut_to_255_characters(): void
    {
        $this->packages();
        $service = app(PackagePayments::class);
        $p = $service->record($this->paidDevice([], $this->makeDealer()), 'activation');

        $service->reverse($p, str_repeat('é', 400));

        $this->assertSame(255, mb_strlen($p->fresh()->reversal_reason));
        $this->assertSame(255, mb_strlen($this->earned($p)->last()->reason));
    }

    public function test_after_an_undo_the_same_payment_can_be_recorded_again(): void
    {
        $this->packages();
        $device = $this->paidDevice([], $this->makeDealer());
        $service = app(PackagePayments::class);
        $first = $service->record($device, 'activation');
        $service->reverse($first, 'mistake');

        $again = $service->record($device->fresh(), 'admin_edit');

        $this->assertNotSame($first->id, $again->id);
        $this->assertSame('valid', $again->status);
        $this->assertEquals(500.0, (float) $this->earned($again)->first()->amount);
    }

    public function test_a_payment_with_no_commission_can_still_be_undone(): void
    {
        $this->packages();
        $service = app(PackagePayments::class);
        $p = $service->record($this->paidDevice(), 'activation');

        $service->reverse($p, 'no commission');

        $this->assertSame('reversed', $p->fresh()->status);
        $this->assertCount(0, $this->earned($p));
    }

    public function test_the_known_sources_are_stable_words(): void
    {
        $this->assertSame('distributor', PackagePayments::SOURCE_DISTRIBUTOR);
        $this->assertSame(['activation', 'admin_edit', 'reactivation', 'distributor_renewal'], [PackagePayments::SOURCE_ACTIVATION, PackagePayments::SOURCE_EDIT, PackagePayments::SOURCE_REACTIVATION, PackagePayments::SOURCE_DISTRIBUTOR_RENEWAL]);
    }

    public function test_the_payment_identity_depends_on_device_vehicle_package_start_day_and_invoice(): void
    {
        $this->packages();
        $d = $this->paidDevice(['bank_invoice' => ' INV-9 ', 'subscription_start_date' => '2026-05-05 08:30:00']);
        $same = $d->replicate()->forceFill(['bank_invoice' => 'INV-9', 'subscription_start_date' => '2026-05-05 23:59:00', 'imei_number' => $d->imei_number, 'vehicle_id' => $d->vehicle_id]);

        $this->assertSame(PackagePayments::eventKey($d), PackagePayments::eventKey($same), 'spaces and the time of day do not matter');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', PackagePayments::eventKey($d));
        foreach (['imei_number' => '123', 'vehicle_id' => 'other', 'subscription_model' => 'Free Margin', 'bank_invoice' => 'INV-10', 'subscription_start_date' => '2026-05-06'] as $field => $value) {
            $this->assertNotSame(PackagePayments::eventKey($d), PackagePayments::eventKey($same->forceFill([$field => $value])), $field);
            $same->forceFill([$field => $d->$field]);
        }
    }

    // ================================================================== DealerCommissionService

    private function rate(?string $status, ?int $typeId, float $rate): void
    {
        DealerCommissionRate::query()->where('dealer_status', $status)->where('device_type_id', $typeId)->delete();
        DealerCommissionRate::create(['dealer_status' => $status, 'device_type_id' => $typeId, 'rate' => $rate]);
    }

    public function test_the_rate_is_the_most_specific_one_that_exists(): void
    {
        DealerCommissionRate::query()->delete();
        $type = $this->makeDeviceType()->id;
        $service = new DealerCommissionService();

        $this->assertSame(['rate' => 1000.0, 'source' => 'unconfigured_fallback'], $service->resolveRate('retailer', $type));

        $this->rate(null, null, 100);
        $this->assertSame(['rate' => 100.0, 'source' => 'network_default'], $service->resolveRate('retailer', $type));

        $this->rate(null, $type, 200);
        $this->assertSame(['rate' => 200.0, 'source' => 'device_type_default'], $service->resolveRate('retailer', $type));

        $this->rate('retailer', null, 300);
        $this->assertSame(['rate' => 300.0, 'source' => 'tier_default'], $service->resolveRate('retailer', $type));

        $this->rate('retailer', $type, 400);
        $this->assertSame(['rate' => 400.0, 'source' => 'exact'], $service->resolveRate('retailer', $type));
    }

    public function test_a_rate_for_another_tier_or_type_is_never_used(): void
    {
        DealerCommissionRate::query()->delete();
        $type = $this->makeDeviceType()->id;
        $otherType = $this->makeDeviceType()->id;
        $this->rate('distributor', $type, 777);
        $this->rate('retailer', $otherType, 888);
        $this->rate(null, $otherType, 999);

        $r = (new DealerCommissionService())->resolveRate('retailer', $type);

        $this->assertSame(['rate' => 1000.0, 'source' => 'unconfigured_fallback'], $r);
    }

    public function test_a_dealer_with_no_tier_uses_the_type_or_network_default(): void
    {
        DealerCommissionRate::query()->delete();
        $type = $this->makeDeviceType()->id;
        $this->rate(null, null, 100);
        $this->rate('retailer', null, 300);

        $this->assertSame('network_default', (new DealerCommissionService())->resolveRate(null, $type)['source'] ?? null);
        $this->assertSame(100.0, (new DealerCommissionService())->resolveRate(null, $type)['rate']);
    }

    public function test_the_old_flat_sale_commission_is_switched_off_and_records_nothing(): void
    {
        $this->assertFalse(DealerCommissionService::FIXED_SALE_COMMISSION_ENABLED);
        $dealer = $this->makeDealer();
        $device = SetupShalotrackDevice::create(['device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(), 'sim_number' => '0700000001', 'status' => 'Activated', 'dealer_id' => $dealer->id]);
        $before = DealerCommissionLedger::count();

        $this->assertNull((new DealerCommissionService())->recordEarned($device, $dealer, 'sold'));
        $this->assertSame($before, DealerCommissionLedger::count());
    }

    private function earnedLedger(Dealer $dealer, SetupShalotrackDevice $device, float $amount = 1000): DealerCommissionLedger
    {
        return DealerCommissionLedger::create(['dealer_id' => $dealer->id, 'shdevice_id' => $device->shdevice_id, 'device_type_id' => null,
            'dealer_status_at_time' => $dealer->dealer_status, 'rate_applied' => $amount, 'amount' => $amount, 'entry_type' => 'earned', 'reason' => 'sold']);
    }

    private function ledgerDevice(Dealer $dealer): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create(['device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(), 'sim_number' => '07' . str_pad((string) $this->nextIntakeNumber(), 9, '0', STR_PAD_LEFT), 'status' => 'Activated', 'dealer_id' => $dealer->id]);
    }

    public function test_reversing_closes_the_active_entry_and_adds_a_negative_one(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->ledgerDevice($dealer);
        $earned = $this->earnedLedger($dealer, $device, 1000);
        $service = new DealerCommissionService();

        $reversal = $service->recordReversed($device, 'device broken');

        $this->assertNotNull($earned->fresh()->reversed_at);
        $this->assertSame('reversed', $reversal->entry_type);
        $this->assertEquals(-1000, $reversal->amount);
        $this->assertSame('device broken', $reversal->reason);
        $this->assertSame($dealer->id, $reversal->dealer_id);
        $this->assertEquals(0.0, $service->currentTotal($dealer));
    }

    public function test_reversing_with_nothing_to_reverse_is_a_quiet_no_op_and_twice_reverses_once(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->ledgerDevice($dealer);
        $service = new DealerCommissionService();

        $this->assertNull($service->recordReversed($device, 'nothing there'));

        $this->earnedLedger($dealer, $device);
        $this->assertNotNull($service->recordReversed($device, 'first'));
        $this->assertNull($service->recordReversed($device, 'second'));
        $this->assertSame(2, DealerCommissionLedger::where('shdevice_id', $device->shdevice_id)->count());
    }

    public function test_the_total_is_per_dealer_and_adds_earned_and_reversed_lines(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $service = new DealerCommissionService();
        $this->earnedLedger($mine, $this->ledgerDevice($mine), 1000);
        $this->earnedLedger($mine, $this->ledgerDevice($mine), 500);
        $gone = $this->ledgerDevice($mine);
        $this->earnedLedger($mine, $gone, 700);
        $service->recordReversed($gone, 'x');
        $this->earnedLedger($other, $this->ledgerDevice($other), 4000);

        $this->assertEquals(1500.0, $service->currentTotal($mine));
        $this->assertEquals(4000.0, $service->currentTotal($other));
        $this->assertSame(0.0, $service->currentTotal($this->makeDealer()));
    }

    // ================================================================== DeviceRegistrationService

    private function register(int $typeId, string $imei, ?string $iccid = null, string $source = 'manual', ?string $adminId = null)
    {
        return app(DeviceRegistrationService::class)->register($typeId, $imei, $iccid, $source, $adminId);
    }

    private function assertRefusedWith(string $field, string $message, \Closure $call): void
    {
        try {
            $call();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([$field => [$message]], $e->errors());
        }
    }

    public function test_a_device_is_registered_stock_goes_down_and_the_sim_is_used_up(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-REG', 'model' => 'X1']);
        $stock = $this->stockFor($type, 3);
        $sim = $this->makeSim();
        $admin = $this->makeAdmin('ADMIN');
        $imei = $this->validImei();

        $device = $this->register($type->id, $imei, $sim->iccid, 'scan', $admin->admin_id);

        $this->assertSame($imei, $device->imei_number);
        $this->assertSame('AUTOTEST-REG X1', $device->device_category);
        $this->assertSame($sim->sim_number, $device->sim_number);
        $this->assertSame($sim->iccid, $device->iccid, 'the ICCID is kept on the device before the SIM row is deleted');
        $this->assertSame('scan', $device->intake_source);
        $this->assertSame($admin->admin_id, $device->registered_by);
        $this->assertSame('Not Activated', $device->status);
        $this->assertNull($device->dealer_id);
        $this->assertSame(2, $stock->fresh()->company_available_stock);
        $this->assertNull(Sim::where('iccid', $sim->iccid)->first(), 'the SIM leaves the available pool');
    }

    public function test_a_device_can_be_registered_without_a_sim(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 1);

        $device = $this->register($type->id, $this->validImei());

        $this->assertNull($device->sim_number);
        $this->assertNull($device->iccid);
        $this->assertSame('manual', $device->intake_source);
        $this->assertSame(0, $stock->fresh()->company_available_stock);
    }

    public function test_a_wrong_device_type_is_refused(): void
    {
        $this->assertRefusedWith('device_type_id', 'The selected device type is invalid.', fn () => $this->register(999999999, $this->validImei()));
    }

    public function test_an_imei_already_registered_is_refused_and_stock_is_untouched(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $imei = $this->validImei();
        $this->register($type->id, $imei);

        $this->assertRefusedWith('imei', 'This IMEI number is already registered.', fn () => $this->register($type->id, $imei));
        $this->assertSame(4, $stock->fresh()->company_available_stock);
    }

    public function test_sim_rules_are_enforced_and_nothing_changes_on_a_refusal(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);

        $this->assertRefusedWith('iccid', 'This SIM (ICCID) is not registered in the system.', fn () => $this->register($type->id, $this->validImei(), '8994900000000000001'));

        $inactive = $this->makeSim('Not Activated');
        $this->assertRefusedWith('iccid', 'This SIM is not in Activated status.', fn () => $this->register($type->id, $this->validImei(), $inactive->iccid));

        $used = $this->makeSim();
        $this->register($type->id, $this->validImei(), $used->iccid);   // the SIM row is now gone, its ICCID sits on a device
        $this->assertRefusedWith('iccid', 'This SIM (ICCID) is not registered in the system.', fn () => $this->register($type->id, $this->validImei(), $used->iccid));

        $this->assertSame(4, $stock->fresh()->company_available_stock, 'only the one good registration used stock');
    }

    public function test_a_sim_still_attached_to_another_device_is_refused(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 5);
        $sim = $this->makeSim();
        $imei = $this->validImei();
        // A device already holds this ICCID while the SIM row still exists (an old inconsistent state).
        SetupShalotrackDevice::create(['device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(), 'sim_number' => '0700000002', 'iccid' => $sim->iccid, 'status' => 'Activated']);

        $this->assertRefusedWith('iccid', 'This SIM is already attached to another device.', fn () => $this->register($type->id, $imei, $sim->iccid));
        $this->assertNotNull(Sim::where('iccid', $sim->iccid)->first(), 'the SIM is not consumed by a refused registration');
    }

    public function test_no_stock_means_no_registration_and_the_sim_survives(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 0);
        $sim = $this->makeSim();
        $devices = SetupShalotrackDevice::count();

        $this->assertRefusedWith('device_type_id', 'No Company Available Stock left for this Device Category / Type.', fn () => $this->register($type->id, $this->validImei(), $sim->iccid));
        $this->assertSame($devices, SetupShalotrackDevice::count());
        $this->assertNotNull(Sim::where('iccid', $sim->iccid)->first());
    }

    public function test_a_device_type_with_no_stock_row_at_all_is_refused_the_same_way(): void
    {
        $type = $this->makeDeviceType();

        $this->assertRefusedWith('device_type_id', 'No Company Available Stock left for this Device Category / Type.', fn () => $this->register($type->id, $this->validImei()));
    }

    // ================================================================== TemporaryPassword

    private function resetRequest(\App\Models\Admin $for, string $status = PasswordResetRequest::PENDING): PasswordResetRequest
    {
        return PasswordResetRequest::create(['admin_id' => $for->admin_id, 'username' => $for->username, 'status' => $status, 'requested_at' => now()]);
    }

    public function test_a_typed_temporary_password_is_stored_hashed_and_forces_a_change(): void
    {
        $target = $this->makeAdmin('DEALER', ['must_change_password' => false, 'password_changed_at' => now(), 'remember_token' => 'old-token']);
        $by = $this->makeAdmin('ADMIN');
        $oldHash = $target->password;

        $r = TemporaryPassword::set($target, $by, '  Typed#Pass123  ', 'developer directory');

        $target = $target->fresh();
        $this->assertSame(['password' => 'Typed#Pass123', 'generated' => false], $r);
        $this->assertTrue(Hash::check('Typed#Pass123', $target->password));
        $this->assertNotSame($oldHash, $target->password);
        $this->assertStringNotContainsString('Typed#Pass123', $target->password);
        $this->assertTrue((bool) $target->must_change_password);
        $this->assertNull($target->password_changed_at);
        $this->assertNotSame('old-token', $target->remember_token);
    }

    public static function blankTyped(): array
    {
        return ['null' => [null], 'empty' => [''], 'spaces' => ['    ']];
    }

    #[DataProvider('blankTyped')]
    public function test_a_blank_password_generates_a_strong_one(?string $typed): void
    {
        $target = $this->makeAdmin('SUPPLIER');

        $r = TemporaryPassword::set($target, $this->makeAdmin('ADMIN'), $typed, 'forgot-password queue');

        $this->assertTrue($r['generated']);
        $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($r['password']));
        $this->assertMatchesRegularExpression('/[A-Z]/', $r['password']);
        $this->assertMatchesRegularExpression('/[a-z]/', $r['password']);
        $this->assertMatchesRegularExpression('/[0-9]/', $r['password']);
        $this->assertTrue(Hash::check($r['password'], $target->fresh()->password));
    }

    public function test_two_generated_passwords_differ(): void
    {
        $by = $this->makeAdmin('ADMIN');

        $a = TemporaryPassword::set($this->makeAdmin('DEALER'), $by, null, 'x')['password'];
        $b = TemporaryPassword::set($this->makeAdmin('DEALER'), $by, null, 'x')['password'];

        $this->assertNotSame($a, $b);
    }

    public function test_the_waiting_request_for_that_login_is_closed_and_others_are_left_alone(): void
    {
        $target = $this->makeAdmin('DEALER');
        $someoneElse = $this->makeAdmin('DEALER');
        $by = $this->makeAdmin('ADMIN');
        $waiting = $this->resetRequest($target);
        $dismissed = $this->resetRequest($target, PasswordResetRequest::DISMISSED);
        $othersWaiting = $this->resetRequest($someoneElse);

        TemporaryPassword::set($target, $by, null, 'queue');

        $waiting = $waiting->fresh();
        $this->assertSame(PasswordResetRequest::DONE, $waiting->status);
        $this->assertSame($by->admin_id, $waiting->resolved_by);
        $this->assertNotNull($waiting->resolved_at);
        $this->assertSame(PasswordResetRequest::DISMISSED, $dismissed->fresh()->status);
        $this->assertSame(PasswordResetRequest::PENDING, $othersWaiting->fresh()->status);
    }

    public function test_the_pending_count_shown_in_the_sidebar_is_refreshed(): void
    {
        $target = $this->makeAdmin('DEALER');
        $this->resetRequest($target);
        $before = PasswordResetRequest::pendingCount();

        TemporaryPassword::set($target, $this->makeAdmin('ADMIN'), null, 'queue');

        $this->assertSame($before - 1, PasswordResetRequest::pendingCount());
    }

    public function test_the_audit_entry_says_who_did_it_to_whom_and_never_contains_the_password(): void
    {
        $target = $this->makeAdmin('FINANCE');
        $by = $this->makeAdmin('ADMIN');
        $this->actingAs($by);

        $r = TemporaryPassword::set($target, $by, 'Typed#Pass123', 'developer directory', ['request_id' => 12]);

        $row = AuditLog::where('action', 'account.password_reset')->where('subject_id', $target->admin_id)->latest('id')->first();
        $this->assertSame($target->username, $row->subject_label);
        $this->assertSame($by->admin_id, $row->actor_id);
        $this->assertEquals(['request_id' => 12, 'via' => 'developer directory', 'set_by' => $by->username, 'target_role' => 'FINANCE', 'password_generated' => false], $row->meta);
        $this->assertStringNotContainsString($r['password'], json_encode($row->toArray()));
    }

    public function test_a_failure_rolls_everything_back(): void
    {
        $target = $this->makeAdmin('DEALER');
        $oldHash = $target->password;
        Schema::drop('password_reset_requests');   // the update inside the transaction will fail

        try {
            TemporaryPassword::set($target, $this->makeAdmin('ADMIN'), 'Typed#Pass123', 'x');
            $this->fail('expected an error');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $e);
        }

        $this->assertSame($oldHash, DB::table('Admins')->where('admin_id', $target->admin_id)->value('password'));
    }

    // ================================================================== ReauthGuard

    private function reauthRequest(?\App\Models\Admin $user, mixed $typed, string $field = 'current_password'): Request
    {
        $request = Request::create('/x', 'POST', $typed === null ? [] : [$field => $typed]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function confirmFails(Request $request, string $bag = '', string $field = 'current_password'): ValidationException
    {
        try {
            ReauthGuard::confirm($request, $bag, 'testing', $field);
        } catch (ValidationException $e) {
            return $e;
        }
        $this->fail('expected the password check to fail');
    }

    public function test_the_right_password_passes(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        ReauthGuard::confirm($this->reauthRequest($admin, 'Autotest#12345'));

        $this->addToAssertionCount(1);
    }

    public static function wrongInputs(): array
    {
        return ['wrong text' => ['wrong'], 'empty' => [''], 'a list' => [['Autotest#12345']], 'missing' => [null], 'a number' => [12345], 'right one with a space' => ['Autotest#12345 ']];
    }

    #[DataProvider('wrongInputs')]
    public function test_anything_but_the_exact_password_fails_with_the_standard_message(mixed $typed): void
    {
        $admin = $this->makeAdmin('ADMIN');

        $e = $this->confirmFails($this->reauthRequest($admin, $typed));

        $this->assertSame(['current_password' => ['That is not your current password.']], $e->errors());
    }

    public function test_a_wrong_try_is_audited_with_what_it_was_for(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        try {
            ReauthGuard::confirm($this->reauthRequest($admin, 'nope'), '', 'adding an administrator');
        } catch (ValidationException) {
        }

        $row = AuditLog::where('action', 'auth.reauth_failed')->where('subject_id', $admin->admin_id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(['for' => 'adding an administrator'], $row->meta);
        $this->assertStringNotContainsString('nope', json_encode($row->toArray()));
    }

    public function test_a_guest_always_fails(): void
    {
        $e = $this->confirmFails($this->reauthRequest(null, 'anything'));
        $this->assertSame(['current_password' => ['That is not your current password.']], $e->errors());
        RateLimiter::clear('reauth:guest');
    }

    public function test_the_error_goes_to_the_named_field_and_bag(): void
    {
        $e = $this->confirmFails($this->reauthRequest($this->makeAdmin('ADMIN'), 'wrong', 'confirm_with'), 'passwordForm', 'confirm_with');

        $this->assertSame('passwordForm', $e->errorBag);
        $this->assertArrayHasKey('confirm_with', $e->errors());
    }

    public function test_after_five_wrong_tries_even_the_right_password_is_blocked_for_a_while(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        foreach (range(1, 5) as $i) {
            $this->confirmFails($this->reauthRequest($admin, 'wrong'));
        }

        $e = $this->confirmFails($this->reauthRequest($admin, 'Autotest#12345'));

        $this->assertMatchesRegularExpression('/^Too many wrong passwords\. Try again in (10|9|8|7|6|5|4|3|2|1) minute\(s\)\.$/', $e->errors()['current_password'][0]);
    }

    public function test_the_lock_lasts_ten_minutes(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        foreach (range(1, 5) as $i) {
            $this->confirmFails($this->reauthRequest($admin, 'wrong'));
        }

        $left = RateLimiter::availableIn('reauth:' . $admin->admin_id);

        $this->assertGreaterThan(590, $left);
        $this->assertLessThanOrEqual(600, $left);
    }

    public function test_four_wrong_tries_are_still_allowed_and_a_right_one_resets_the_count(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        foreach (range(1, 4) as $i) {
            $this->confirmFails($this->reauthRequest($admin, 'wrong'));
        }
        ReauthGuard::confirm($this->reauthRequest($admin, 'Autotest#12345'));

        foreach (range(1, 4) as $i) {
            $this->confirmFails($this->reauthRequest($admin, 'wrong'));
        }
        ReauthGuard::confirm($this->reauthRequest($admin, 'Autotest#12345'));

        $this->addToAssertionCount(1);
    }

    public function test_one_persons_wrong_tries_do_not_lock_another(): void
    {
        $a = $this->makeAdmin('ADMIN');
        $b = $this->makeAdmin('ADMIN');
        foreach (range(1, 5) as $i) {
            $this->confirmFails($this->reauthRequest($a, 'wrong'));
        }

        ReauthGuard::confirm($this->reauthRequest($b, 'Autotest#12345'));

        $this->addToAssertionCount(1);
    }

    // ================================================================== AuthAudit

    private function fromAddress(string $address, string $path = '/login', string $method = 'POST', array $input = []): Request
    {
        $request = Request::create($path, $method, $input, [], [], ['REMOTE_ADDR' => $address]);
        $this->app->instance('request', $request);

        return $request;
    }

    private function authRows(string $action, ?string $adminId = null)
    {
        return AuditLog::where('action', $action)->when($adminId, fn ($q) => $q->where('actor_id', $adminId)->orWhere('subject_id', $adminId));
    }

    private function uniqueAddress(): string
    {
        return '10.' . random_int(1, 254) . '.' . random_int(0, 254) . '.' . random_int(1, 254);   // plus a unique username in each test, so cached counters never collide
    }

    public function test_the_three_sign_in_events_are_wired_to_the_audit_service(): void
    {
        $this->assertTrue(Event::hasListeners(Login::class));
        $this->assertTrue(Event::hasListeners(Logout::class));
        $this->assertTrue(Event::hasListeners(Lockout::class));
        $this->assertSame([Login::class => 'onLogin', Logout::class => 'onLogout', Lockout::class => 'onLockout'], (new AuthAudit())->subscribe(app('events')));
    }

    public function test_signing_in_through_the_form_is_recorded(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $this->fromAddress($this->uniqueAddress(), '/login', 'POST');

        event(new Login('web', $admin, false));

        $row = $this->authRows('auth.login', $admin->admin_id)->latest('id')->first();
        $this->assertSame($admin->admin_id, $row->actor_id);
        $this->assertSame($admin->username, $row->subject_label);
        $this->assertSame('username and password', $row->meta['how']);
    }

    public function test_a_remember_me_cookie_sign_in_is_recorded_as_such(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $this->fromAddress($this->uniqueAddress(), '/admin/dashboard', 'GET');

        event(new Login('web', $admin, true));

        $this->assertSame('remember-me cookie (new browser session)', $this->authRows('auth.login', $admin->admin_id)->latest('id')->first()->meta['how']);
    }

    public function test_signing_out_is_recorded_as_chosen_unless_a_reason_was_set(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $request = $this->fromAddress($this->uniqueAddress(), '/logout', 'POST');

        event(new Logout('web', $admin));
        $this->assertSame('signed out by the user', $this->authRows('auth.logout', $admin->admin_id)->latest('id')->first()->meta['how']);

        $request->attributes->set('audit.logout_reason', 'forced out: test');
        event(new Logout('web', $admin));
        $this->assertSame('forced out: test', $this->authRows('auth.logout', $admin->admin_id)->latest('id')->first()->meta['how']);

        $request->attributes->set('audit.logout_reason', ['not', 'a string']);
        event(new Logout('web', $admin));
        $this->assertSame('signed out by the user', $this->authRows('auth.logout', $admin->admin_id)->latest('id')->first()->meta['how']);
    }

    public function test_a_sign_out_with_nobody_signed_in_leaves_no_entry(): void
    {
        $before = AuditLog::where('action', 'auth.logout')->count();

        event(new Logout('web', null));

        $this->assertSame($before, AuditLog::where('action', 'auth.logout')->count());
    }

    public function test_a_lockout_for_a_real_account_is_recorded_once_per_five_minutes(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $admin->username]);

        event(new Lockout($request));
        event(new Lockout($request));
        event(new Lockout($request));

        $rows = AuditLog::where('action', 'auth.lockout')->where('subject_id', $admin->admin_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($admin->username, $rows[0]->subject_label);
        $this->assertStringContainsString('too many failed attempts', $rows[0]->meta['why']);
    }

    public function test_a_lockout_for_a_name_that_does_not_exist_never_copies_the_typed_text(): void
    {
        $typed = 'typed-password-' . bin2hex(random_bytes(4));
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $typed]);

        event(new Lockout($request));

        $row = AuditLog::where('action', 'auth.lockout')->latest('id')->first();
        $this->assertSame('(no such account)', $row->subject_label);
        $this->assertStringNotContainsString($typed, json_encode($row->toArray()));
    }

    public function test_a_lockout_with_no_username_or_a_list_instead_of_text_is_handled(): void
    {
        $before = AuditLog::where('action', 'auth.lockout')->where('subject_label', '(no such account)')->count();

        event(new Lockout($this->fromAddress($this->uniqueAddress(), '/login', 'POST', [])));
        event(new Lockout($this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => ['a']])));

        $this->assertGreaterThanOrEqual($before + 1, AuditLog::where('action', 'auth.lockout')->where('subject_label', '(no such account)')->count());
    }

    public function test_a_failed_sign_in_for_a_real_account_names_it(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $admin->username]);

        AuthAudit::failed($request, $admin, 'wrong password');

        $row = AuditLog::where('action', 'auth.login_failed')->where('subject_id', $admin->admin_id)->first();
        $this->assertSame($admin->username, $row->subject_label);
        $this->assertSame('wrong password', $row->meta['why']);
    }

    public function test_a_failed_sign_in_for_an_unknown_name_never_stores_what_was_typed(): void
    {
        $typed = 'hunter2-' . bin2hex(random_bytes(4));
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $typed]);

        AuthAudit::failed($request, null, 'no such account');

        $row = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();
        $this->assertSame('(no such account)', $row->subject_label);
        $this->assertNull($row->subject_id);
        $this->assertStringNotContainsString($typed, json_encode($row->toArray()));
    }

    public function test_the_same_failed_attempt_is_written_once_a_minute(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $admin->username]);

        AuthAudit::failed($request, $admin, 'wrong password');
        AuthAudit::failed($request, $admin, 'wrong password');
        AuthAudit::failed($request, $admin, 'wrong password');

        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->where('subject_id', $admin->admin_id)->count());
    }

    public function test_the_same_name_in_another_letter_case_counts_as_the_same_attempt(): void
    {
        $address = $this->uniqueAddress();
        $name = 'case-' . bin2hex(random_bytes(3));

        AuthAudit::failed($this->fromAddress($address, '/login', 'POST', ['username' => $name]), null, 'x');
        AuthAudit::failed($this->fromAddress($address, '/login', 'POST', ['username' => strtoupper($name)]), null, 'x');

        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->where('occurred_at', '>=', now()->subMinute())->where('meta->why', 'x')->count());
    }

    public function test_one_address_cannot_flood_the_log_and_a_single_marker_row_says_so(): void
    {
        $address = $this->uniqueAddress();
        $before = AuditLog::where('action', 'auth.login_failed')->count();

        foreach (range(1, 40) as $i) {
            AuthAudit::failed($this->fromAddress($address, '/login', 'POST', ['username' => "flood-{$i}-" . bin2hex(random_bytes(3))]), null, 'wrong password');
        }

        $this->assertSame($before + 31, AuditLog::where('action', 'auth.login_failed')->count(), '30 normal rows, then one marker row, then nothing');
        $marker = AuditLog::where('action', 'auth.login_failed')->where('subject_label', '(many accounts)')->latest('id')->first();
        $this->assertStringContainsString('more than 30 failed attempts from this address in an hour', $marker->meta['why']);
    }

    public function test_a_broken_audit_table_never_blocks_a_sign_in_or_out(): void
    {
        $admin = $this->makeAdmin('DEALER');
        $request = $this->fromAddress($this->uniqueAddress(), '/login', 'POST', ['username' => $admin->username]);
        Schema::drop('audit_logs');
        Log::spy();

        event(new Login('web', $admin, false));
        event(new Logout('web', $admin));
        event(new Lockout($request));
        AuthAudit::failed($request, $admin, 'x');

        $this->addToAssertionCount(1);
    }

    // ================================================================== DeviceWarranty

    public function test_no_added_warranty_or_a_missing_date_is_reported_plainly(): void
    {
        foreach ([null, 0, -3] as $months) {
            $this->assertSame(['state' => 'none', 'label' => 'No added warranty', 'date' => null, 'days' => null],
                DeviceWarranty::status((object) ['warranty_months' => $months, 'original_activated_at' => '2026-01-01']));
        }
        $this->assertSame('none', DeviceWarranty::status((object) [])['state']);

        foreach ([null, ''] as $date) {
            $this->assertSame(['state' => 'unknown', 'label' => 'Activation date missing', 'date' => null, 'days' => null],
                DeviceWarranty::status((object) ['warranty_months' => 12, 'original_activated_at' => $date]));
        }
    }

    public function test_a_warranty_is_active_until_the_end_day_and_expired_after_it(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $row = fn (string $activated) => (object) ['warranty_months' => 12, 'original_activated_at' => $activated];

        $active = DeviceWarranty::status($row('2026-01-01 08:00:00'));
        $this->assertSame(['active', 'Active'], [$active['state'], $active['label']]);
        $this->assertSame('2027-01-01', $active['date']->toDateString());
        $this->assertGreaterThan(0, $active['days']);

        $lastDay = DeviceWarranty::status($row('2025-11-12 08:00:00'));
        $this->assertSame('active', $lastDay['state'], 'it still covers the end day itself');
        $this->assertSame(0, $lastDay['days']);

        $ended = DeviceWarranty::status($row('2025-11-11 08:00:00'));
        $this->assertSame(['ended', 'Expired'], [$ended['state'], $ended['label']]);
        $this->assertSame(-1, $ended['days']);
    }

    public static function expiries(): array
    {
        return ['31 Aug + 6 months' => ['2026-08-31', 6, '2027-02-28'], '31 Jan + 1 month' => ['2026-01-31', 1, '2026-02-28'], 'leap year' => ['2027-08-31', 6, '2028-02-29'],
            '30 Nov + 3 months' => ['2026-11-30', 3, '2027-02-28'], 'ordinary' => ['2026-03-15', 12, '2027-03-15'], 'sri lanka day, not UTC' => ['2026-08-31 20:00:00', 6, '2027-03-01']];
    }

    #[DataProvider('expiries')]
    public function test_the_expiry_is_calendar_months_clamped_to_the_month_end_on_the_sri_lanka_day(string $activated, int $months, string $expected): void
    {
        $end = DeviceWarranty::expiry($activated, $months);

        $this->assertSame($expected, $end->toDateString());
        $this->assertSame('00:00:00', $end->format('H:i:s'));
    }

    // ================================================================== AddressResolver

    private function coords(): array
    {
        static $n = 0;
        $n++;

        return [6.0 + $n / 1000, 79.0 + $n / 1000];
    }

    private function googleOk(string $address = '1 Galle Road, Colombo'): array
    {
        return ['status' => 'OK', 'results' => [['formatted_address' => $address]]];
    }

    public function test_the_google_address_is_used_and_remembered(): void
    {
        config(['services.google.maps_api_key' => 'g-key']);
        [$lat, $lng] = $this->coords();
        Http::fake(['maps.googleapis.com/*' => Http::response($this->googleOk(), 200)]);

        $first = (new AddressResolver())->resolve($lat, $lng);
        $second = (new AddressResolver())->resolve($lat, $lng);

        $this->assertSame('1 Galle Road, Colombo', $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'maps.googleapis.com') && $r['latlng'] === "{$lat},{$lng}" && $r['key'] === 'g-key');
    }

    public function test_when_google_has_no_answer_openstreetmap_is_used(): void
    {
        config(['services.google.maps_api_key' => 'g-key']);
        [$lat, $lng] = $this->coords();
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Fort, Colombo, Sri Lanka'], 200),
        ]);

        $this->assertSame('Fort, Colombo, Sri Lanka', (new AddressResolver())->resolve($lat, $lng));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'nominatim') && $r['lat'] == $lat && $r['lon'] == $lng && $r['format'] === 'json' && $r->header('User-Agent') === ['ShaloTrackAdmin/1.0']);
    }

    public function test_a_google_error_status_also_falls_back(): void
    {
        config(['services.google.maps_api_key' => 'g-key']);
        [$lat, $lng] = $this->coords();
        Http::fake([
            'maps.googleapis.com/*' => Http::response('denied', 403),
            'nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Kandy'], 200),
        ]);

        $this->assertSame('Kandy', (new AddressResolver())->resolve($lat, $lng));
    }

    public function test_without_a_google_key_google_is_not_called(): void
    {
        config(['services.google.maps_api_key' => null]);
        [$lat, $lng] = $this->coords();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Galle'], 200)]);

        $this->assertSame('Galle', (new AddressResolver())->resolve($lat, $lng));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'googleapis'));
    }

    public function test_when_nothing_answers_the_plain_numbers_are_returned_and_not_remembered(): void
    {
        config(['services.google.maps_api_key' => null]);
        [$lat, $lng] = $this->coords();
        $online = false;
        Http::fake(function () use (&$online) { return $online ? Http::response(['display_name' => 'Back Online'], 200) : Http::response('down', 500); });

        $this->assertSame("{$lat}, {$lng}", (new AddressResolver())->resolve($lat, $lng));

        $online = true;
        $this->assertSame('Back Online', (new AddressResolver())->resolve($lat, $lng), 'a failure must not be cached');
    }

    public function test_an_empty_openstreetmap_answer_gives_the_plain_numbers(): void
    {
        config(['services.google.maps_api_key' => null]);
        [$lat, $lng] = $this->coords();
        Http::fake(['*' => Http::response(['error' => 'Unable to geocode'], 200)]);

        $this->assertSame("{$lat}, {$lng}", (new AddressResolver())->resolve($lat, $lng));
    }

    public function test_a_network_error_is_logged_and_gives_the_plain_numbers(): void
    {
        config(['services.google.maps_api_key' => 'g-key']);
        [$lat, $lng] = $this->coords();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        Log::spy();

        $this->assertSame("{$lat}, {$lng}", (new AddressResolver())->resolve($lat, $lng));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_starts_with($m, 'Geocoding Exception: '))->once();
    }

    public function test_points_ten_metres_apart_are_different_places(): void
    {
        config(['services.google.maps_api_key' => null]);
        [$lat, $lng] = $this->coords();
        Http::fake(['*' => Http::response(['display_name' => 'Somewhere'], 200)]);

        (new AddressResolver())->resolve($lat, $lng);
        (new AddressResolver())->resolve($lat + 0.0001, $lng);

        Http::assertSentCount(2);
    }

    public function test_points_a_metre_apart_share_one_remembered_address(): void
    {
        config(['services.google.maps_api_key' => null]);
        [$lat, $lng] = $this->coords();
        Http::fake(['*' => Http::response(['display_name' => 'Same Place'], 200)]);

        (new AddressResolver())->resolve($lat, $lng);
        (new AddressResolver())->resolve($lat + 0.000001, $lng - 0.000001);

        Http::assertSentCount(1);
    }

    // ================================================================== StaleDeviceService

    private function gps(string $imei, ?string $eventTime, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response($eventTime === null ? ['currentLocation' => null] : ['currentLocation' => ['eventTime' => $eventTime]], $status);
    }

    public function test_no_devices_means_no_check_and_no_calls(): void
    {
        Http::fake();

        $this->assertSame(['stale' => 0, 'checked' => 0, 'unknown' => 0, 'unavailable' => false], (new StaleDeviceService())->summarize(collect([null, '', null])));
        Http::assertNothingSent();
    }

    public function test_a_device_is_stale_after_24_hours_without_a_point_or_with_no_point_at_all(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00');
        Http::fake(function ($request) {
            return match ($request['imei']) {
                'FRESH'    => Http::response(['currentLocation' => ['eventTime' => '2026-11-12 11:00:00']], 200),
                'EDGE_NEW' => Http::response(['currentLocation' => ['eventTime' => '2026-11-11 12:01:00']], 200),   // 23h59m ago
                'EDGE_OLD' => Http::response(['currentLocation' => ['eventTime' => '2026-11-11 11:59:00']], 200),   // 24h01m ago
                'NO_POINT' => Http::response(['currentLocation' => null], 200),
                default    => Http::response('x', 500),
            };
        });

        $r = (new StaleDeviceService())->summarize(collect(['FRESH', 'EDGE_NEW', 'EDGE_OLD', 'NO_POINT']));

        $this->assertSame(['stale' => 2, 'checked' => 4, 'unknown' => 0, 'unavailable' => false], $r);
    }

    public function test_event_times_are_read_as_utc(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00');   // the test clock is UTC here
        Http::fake(['*' => Http::response(['currentLocation' => ['eventTime' => '2026-11-12T00:30:00']], 200)]);

        $this->assertSame(0, (new StaleDeviceService())->summarize(collect(['A']))['stale']);
    }

    public function test_devices_the_api_cannot_answer_for_are_unknown_not_stale(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00');
        Http::fake(fn ($request) => $request['imei'] === 'OK' ? Http::response(['currentLocation' => ['eventTime' => '2026-11-12 11:00:00']], 200) : Http::response('nope', 404));

        $this->assertSame(['stale' => 0, 'checked' => 1, 'unknown' => 2, 'unavailable' => false], (new StaleDeviceService())->summarize(collect(['OK', 'GONE1', 'GONE2'])));
    }

    public function test_when_no_lookup_works_the_result_says_unavailable_and_is_not_remembered(): void
    {
        $online = false;
        Http::fake(function () use (&$online) { return $online ? Http::response(['currentLocation' => ['eventTime' => now()->toDateTimeString()]], 200) : Http::response('down', 500); });
        $service = new StaleDeviceService();

        $this->assertSame(['stale' => 0, 'checked' => 0, 'unknown' => 3, 'unavailable' => true], $service->summarize(collect(['A', 'B', 'C'])));
        $this->assertFalse(Cache::has(StaleDeviceService::CACHE_KEY));

        $online = true;
        $this->assertSame(['stale' => 0, 'checked' => 3, 'unknown' => 0, 'unavailable' => false], $service->summarize(collect(['A', 'B', 'C'])));
    }

    public function test_a_good_result_is_remembered_for_the_next_page_load(): void
    {
        Http::fake(['*' => Http::response(['currentLocation' => null], 200)]);
        $service = new StaleDeviceService();

        $first = $service->summarize(collect(['A', 'B']));
        $callsAfterFirst = count(Http::recorded());
        $second = $service->summarize(collect(['A', 'B']));

        $this->assertSame($first, $second);
        $this->assertSame($callsAfterFirst, count(Http::recorded()), 'the second load used the remembered answer');
    }

    public function test_at_most_100_vehicles_are_checked_and_repeats_are_counted_once(): void
    {
        Http::fake(['*' => Http::response(['currentLocation' => null], 200)]);
        $imeis = collect(range(1, 150))->map(fn ($i) => "IMEI{$i}")->merge(['IMEI1', 'IMEI2']);

        $r = (new StaleDeviceService())->summarize($imeis);

        $this->assertSame(100, $r['checked']);
        $this->assertCount(100, Http::recorded());
    }

    public function test_each_call_carries_the_key_and_asks_only_for_the_newest_point(): void
    {
        Http::fake(['*' => Http::response(['currentLocation' => null], 200)]);

        (new StaleDeviceService())->summarize(collect(['123456789012345']));

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.example.test/api/internal/gps-tracking-sync')
            && $r->header('X-Admin-Sync-Key') === ['autotest-key'] && $r['imei'] === '123456789012345' && $r['page'] == 1 && $r['pageSize'] == 1);
    }
}