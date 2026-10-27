<?php

namespace Tests\Pgsql;

use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\BuildsDistributorData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PgsqlTestCase;

/**
 * The distributor portal's read pages: dashboard, My Dealers, My Stock (and who may open them).
 *   GET distributor.dashboard / distributor.dealers / distributor.stock
 *
 * What is checked: the numbers are counted from the right rows (own stock, own dealers' stock, sold, leads),
 * other networks never leak in, the stock filters and search work (including % and _ typed by the user), bad
 * filter values are refused, paging cannot crash the page, and a login that is not an active distributor sees
 * nothing. Runs on real Postgres in the autotest schema; every row is rolled back.
 */
class DistributorPortalPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsDistributorData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function get_(string $route, array $query = [])
    {
        return $this->actingAs($this->login)->get(route($route, $query));
    }

    /** One distributor with three dealers (active, active without stock, archived) and a second network as noise. */
    private function network(): array
    {
        $dist = $this->distributor('Autotest Distributor D');
        $a = $this->dealerUnder($dist, 'Autotest Dealer A');
        $b = $this->dealerUnder($dist, 'Autotest Dealer B');
        $c = $this->dealerUnder($dist, 'Autotest Dealer C', 'archived');

        // The distributor's own devices: 3 ready (2 x X, 1 x Y), 1 not activated, 1 sold.
        $this->device($dist, 'AUTOTEST-CAT-X');
        $this->device($dist, 'AUTOTEST-CAT-X');
        $this->device($dist, 'AUTOTEST-CAT-Y');
        $this->device($dist, 'AUTOTEST-CAT-X', 'Not Activated');
        $this->device($dist, 'AUTOTEST-CAT-X', null, $this->lead($dist));

        // Dealer A: 2 ready + 1 sold. Dealer B: nothing. Dealer C (archived): 1 ready.
        $this->device($a);
        $this->device($a);
        $this->device($a, 'AUTOTEST-CAT-X', null, $this->lead($a));
        $this->device($c);
        $this->lead($a);
        $this->lead($c);

        // Another network that must never be counted.
        $other = $this->distributor('Autotest Distributor Other');
        $otherDealer = $this->dealerUnder($other, 'Autotest Dealer Other');
        $this->device($other);
        $this->device($otherDealer);
        $this->lead($otherDealer);

        return [$dist, $a, $b, $c, $other, $otherDealer];
    }

    // ------------------------------------------------------------------ dashboard

    public function test_the_dashboard_counts_the_right_rows(): void
    {
        [$dist] = $this->network();
        $this->asDistributor($dist);

        $r = $this->get_('distributor.dashboard')->assertOk();

        $this->assertSame(3, $r->viewData('ownStock'));
        $this->assertSame(1, $r->viewData('ownSold'));
        $this->assertSame(3, $r->viewData('dealerCount'));        // the archived dealer is still in the network
        $this->assertSame(3, $r->viewData('dealerStock'));        // A 2 + C 1
        $this->assertSame(1, $r->viewData('dealerSold'));
        $this->assertSame(3, $r->viewData('dealerCustomers'));    // A 2 (one of them bought a device) + C 1; the distributor's own leads are not dealer leads
        $this->assertSame(1, $r->viewData('dealersWithoutStock')); // only B: active and empty (C is archived)
        $this->assertSame(['AUTOTEST-CAT-X' => 2, 'AUTOTEST-CAT-Y' => 1], array_map('intval', $r->viewData('stockByCategory')->all()));
    }

    public function test_the_dashboard_of_a_distributor_with_nothing_still_opens(): void
    {
        $dist = $this->distributor('Autotest Distributor Empty');
        $this->asDistributor($dist);

        $r = $this->get_('distributor.dashboard')->assertOk();

        $this->assertSame(0, $r->viewData('ownStock'));
        $this->assertSame(0, $r->viewData('dealerCount'));
        $this->assertSame(0, $r->viewData('dealersWithoutStock'));
        $this->assertSame([], $r->viewData('stockByCategory')->all());
        $this->assertCount(0, $r->viewData('recent'));
    }

    public function test_the_dashboard_lists_the_last_six_transfers_it_sent_or_received_and_no_others(): void
    {
        [$dist, $a, , , $other, $otherDealer] = $this->network();

        $received = $this->ledger($dist, null, 'AUTOTEST-CAT-X', 5, '2026-03-01 10:00:00');
        $sent     = [];
        foreach ([2, 3, 4, 5, 6, 7] as $day) {
            $sent[] = $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 1, "2026-03-0{$day} 10:00:00");
        }
        $foreign = $this->ledger($otherDealer, $other, 'AUTOTEST-CAT-X', 9, '2026-03-09 10:00:00');
        $fromCompanyToDealer = $this->ledger($a, null, 'AUTOTEST-CAT-X', 9, '2026-03-10 10:00:00');
        $this->asDistributor($dist);

        $ids = $this->get_('distributor.dashboard')->assertOk()->viewData('recent')->pluck('id')->all();

        $this->assertCount(6, $ids);
        $this->assertSame(array_reverse(array_map(fn ($l) => $l->id, $sent)), $ids, 'newest first, the oldest sent record and the received one are cut off');
        $this->assertNotContains($foreign->id, $ids);
        $this->assertNotContains($fromCompanyToDealer->id, $ids, 'a company-to-dealer transfer is not the distributor\'s business');
        $this->assertNotContains($received->id, $ids);
    }

    // ------------------------------------------------------------------ My Dealers

    public function test_my_dealers_shows_each_dealer_with_its_numbers_and_hides_other_networks(): void
    {
        [$dist, $a, $b, $c, , $otherDealer] = $this->network();
        $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 2, '2026-02-01 09:00:00');
        $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 1, '2026-02-05 09:00:00');
        $this->ledger($a, null, 'AUTOTEST-CAT-X', 7, '2026-02-09 09:00:00');   // company sent: not "my" last transfer
        $this->asDistributor($dist);

        $r = $this->get_('distributor.dealers')->assertOk()
            ->assertSee('Autotest Dealer A')->assertSee('Autotest Dealer B')->assertSee('Autotest Dealer C')
            ->assertDontSee($otherDealer->full_name);

        $rows = $r->viewData('dealers')->keyBy('id');
        $this->assertSame([$a->id, $b->id, $c->id], $r->viewData('dealers')->pluck('id')->all(), 'ordered by name');
        $this->assertSame([2, 1, 2], [$rows[$a->id]->in_stock, $rows[$a->id]->sold, $rows[$a->id]->customers]);
        $this->assertSame([0, 0, 0], [$rows[$b->id]->in_stock, $rows[$b->id]->sold, $rows[$b->id]->customers]);
        $this->assertSame([1, 0, 1], [$rows[$c->id]->in_stock, $rows[$c->id]->sold, $rows[$c->id]->customers]);
        $this->assertSame('2026-02-05', $rows[$a->id]->last_transfer_at->toDateString());
        $this->assertNull($rows[$b->id]->last_transfer_at);
    }

    public function test_a_direct_dealer_and_a_dealer_of_another_distributor_are_not_listed(): void
    {
        $dist   = $this->distributor('Autotest Distributor D');
        $mine   = $this->dealerUnder($dist, 'Autotest Dealer Mine');
        $direct = $this->makeDealerRow('Autotest Dealer Direct', 'retailer', null, 'active');
        $this->asDistributor($dist);

        $this->get_('distributor.dealers')->assertOk()
            ->assertSee($mine->full_name)->assertDontSee($direct->full_name);
    }

    // ------------------------------------------------------------------ My Stock

    private function stockSet(): array
    {
        $dist = $this->distributor('Autotest Distributor S');
        $dealer = $this->dealerUnder($dist, 'Autotest Dealer S');
        $ready1 = $this->device($dist, 'AUTOTEST-CAT-X');
        $ready2 = $this->device($dist, 'AUTOTEST-CAT-Y');
        $idle   = $this->device($dist, 'AUTOTEST-CAT-X', 'Not Activated');
        $sold   = $this->device($dist, 'AUTOTEST-CAT-X', null, $this->lead($dist));
        $dealerOwn = $this->device($dealer);                       // held by the dealer: not the distributor's stock
        $foreign   = $this->device($this->distributor('Autotest Distributor Z'));
        $this->asDistributor($dist);

        return [$ready1, $ready2, $idle, $sold, $dealerOwn, $foreign];
    }

    public function test_my_stock_lists_only_the_distributors_own_devices_with_totals(): void
    {
        [$ready1, $ready2, $idle, $sold, $dealerOwn, $foreign] = $this->stockSet();

        $r = $this->get_('distributor.stock')->assertOk();

        $this->assertEqualsCanonicalizing([$ready1->imei_number, $ready2->imei_number, $idle->imei_number, $sold->imei_number], $this->imeis($r));
        $this->assertNotContains($dealerOwn->imei_number, $this->imeis($r));
        $this->assertNotContains($foreign->imei_number, $this->imeis($r));
        $this->assertSame(4, $r->viewData('totalHeld'));
        $this->assertSame(2, $r->viewData('totalAvailable'));
        $this->assertEqualsCanonicalizing([$ready1->shdevice_id, $ready2->shdevice_id], $r->viewData('transferable')->keys()->all());
        $this->assertSame(['AUTOTEST-CAT-X' => 3, 'AUTOTEST-CAT-Y' => 1], array_map('intval', $r->viewData('categories')->all()));
    }

    public function test_the_availability_filter_splits_ready_and_not_ready_devices(): void
    {
        [$ready1, $ready2, $idle, $sold] = $this->stockSet();

        $ready = $this->get_('distributor.stock', ['availability' => 'available'])->assertOk();
        $this->assertEqualsCanonicalizing([$ready1->imei_number, $ready2->imei_number], $this->imeis($ready));

        $other = $this->get_('distributor.stock', ['availability' => 'other'])->assertOk();
        $this->assertEqualsCanonicalizing([$idle->imei_number, $sold->imei_number], $this->imeis($other));

        $all = $this->get_('distributor.stock', ['availability' => 'all'])->assertOk();
        $this->assertCount(4, $this->imeis($all));
    }

    public function test_the_category_filter_works_including_uncategorised(): void
    {
        [$ready1, $ready2, $idle, $sold] = $this->stockSet();

        $x = $this->get_('distributor.stock', ['category' => 'AUTOTEST-CAT-X'])->assertOk();
        $this->assertEqualsCanonicalizing([$ready1->imei_number, $idle->imei_number, $sold->imei_number], $this->imeis($x));

        $y = $this->get_('distributor.stock', ['category' => 'AUTOTEST-CAT-Y', 'availability' => 'available'])->assertOk();
        $this->assertSame([$ready2->imei_number], $this->imeis($y));

        $none = $this->get_('distributor.stock', ['category' => 'AUTOTEST-NO-SUCH-CAT'])->assertOk();
        $this->assertSame([], $this->imeis($none));
    }

    public function test_search_finds_a_device_by_imei_or_sim_and_percent_and_underscore_are_plain_characters(): void
    {
        [$ready1, $ready2] = $this->stockSet();

        $byImei = $this->get_('distributor.stock', ['q' => substr($ready1->imei_number, 3)])->assertOk();
        $this->assertSame([$ready1->imei_number], $this->imeis($byImei));

        $bySim = $this->get_('distributor.stock', ['q' => $ready2->sim_number])->assertOk();
        $this->assertSame([$ready2->imei_number], $this->imeis($bySim));

        foreach (['%', '_', '\\', "99900000'; DROP TABLE dealers; --"] as $evil) {
            $r = $this->get_('distributor.stock', ['q' => $evil])->assertOk();
            $this->assertSame([], $this->imeis($r), "search for [{$evil}] must match nothing");
        }
        $this->assertTrue(Dealer::query()->exists(), 'the dealers table is still there');
    }

    public static function badStockFilters(): array
    {
        return [
            'unknown availability' => [['availability' => 'everything'], 'availability'],
            'availability as list' => [['availability' => ['all']], 'availability'],
            'search too long'      => [['q' => str_repeat('1', 61)], 'q'],
            'search as list'       => [['q' => ['1']], 'q'],
            'category too long'    => [['category' => str_repeat('c', 101)], 'category'],
            'category as list'     => [['category' => ['x']], 'category'],
        ];
    }

    #[DataProvider('badStockFilters')]
    public function test_bad_stock_filters_are_refused_with_a_message(array $query, string $field): void
    {
        $this->stockSet();

        $this->get_('distributor.stock', $query)->assertSessionHasErrors($field);
    }

    public function test_paging_works_and_a_silly_page_number_cannot_crash_the_page(): void
    {
        $dist = $this->distributor('Autotest Distributor P');
        for ($i = 0; $i < 30; $i++) {
            $this->device($dist);
        }
        $this->asDistributor($dist);

        $this->assertCount(25, $this->imeis($this->get_('distributor.stock')->assertOk()));
        $this->assertCount(5, $this->imeis($this->get_('distributor.stock', ['page' => 2])->assertOk()));

        foreach (['0', '-3', 'abc', '99999999999', '9223372036854775807', '99999999999999999999999'] as $page) {
            $this->get_('distributor.stock', ['page' => $page])->assertOk();
        }
    }

    // ------------------------------------------------------------------ who may open the pages

    public static function portalPages(): array
    {
        return [
            'dashboard' => ['distributor.dashboard'],
            'dealers'   => ['distributor.dealers'],
            'stock'     => ['distributor.stock'],
            'transfer'  => ['distributor.transfer'],
            'history'   => ['distributor.transfers'],
        ];
    }

    #[DataProvider('portalPages')]
    public function test_a_visitor_who_is_not_signed_in_is_sent_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('portalPages')]
    public function test_a_distributor_login_with_no_distributor_behind_it_is_refused(string $route): void
    {
        $this->login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => null]);

        $this->get_($route)->assertForbidden();
    }

    #[DataProvider('portalPages')]
    public function test_a_login_pointing_at_an_archived_distributor_is_refused(string $route): void
    {
        $this->asDistributor($this->distributor('Autotest Distributor Archived', 'archived'));

        $this->get_($route)->assertForbidden();
    }

    #[DataProvider('portalPages')]
    public function test_a_login_pointing_at_a_normal_dealer_instead_of_a_distributor_is_refused(string $route): void
    {
        $dist = $this->distributor('Autotest Distributor D');
        $this->asDistributor($this->dealerUnder($dist, 'Autotest Dealer Not A Distributor'));

        $this->get_($route)->assertForbidden();
    }
}