<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\RenewalPackage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * RENEWAL PACKAGES (the price list), on real PostgreSQL.
 *   GET   admin.renewal-packages            the price list (ADMIN only)
 *   PATCH admin.renewal-packages.update     change price, margins, warranty, on/off, start date
 *   php artisan renewal-packages:push       send the list to the mobile app again
 *
 * Why it matters (simple words): this is the price customers pay and the split between the company, the
 * distributor and the retailer. The rules the portal promises are:
 *   - Customer price = company margin + distributor margin + retailer margin (to the cent).
 *   - A priced package needs all three margins; margins without a price are refused.
 *   - Every real change is audited (who, what, from, to). A save that changes nothing writes no audit entry.
 *   - The mobile app gets prices and warranty only. Company, distributor and retailer margins NEVER leave the portal.
 *   - If the mobile app cannot be reached, the save still stands and the admin is warned (nothing is lost).
 *
 * The mobile API is FAKED. The price list table is empty in "autotest", so each test creates the packages it needs.
 * One login per test. Everything is rolled back.
 */
class RenewalPackageTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const API      = 'https://api.test';
    private const SYNC_KEY = 'sync-key-for-tests';

    /** What the fake API answers to a price list push: ok | refuse | refuse_message | down */
    private string $push = 'ok';

    /** Every price list the portal pushed: the packages array of each call. */
    private array $pushed = [];
    private array $pushHeaders = [];
    private int $pushCalls = 0;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.shalotrack_api.base_url' => self::API,
            'services.shalotrack_api.sync_key' => self::SYNC_KEY,
        ]);

        Http::fake(function (ClientRequest $request) {
            // The admin sidebar asks the API for the complaint count; answer it quietly (not a price list call).
            if (str_contains($request->url(), '/api/internal/complaints/')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::API . '/api/internal/renewal-packages') {
                $this->pushCalls++;
                $this->pushed[] = $request->data()['packages'] ?? null;
                $this->pushHeaders[] = $request->header('X-Admin-Sync-Key')[0] ?? null;

                return match ($this->push) {
                    'down'           => throw new ConnectionException('api is down'),
                    'refuse'         => Http::response(['errors' => ['Price list is not valid.']], 422),
                    'refuse_message' => Http::response(['message' => 'Try later.'], 503),
                    default          => Http::response(['message' => 'ok'], 200),
                };
            }

            return Http::response([], 404);
        });

        // The price master starts empty here; make that certain, then add what each test needs.
        RenewalPackage::query()->delete();
        $this->seedPackages();
    }

    // ------------------------------------------------------------------ helpers

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function seedPackages(): void
    {
        $rows = [
            // model, code, label, positioning, months, price, company, distributor, retailer, warranty, sort
            ['3 Months', 'THREE_MONTHS', '3 Months', 'Trial plan', 3, null, null, null, null, 0, 1],
            ['6 Months', 'SIX_MONTHS', '6 Months', 'Short-Term Plan', 6, 1999, 1499, 200, 300, 0, 2],
            ['1 Year', 'ONE_YEAR', '1 Year', 'Standard Plan', 12, 2999, 2199, 300, 500, 12, 3],
        ];
        foreach ($rows as [$model, $code, $label, $pos, $months, $price, $co, $di, $re, $war, $sort]) {
            RenewalPackage::create([
                'model' => $model, 'code' => $code, 'label' => $label, 'positioning' => $pos, 'months' => $months,
                'customer_price' => $price, 'company_margin' => $co, 'distributor_margin' => $di, 'retailer_margin' => $re,
                'warranty_months' => $war, 'is_active' => true, 'effective_from' => $price === null ? null : '2026-09-30',
                'sort_order' => $sort,
            ]);
        }
    }

    private function package(string $model = '1 Year'): RenewalPackage
    {
        return RenewalPackage::where('model', $model)->firstOrFail();
    }

    /** A valid form for the 1 Year package (changes nothing). Overrides replace values; '__missing__' removes a field. */
    private function form(array $overrides = []): array
    {
        $form = array_merge([
            'customer_price'     => '2999',
            'company_margin'     => '2199',
            'distributor_margin' => '300',
            'retailer_margin'    => '500',
            'warranty_months'    => '12',
            'is_active'          => '1',
            'effective_from'     => '2026-09-30',
        ], $overrides);

        return array_filter($form, fn ($v) => $v !== '__missing__');
    }

    private function save(RenewalPackage $package, array $form)
    {
        return $this->actingAs($this->admin())->patch(route('admin.renewal-packages.update', $package->id), $form);
    }

    private function updateAudits()
    {
        return AuditLog::where('action', 'package.updated')->get();
    }

    // ------------------------------------------------------------------ the price list page

    public function test_the_page_lists_every_package_in_order_and_marks_the_unpriced_one(): void
    {
        $this->actingAs($this->admin())->get(route('admin.renewal-packages'))
            ->assertOk()
            ->assertSeeInOrder(['3 Months', '6 Months', '1 Year'])
            ->assertSee('No approved price')
            ->assertSee('Rs. 2,999.00')
            ->assertSee('Rs. 1,999.00');
    }

    // ------------------------------------------------------------------ saving a price

    public function test_a_price_change_is_saved_audited_and_pushed_to_the_app(): void
    {
        $package = $this->package();

        $this->save($package, $this->form(['customer_price' => '3099', 'company_margin' => '2299']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $saved = $package->fresh();
        $this->assertSame('3099.00', $saved->customer_price);
        $this->assertSame('2299.00', $saved->company_margin);
        $this->assertSame('300.00', $saved->distributor_margin);
        $this->assertSame('500.00', $saved->retailer_margin);
        $this->assertSame($this->admin()->admin_id, $saved->updated_by);

        // The audit entry holds only what changed, with before and after.
        $logs = $this->updateAudits();
        $this->assertCount(1, $logs);
        $this->assertSame('renewal_package', $logs[0]->subject_type);
        $this->assertSame((string) $package->id, $logs[0]->subject_id);
        $this->assertSame('1 Year', $logs[0]->subject_label);
        $this->assertEqualsCanonicalizing(['customer_price', 'company_margin'], array_keys($logs[0]->changes));
        $this->assertEquals(['from' => '2999.00', 'to' => '3099.00'], $logs[0]->changes['customer_price']);
        $this->assertEquals(['from' => '2199.00', 'to' => '2299.00'], $logs[0]->changes['company_margin']);
        $this->assertSame('ONE_YEAR', $logs[0]->meta['code']);

        $this->assertSame(1, $this->pushCalls, 'the app must be told once');
        $this->assertSame([self::SYNC_KEY], $this->pushHeaders);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_entry_but_still_refreshes_the_app(): void
    {
        $this->save($this->package(), $this->form())->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '(nothing changed)'));

        $this->assertCount(0, $this->updateAudits());
        $this->assertSame(1, $this->pushCalls);
    }

    public function test_every_kind_of_change_is_audited_with_readable_values(): void
    {
        $package = $this->package();

        $this->save($package, $this->form(['warranty_months' => '18', 'is_active' => '0', 'effective_from' => '2026-11-01']))
            ->assertSessionHasNoErrors();

        $log = $this->updateAudits()->first();
        $this->assertEqualsCanonicalizing(['warranty_months', 'is_active', 'effective_from'], array_keys($log->changes));
        $this->assertEquals(['from' => '12', 'to' => '18'], $log->changes['warranty_months']);
        $this->assertEquals(['from' => 'yes', 'to' => 'no'], $log->changes['is_active']);
        $this->assertEquals(['from' => '2026-09-30', 'to' => '2026-11-01'], $log->changes['effective_from']);
        $this->assertFalse($package->fresh()->is_active);
        $this->assertFalse($package->fresh()->isOffered(), 'a switched-off package is not offered');
    }

    public function test_the_app_gets_prices_and_warranty_but_never_the_margins(): void
    {
        $this->save($this->package(), $this->form(['is_active' => '0']))->assertSessionHasNoErrors();

        $list = $this->pushed[0];
        $this->assertCount(3, $list);
        $this->assertSame(['THREE_MONTHS', 'SIX_MONTHS', 'ONE_YEAR'], array_column($list, 'code'), 'sorted by sort_order');

        $expectedKeys = ['code', 'label', 'positioning', 'months', 'customerPrice', 'warrantyMonths', 'isActive', 'sortOrder'];
        foreach ($list as $package) {
            $keys = array_keys($package);
            sort($keys);
            $sorted = $expectedKeys;
            sort($sorted);
            $this->assertSame($sorted, $keys, 'only customer-safe fields may be sent');
        }

        $json = json_encode($this->pushed[0]);
        $this->assertStringNotContainsStringIgnoringCase('margin', $json);
        foreach (['2199', '1499', '"retailer', '"distributor', '"company'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} must never leave the portal");
        }

        $byCode = array_column($list, null, 'code');
        $this->assertNull($byCode['THREE_MONTHS']['customerPrice'], 'an unpriced package is sent as no price');
        $this->assertEquals(1999, $byCode['SIX_MONTHS']['customerPrice']);
        $this->assertEquals(2999, $byCode['ONE_YEAR']['customerPrice']);
        $this->assertSame(12, $byCode['ONE_YEAR']['warrantyMonths']);
        $this->assertTrue($byCode['SIX_MONTHS']['isActive']);
        $this->assertFalse($byCode['ONE_YEAR']['isActive']);
        $this->assertSame(12, $byCode['ONE_YEAR']['months']);
    }

    // ------------------------------------------------------------------ price rules

    public static function badForms(): array
    {
        return [
            'price is negative'            => [['customer_price' => '-1']],
            'price is text'                => [['customer_price' => 'abc']],
            'price has three decimals'     => [['customer_price' => '2999.999']],
            'price too big'                => [['customer_price' => '10000000']],
            'margin is negative'           => [['company_margin' => '-5']],
            'margin is text'               => [['distributor_margin' => 'lots']],
            'margin has three decimals'    => [['retailer_margin' => '500.123']],
            'warranty below zero'          => [['warranty_months' => '-1']],
            'warranty above 120'           => [['warranty_months' => '121']],
            'warranty is text'             => [['warranty_months' => 'long']],
            'warranty has decimals'        => [['warranty_months' => '12.5']],
            'warranty missing'             => [['warranty_months' => '__missing__']],
            'on/off missing'               => [['is_active' => '__missing__']],
            'on/off is a word'             => [['is_active' => 'maybe']],
            'start date missing'           => [['effective_from' => '__missing__']],
            'start date is text'           => [['effective_from' => 'not-a-date']],
            'price is not the sum'         => [['customer_price' => '3000']],
            'price is one cent off'        => [['customer_price' => '2999.01']],
            'a margin is missing'          => [['company_margin' => '']],
            'two margins are missing'      => [['company_margin' => '', 'retailer_margin' => '']],
            'margins with no price'        => [['customer_price' => '']],
        ];
    }

    #[DataProvider('badForms')]
    public function test_a_bad_price_form_is_refused_and_nothing_changes(array $overrides): void
    {
        $package = $this->package();
        $before = $package->fresh()->getAttributes();

        $this->save($package, $this->form($overrides))->assertSessionHasErrors();

        $this->assertEquals($before, $package->fresh()->getAttributes(), 'the saved package must be untouched');
        $this->assertCount(0, $this->updateAudits());
        $this->assertSame(0, $this->pushCalls, 'nothing is pushed when nothing is saved');
    }

    public static function goodForms(): array
    {
        return [
            'no warranty'          => [['warranty_months' => '0'], ['warranty_months' => 0]],
            'longest warranty'     => [['warranty_months' => '120'], ['warranty_months' => 120]],
            'switched off'         => [['is_active' => '0'], ['is_active' => false]],
            'price written 2999.0' => [['customer_price' => '2999.0'], ['customer_price' => '2999.00']],
        ];
    }

    #[DataProvider('goodForms')]
    public function test_the_edges_that_are_allowed_are_saved(array $overrides, array $expected): void
    {
        $package = $this->package();

        $this->save($package, $this->form($overrides))->assertSessionHasNoErrors();

        $saved = $package->fresh();
        foreach ($expected as $field => $value) {
            $this->assertEquals($value, $saved->{$field}, $field);
        }
    }

    public function test_the_biggest_allowed_price_is_saved(): void
    {
        $package = $this->package();

        $this->save($package, $this->form([
            'customer_price' => '9999999.99', 'company_margin' => '9999999.99', 'distributor_margin' => '0', 'retailer_margin' => '0',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('9999999.99', $package->fresh()->customer_price);
    }

    public function test_clearing_the_price_and_all_margins_takes_the_package_off_sale(): void
    {
        $package = $this->package();

        $this->save($package, $this->form([
            'customer_price' => '', 'company_margin' => '', 'distributor_margin' => '', 'retailer_margin' => '',
        ]))->assertSessionHasNoErrors();

        $saved = $package->fresh();
        $this->assertNull($saved->customer_price);
        $this->assertNull($saved->company_margin);
        $this->assertNull($saved->distributor_margin);
        $this->assertNull($saved->retailer_margin);
        $this->assertFalse($saved->isOffered(), 'an unpriced package is never offered');
        $this->assertNull(array_column($this->pushed[0], null, 'code')['ONE_YEAR']['customerPrice']);
    }

    public function test_the_unpriced_package_can_be_priced_when_all_three_margins_are_given(): void
    {
        $trial = $this->package('3 Months');
        $this->assertFalse($trial->isOffered());

        $this->save($trial, $this->form([
            'customer_price' => '999', 'company_margin' => '700', 'distributor_margin' => '100', 'retailer_margin' => '199',
            'warranty_months' => '0', 'effective_from' => '2026-10-27',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue($trial->fresh()->isOffered());
        $this->assertEquals(999, array_column($this->pushed[0], null, 'code')['THREE_MONTHS']['customerPrice']);
    }

    // ------------------------------------------------------------------ the mobile app cannot be reached

    public static function pushFailures(): array
    {
        return [
            'api is down'            => ['down', 'The mobile API could not be reached.'],
            'api gives a reason'     => ['refuse', 'The mobile API refused the price list: Price list is not valid.'],
            'api gives only a code'  => ['refuse_message', 'The mobile API refused the price list: Try later.'],
        ];
    }

    #[DataProvider('pushFailures')]
    public function test_when_the_app_cannot_be_told_the_price_is_still_saved_and_the_admin_is_warned(string $mode, string $words): void
    {
        $this->push = $mode;
        $package = $this->package();

        $response = $this->save($package, $this->form(['customer_price' => '3099', 'company_margin' => '2299']));

        $response->assertSessionHasNoErrors()->assertSessionHas('warning');
        $this->assertStringContainsString($words, session('warning'));
        $this->assertStringContainsString('the app still shows the old one', session('warning'));

        $this->assertSame('3099.00', $package->fresh()->customer_price, 'the save must not be undone');
        $this->assertCount(1, $this->updateAudits(), 'the change is audited even when the push fails');
    }

    // ------------------------------------------------------------------ bad ids in the address

    public static function badPackageIds(): array
    {
        return [
            'a number that does not exist' => ['987654321'],
            'text'                         => ['abc'],
            'a number too big for the db'  => ['99999999999999999999'],
            'a decimal'                    => ['1.5'],
            'a negative number'            => ['-1'],
            'zero'                         => ['0'],
        ];
    }

    #[DataProvider('badPackageIds')]
    public function test_a_wrong_package_id_in_the_address_is_a_404_not_a_crash(string $id): void
    {
        $before = RenewalPackage::orderBy('id')->get()->map->getAttributes()->all();

        $this->actingAs($this->admin())->patch('/admin/renewal-packages/' . $id, $this->form())->assertNotFound();

        $this->assertSame(0, $this->pushCalls);
        $this->assertEquals($before, RenewalPackage::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertCount(0, $this->updateAudits());
    }

    // ------------------------------------------------------------------ who may use it

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_see_or_change_prices(string $role): void
    {
        $package = $this->package();
        $before = $package->fresh()->getAttributes();
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.renewal-packages'))->assertForbidden();
        $this->patch(route('admin.renewal-packages.update', $package->id), $this->form(['customer_price' => '1', 'company_margin' => '1', 'distributor_margin' => '0', 'retailer_margin' => '0']))
            ->assertForbidden();

        $this->assertEquals($before, $package->fresh()->getAttributes());
        $this->assertSame(0, $this->pushCalls);
        $this->assertCount(0, $this->updateAudits());
    }

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $package = $this->package();

        $this->get(route('admin.renewal-packages'))->assertRedirect('/login');
        $this->patch(route('admin.renewal-packages.update', $package->id), $this->form())->assertRedirect('/login');
        $this->assertSame(0, $this->pushCalls);
    }

    // ------------------------------------------------------------------ the command that re-sends the list

    public function test_the_push_command_sends_the_list_and_reports_success(): void
    {
        $this->artisan('renewal-packages:push')
            ->expectsOutputToContain('The mobile app price list is up to date.')
            ->assertExitCode(0);

        $this->assertSame(1, $this->pushCalls);
        $this->assertSame([self::SYNC_KEY], $this->pushHeaders);
    }

    #[DataProvider('pushFailures')]
    public function test_the_push_command_fails_loudly_when_the_app_cannot_be_reached(string $mode, string $words): void
    {
        $this->push = $mode;

        $this->artisan('renewal-packages:push')
            ->expectsOutputToContain($words)
            ->assertExitCode(1);
    }

    public function test_the_push_command_refuses_to_send_an_empty_list(): void
    {
        RenewalPackage::query()->delete();

        $this->artisan('renewal-packages:push')
            ->expectsOutputToContain('There are no packages to send.')
            ->assertExitCode(1);

        $this->assertSame(0, $this->pushCalls, 'an empty list must never replace the app\'s prices');
    }

    // ------------------------------------------------------------------ the package rules used by activation

    public function test_warranty_and_offered_rules(): void
    {
        $this->assertSame(12, RenewalPackage::warrantyMonthsFor('1 Year'));
        $this->assertSame(0, RenewalPackage::warrantyMonthsFor('6 Months'));
        $this->assertSame(0, RenewalPackage::warrantyMonthsFor('99 Years'), 'unknown plan: no added warranty');
        $this->assertSame(0, RenewalPackage::warrantyMonthsFor(null));
        $this->assertSame(0, RenewalPackage::warrantyMonthsFor(''));

        $this->assertNull(RenewalPackage::forModel('99 Years'));
        $this->assertSame('ONE_YEAR', RenewalPackage::forModel('1 Year')->code);

        $this->assertTrue($this->package('1 Year')->isOffered());
        $this->assertFalse($this->package('3 Months')->isOffered(), 'no price: not offered');

        $this->package('6 Months')->update(['is_active' => false]);
        $this->assertFalse($this->package('6 Months')->isOffered(), 'switched off: not offered');
    }

    public function test_two_packages_can_never_share_a_code_or_a_plan_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        RenewalPackage::create(['model' => '1 Year', 'code' => 'ONE_YEAR_COPY', 'label' => 'Copy', 'months' => 12]);
    }
}