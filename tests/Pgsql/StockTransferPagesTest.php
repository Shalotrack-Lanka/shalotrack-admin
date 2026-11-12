<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * STOCK TRANSFER, the whole screen, on real PostgreSQL (autotest schema).
 * (The basic "send stock to a dealer" rules are in StockTransferTest; this file covers the page and the other buttons.)
 *
 *   GET    admin.stock_transfer                  ADMIN: the page (what can be sent, to whom, the history, who holds what)
 *   GET    admin.stock_transfer.sim-numbers      ADMIN: the SIM numbers of one category that can still be sent
 *   GET    admin.stock_transfer.edit-data        ADMIN: what the Edit window needs for one transfer
 *   PUT    admin.stock_transfer.update           ADMIN: change dealer or the devices of a transfer
 *   DELETE admin.stock_transfer.destroy          ADMIN: undo a transfer made by mistake
 *   GET    admin.stock_transfer.report           ADMIN: the full PDF report
 *
 * What the tests prove (simple words):
 *   - the page offers only devices that are Activated, have a SIM, belong to the company and are not on a vehicle,
 *   - only ACTIVE dealers can receive, and a dealer under a distributor says so,
 *   - hints appear for stock that is not activated yet or has no SIM,
 *   - Edit can add and remove devices, change the dealer, keeps the history right and is audited,
 *   - Edit can only add devices of the SAME category that are still free; a device someone else took is refused
 *     and nothing at all changes,
 *   - Undo gives every device back to the company, hides the history row (it is kept, not erased) and is audited,
 *   - a transfer is LOCKED (no edit, no undo) once any device was passed on, sold or bound, or if a distributor sent it,
 *   - when the app server cannot be told, Undo still works and the person is warned,
 *   - only the company ADMIN may use any of it.
 *
 * The app server is FAKED. Everything is rolled back after each test.
 */
class StockTransferPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private ?Admin $admin = null;
    private bool $apiDown = false;
    /** @var array<int,ClientRequest> */
    private array $sent = [];
    private string $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->category = 'AUTOTEST-STP-' . $this->nextIntakeNumber();
        config(['services.shalotrack_api.base_url' => 'https://api.test', 'services.shalotrack_api.sync_key' => 'autotest-sync-key']);

        Http::fake(function (ClientRequest $request) {
            $this->sent[] = $request;

            return $this->apiDown ? Http::response(['message' => 'boom'], 500) : Http::response(['data' => []], 200);
        });
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function device(array $over = []): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        return SetupShalotrackDevice::create(array_merge([
            'device_category' => $this->category,
            'imei_number'     => $this->validImei(),
            'sim_number'      => '07' . str_pad((string) (3000000 + $n), 9, '0', STR_PAD_LEFT),
            'status'          => 'Activated',
        ], $over));
    }

    private function devices(int $count, array $over = []): array
    {
        return array_map(fn () => $this->device($over), range(1, $count));
    }

    private function sims(array $devices): array
    {
        return array_map(fn ($d) => $d->sim_number, $devices);
    }

    private function send(Dealer $dealer, array $devices, ?string $category = null)
    {
        return $this->actingAs($this->admin())->post(route('admin.stock_transfer.store'), [
            'device_category' => $category ?? $this->category,
            'dealer_id'       => $dealer->id,
            'sim_numbers'     => $this->sims($devices),
        ]);
    }

    private function ledgerOf(Dealer $dealer): DealerTransferLedger
    {
        return DealerTransferLedger::where('dealer_id', $dealer->id)->where('device_category', $this->category)->latest('id')->firstOrFail();
    }

    private function edit(DealerTransferLedger $ledger, Dealer $dealer, array $sims)
    {
        return $this->actingAs($this->admin())->from(route('admin.stock_transfer'))
            ->put(route('admin.stock_transfer.update', $ledger->id), ['dealer_id' => $dealer->id, 'sim_numbers' => $sims]);
    }

    private function undo(DealerTransferLedger $ledger)
    {
        return $this->actingAs($this->admin())->from(route('admin.stock_transfer'))->delete(route('admin.stock_transfer.destroy', $ledger->id));
    }

    private function audits(string $action): \Illuminate\Support\Collection
    {
        return AuditLog::where('action', $action)->get();
    }

    // ================================================================== the page

    public function test_the_page_offers_only_activated_company_stock_with_a_sim_that_is_not_on_a_vehicle(): void
    {
        $ok = $this->device();
        $this->device(['status' => 'Not Activated', 'sim_number' => '0700000111']);        // not activated
        $this->makeBound(['imei_number' => ($bound = $this->device())->imei_number]);       // on a vehicle
        $other = $this->makeDealer();
        $this->device(['dealer_id' => $other->id, 'sim_number' => '0700000222']);          // already with a dealer

        $json = $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.sim-numbers', $this->category))->assertOk()->json();

        $this->assertSame([$ok->sim_number], $json);
        $this->assertNotContains($bound->sim_number, $json);
    }

    public function test_the_category_dropdown_lists_a_category_only_when_something_in_it_can_be_sent(): void
    {
        $this->device();
        $emptyCat = 'AUTOTEST-STP-EMPTY-' . $this->nextIntakeNumber();
        $this->device(['device_category' => $emptyCat, 'status' => 'Not Activated']);

        $html = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="' . $this->category . '">', $html);
        $this->assertStringNotContainsString('<option value="' . $emptyCat . '">', $html);
    }

    public function test_only_active_dealers_can_receive_and_a_distributor_is_marked(): void
    {
        $active = $this->makeDealer(['full_name' => 'Autotest Active Retailer']);
        $dist   = $this->makeDealer(['full_name' => 'Autotest Big Distributor', 'dealer_status' => 'distributor']);
        $off    = $this->makeDealer(['full_name' => 'Autotest Switched Off Dealer', 'status' => 'inactive']);

        $html = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('Autotest Active Retailer', $html);
        $this->assertStringContainsString('Autotest Big Distributor', $html);
        $this->assertStringContainsString('(Distributor)', $html);
        $this->assertStringNotContainsString('Autotest Switched Off Dealer', $html);
    }

    public function test_a_dealer_under_a_distributor_is_marked_with_its_parent(): void
    {
        $dist = $this->makeDealer(['full_name' => 'Autotest Parent Distributor', 'dealer_status' => 'distributor']);
        $this->makeDealer(['full_name' => 'Autotest Child Dealer', 'distributor_id' => $dist->id]);

        $html = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('Autotest Child Dealer', $html);
        $this->assertStringContainsString('Autotest Parent Distributor', $html);
    }

    public function test_the_hints_count_stock_that_is_not_activated_or_has_no_sim(): void
    {
        $before = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();
        preg_match('/(\d+) device\(s\) are in stock but not activated yet/', $before, $m0);
        preg_match('/(\d+) activated device\(s\) have no SIM/', $before, $s0);

        $this->device(['status' => 'Not Activated']);
        $this->device(['status' => 'Not Activated']);
        $this->device(['sim_number' => null]);

        $after = $this->get(route('admin.stock_transfer'))->assertOk()->getContent();
        preg_match('/(\d+) device\(s\) are in stock but not activated yet/', $after, $m1);
        preg_match('/(\d+) activated device\(s\) have no SIM/', $after, $s1);

        $this->assertSame(((int) ($m0[1] ?? 0)) + 2, (int) $m1[1]);
        $this->assertSame(((int) ($s0[1] ?? 0)) + 1, (int) $s1[1]);
    }

    public function test_the_history_and_the_holders_list_show_a_transfer(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest History Dealer']);
        $devs = $this->devices(2);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('Autotest History Dealer', $html);
        $this->assertStringContainsString($this->category, $html);
        $this->assertStringContainsString($devs[0]->imei_number, $html);
        $this->assertStringContainsString($devs[1]->imei_number, $html);
        $this->assertStringContainsString(route('admin.stock_transfer.destroy', $this->ledgerOf($dealer)->id), $html);
    }

    public function test_names_are_shown_as_text_never_run_as_a_script(): void
    {
        $dealer = $this->makeDealer(['full_name' => '<script>alert(1)</script>']);
        $this->send($dealer, $this->devices(1))->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin())->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ================================================================== the SIM list and the Edit window data

    public function test_the_sim_list_is_in_order_and_two_addresses_give_the_same_answer(): void
    {
        $devs = $this->devices(3);

        $a = $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.sim-numbers', $this->category))->assertOk()->json();
        $b = $this->getJson(route('admin.stock_transfer.sim_numbers', $this->category))->assertOk()->json();

        $expected = $this->sims($devs);
        sort($expected);
        $this->assertSame($expected, $a);
        $this->assertSame($a, $b);
    }

    public function test_a_category_with_nothing_to_send_or_that_does_not_exist_gives_an_empty_list(): void
    {
        $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.sim-numbers', 'AUTOTEST-NO-SUCH-CATEGORY'))->assertOk()->assertExactJson([]);
    }

    public function test_a_strange_category_name_is_just_an_empty_list(): void
    {
        foreach (["x'%20or%20'1'='1", '%3Cscript%3E', str_repeat('a', 300)] as $name) {
            $this->actingAs($this->admin())->getJson('/admin/master-pages/stock-transfer/' . $name . '/sim-numbers')->assertOk()->assertExactJson([]);
        }
    }

    public function test_a_sim_that_went_to_a_dealer_leaves_the_list(): void
    {
        $devs = $this->devices(2);
        $this->send($this->makeDealer(), [$devs[0]])->assertSessionHasNoErrors();

        $json = $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.sim-numbers', $this->category))->assertOk()->json();

        $this->assertSame([$devs[1]->sim_number], $json);
    }

    public function test_edit_data_has_the_dealer_the_category_the_selected_sims_and_keeps_them_in_the_options(): void
    {
        $dealer = $this->makeDealer();
        $sent = $this->devices(2);
        $this->send($dealer, $sent)->assertSessionHasNoErrors();
        $free = $this->device();
        $ledger = $this->ledgerOf($dealer);

        $json = $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.edit-data', $ledger->id))->assertOk()->json();

        $this->assertSame($dealer->id, $json['dealer_id']);
        $this->assertSame($this->category, $json['device_category']);
        $selected = $this->sims($sent);
        sort($selected);
        $this->assertSame($selected, $json['selected']);
        $options = array_merge($this->sims($sent), [$free->sim_number]);
        sort($options);
        $this->assertSame($options, $json['sim_numbers']);                // sent ones stay in the list
    }

    public function test_edit_data_for_a_missing_transfer_is_a_404(): void
    {
        $this->actingAs($this->admin())->getJson(route('admin.stock_transfer.edit-data', 987654321))->assertNotFound();
    }

    // ================================================================== Edit

    public function test_edit_can_add_and_remove_devices_and_the_history_follows(): void
    {
        $dealer = $this->makeDealer();
        [$a, $b] = $this->devices(2);
        $c = $this->device();
        $this->send($dealer, [$a, $b])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);

        $this->edit($ledger, $dealer, [$b->sim_number, $c->sim_number])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Transfer record updated successfully.');

        $this->assertNull($a->fresh()->dealer_id);                         // took out: back with the company
        $this->assertNull($a->fresh()->transfer_id);
        $this->assertNull($a->fresh()->allocated_at);
        $this->assertSame($dealer->id, $c->fresh()->dealer_id);            // put in
        $this->assertSame($ledger->id, $c->fresh()->transfer_id);
        $this->assertNotNull($c->fresh()->allocated_at);
        $this->assertSame(2, $ledger->fresh()->quantity);
        $this->assertEqualsCanonicalizing([$b->imei_number, $c->imei_number], DealerTransferItem::where('ledger_id', $ledger->id)->pluck('imei_number')->all());
    }

    public function test_edit_can_move_the_whole_transfer_to_another_dealer(): void
    {
        $first = $this->makeDealer();
        $second = $this->makeDealer();
        $devs = $this->devices(2);
        $this->send($first, $devs)->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($first);

        $this->edit($ledger, $second, $this->sims($devs))->assertSessionHasNoErrors();

        $this->assertSame($second->id, $ledger->fresh()->dealer_id);
        foreach ($devs as $d) {
            $this->assertSame($second->id, $d->fresh()->dealer_id);
        }
    }

    public function test_edit_is_audited_with_what_changed_and_which_sims_moved(): void
    {
        $first = $this->makeDealer();
        $second = $this->makeDealer();
        [$a, $b] = $this->devices(2);
        $c = $this->device();
        $this->send($first, [$a, $b])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($first);

        $this->edit($ledger, $second, [$b->sim_number, $c->sim_number])->assertSessionHasNoErrors();

        $audit = $this->audits('stock.transfer_edited')->where('subject_id', (string) $ledger->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->admin()->admin_id, $audit->actor_id);
        $this->assertEquals(['from' => $first->id, 'to' => $second->id], $audit->changes['dealer_id']);
        $this->assertSame([$a->sim_number], $audit->meta['removed_sims']);
        $this->assertSame([$c->sim_number], $audit->meta['added_sims']);
    }

    public function test_edit_cannot_add_a_device_of_another_category_or_one_already_taken_and_changes_nothing(): void
    {
        $dealer = $this->makeDealer();
        $rival = $this->makeDealer();
        [$a] = $this->devices(1);
        $this->send($dealer, [$a])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $otherCat = $this->device(['device_category' => 'AUTOTEST-STP-OTHER-' . $this->nextIntakeNumber()]);
        $taken = $this->device(['dealer_id' => $rival->id]);

        $this->edit($ledger, $dealer, [$a->sim_number, $otherCat->sim_number])->assertSessionHasErrors('transfer_edit');
        $this->edit($ledger, $dealer, [$a->sim_number, $taken->sim_number])->assertSessionHasErrors('transfer_edit');

        $this->assertSame(1, $ledger->fresh()->quantity);
        $this->assertSame($dealer->id, $a->fresh()->dealer_id);
        $this->assertSame($rival->id, $taken->fresh()->dealer_id);
        $this->assertNull($otherCat->fresh()->dealer_id);
    }

    public function test_a_refused_edit_keeps_the_removals_it_was_about_to_make(): void
    {
        $dealer = $this->makeDealer();
        [$a, $b] = $this->devices(2);
        $this->send($dealer, [$a, $b])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $notFree = $this->device(['status' => 'Not Activated']);

        // Drop $b and try to add a device that is not free: the whole edit must be refused, $b stays.
        $this->edit($ledger, $dealer, [$a->sim_number, $notFree->sim_number])->assertSessionHasErrors('transfer_edit');

        $this->assertSame($dealer->id, $b->fresh()->dealer_id);
        $this->assertSame($ledger->id, $b->fresh()->transfer_id);
        $this->assertSame(2, DealerTransferItem::where('ledger_id', $ledger->id)->count());
    }

    public static function badEdits(): array
    {
        return [
            'no dealer'          => [['dealer_id' => ''], 'dealer_id'],
            'unknown dealer'     => [['dealer_id' => 987654321], 'dealer_id'],
            'no sims'            => [['sim_numbers' => []], 'sim_numbers'],
            'sims not a list'    => [['sim_numbers' => 'abc'], 'sim_numbers'],
            'an empty sim entry' => [['sim_numbers' => ['']], 'sim_numbers.0'],
            'a sim too long'     => [['sim_numbers' => [str_repeat('1', 33)]], 'sim_numbers.0'],
            'too many sims'      => [['sim_numbers' => 'TOO-MANY'], 'sim_numbers'],
        ];
    }

    #[DataProvider('badEdits')]
    public function test_a_bad_edit_is_refused_and_nothing_changes(array $override, string $field): void
    {
        $dealer = $this->makeDealer();
        [$a] = $this->devices(1);
        $this->send($dealer, [$a])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $body = array_merge(['dealer_id' => $dealer->id, 'sim_numbers' => [$a->sim_number]], $override);
        if (($override['sim_numbers'] ?? null) === 'TOO-MANY') {
            $body['sim_numbers'] = array_map(fn ($i) => '0700' . $i, range(1, 1001));
        }

        $this->actingAs($this->admin())->from(route('admin.stock_transfer'))
            ->put(route('admin.stock_transfer.update', $ledger->id), $body)->assertSessionHasErrors($field);

        $this->assertSame($dealer->id, $a->fresh()->dealer_id);
        $this->assertSame(1, $ledger->fresh()->quantity);
    }

    public function test_an_inactive_dealer_cannot_receive_an_edited_transfer(): void
    {
        $dealer = $this->makeDealer();
        $off = $this->makeDealer(['status' => 'inactive']);
        [$a] = $this->devices(1);
        $this->send($dealer, [$a])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);

        $this->edit($ledger, $off, [$a->sim_number])->assertSessionHasErrors('dealer_id');

        $this->assertSame($dealer->id, $ledger->fresh()->dealer_id);
    }

    public function test_edit_for_a_missing_transfer_is_a_404(): void
    {
        $this->actingAs($this->admin())->put(route('admin.stock_transfer.update', 987654321), ['dealer_id' => $this->makeDealer()->id, 'sim_numbers' => ['0700000001']])->assertNotFound();
    }

    // ================================================================== Undo

    public function test_undo_gives_every_device_back_to_the_company_and_hides_the_history_row(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(3);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);

        $this->undo($ledger)->assertSessionHas('success', 'Transfer undone: 3 device(s) are back in company stock.');

        foreach ($devs as $d) {
            $row = $d->fresh();
            $this->assertNull($row->dealer_id);
            $this->assertNull($row->transfer_id);
            $this->assertNull($row->allocated_at);
            $this->assertSame('Activated', $row->status);
        }
        $this->assertNull(DealerTransferLedger::find($ledger->id));                       // hidden from the page
        $this->assertNotNull(DealerTransferLedger::withTrashed()->find($ledger->id));      // but kept, not erased
        $this->assertSame(0, DealerTransferItem::where('ledger_id', $ledger->id)->count());
    }

    public function test_after_an_undo_the_devices_can_be_sent_again(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(2);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $this->undo($this->ledgerOf($dealer))->assertSessionHasNoErrors();

        $this->send($this->makeDealer(), $devs)->assertSessionHasNoErrors();

        $this->assertNotNull($devs[0]->fresh()->dealer_id);
    }

    public function test_undo_is_audited_with_who_what_and_which_devices_came_back(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(2);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);

        $this->undo($ledger)->assertSessionHasNoErrors();

        $audit = $this->audits('ledger.transfer_deleted')->where('subject_id', (string) $ledger->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->admin()->admin_id, $audit->actor_id);
        $this->assertTrue($audit->meta['undone']);
        $this->assertEqualsCanonicalizing([$devs[0]->imei_number, $devs[1]->imei_number], $audit->meta['devices_returned']);
        $this->assertEquals(['from' => 2, 'to' => null], $audit->changes['quantity']);
    }

    public function test_undo_tells_the_app_server_each_device_is_back_with_the_company(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(2);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $this->sent = [];

        $this->undo($this->ledgerOf($dealer))->assertSessionHasNoErrors();

        $synced = array_filter($this->sent, fn ($r) => str_contains($r->url(), '/api/internal/setup-devices-sync'));
        $this->assertCount(2, $synced);
        foreach ($synced as $r) {
            $this->assertSame('autotest-sync-key', $r->header('X-Admin-Sync-Key')[0] ?? null);
        }
    }

    public function test_when_the_app_server_cannot_be_told_undo_still_works_and_the_person_is_warned(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(2);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $this->apiDown = true;

        $this->undo($ledger)->assertSessionHas('success')->assertSessionHas('warning');

        $this->assertStringContainsString('2 device(s) could not be synced', session('warning'));
        $this->assertStringContainsString('devices:backfill', session('warning'));
        $this->assertNull($devs[0]->fresh()->dealer_id);
        $this->assertNull(DealerTransferLedger::find($ledger->id));
    }

    public function test_undo_for_a_missing_transfer_is_a_404(): void
    {
        $this->actingAs($this->admin())->delete(route('admin.stock_transfer.destroy', 987654321))->assertNotFound();
    }

    public function test_undo_twice_the_second_time_is_a_404_and_changes_nothing(): void
    {
        $dealer = $this->makeDealer();
        $devs = $this->devices(1);
        $this->send($dealer, $devs)->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $this->undo($ledger)->assertSessionHasNoErrors();
        $audits = $this->audits('ledger.transfer_deleted')->count();

        $this->undo($ledger)->assertNotFound();

        $this->assertSame($audits, $this->audits('ledger.transfer_deleted')->count());
    }

    // ================================================================== locked transfers

    public static function movers(): array
    {
        return [
            'a device was passed on to another dealer' => ['passed'],
            'a device was sold to a customer'          => ['sold'],
            'a device was taken back by the company'   => ['returned'],
        ];
    }

    private function lockIt(string $how, SetupShalotrackDevice $device): void
    {
        $other = $this->makeDealer();
        // Written straight to the table: these columns are not mass-assignable on purpose.
        $change = match ($how) {
            'passed'   => ['dealer_id' => $other->id],
            'sold'     => ['assigned_customer_id' => \App\Models\DealerCustomerAd::create(['dealer_id' => $other->id, 'name' => 'Autotest Buyer', 'email' => 'autotest+buyer' . $this->nextIntakeNumber() . '@example.test', 'contact' => '0700000077'])->id],
            'returned' => ['dealer_id' => null],
        };
        SetupShalotrackDevice::where('shdevice_id', $device->shdevice_id)->update($change);
    }

    #[DataProvider('movers')]
    public function test_a_transfer_is_locked_once_a_device_has_left_the_receiver_so_edit_is_refused(string $how): void
    {
        $dealer = $this->makeDealer();
        [$a, $b] = $this->devices(2);
        $c = $this->device();
        $this->send($dealer, [$a, $b])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $this->lockIt($how, $a);
        $state = $a->fresh()->only(['dealer_id', 'assigned_customer_id', 'transfer_id']);

        $this->edit($ledger, $dealer, [$a->sim_number, $b->sim_number, $c->sim_number])->assertSessionHasErrors('transfer_edit');

        $this->assertStringContainsString('can no longer be edited', session('errors')->first('transfer_edit'));
        $this->assertSame($state, $a->fresh()->only(['dealer_id', 'assigned_customer_id', 'transfer_id']));
        $this->assertNull($c->fresh()->dealer_id);
        $this->assertSame(2, $ledger->fresh()->quantity);
    }

    #[DataProvider('movers')]
    public function test_a_transfer_is_locked_once_a_device_has_left_the_receiver_so_undo_is_refused(string $how): void
    {
        $dealer = $this->makeDealer();
        [$a, $b] = $this->devices(2);
        $this->send($dealer, [$a, $b])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $this->lockIt($how, $a);
        $audits = $this->audits('ledger.transfer_deleted')->count();

        $this->undo($ledger)->assertSessionHasErrors('transfer');

        $this->assertStringContainsString('cannot be undone', session('errors')->first('transfer'));
        $this->assertNotNull(DealerTransferLedger::find($ledger->id));
        $this->assertSame($dealer->id, $b->fresh()->dealer_id);                      // the others did not move either
        $this->assertSame($audits, $this->audits('ledger.transfer_deleted')->count());
    }

    public function test_a_transfer_sent_by_a_distributor_is_locked_for_edit_and_undo(): void
    {
        $dist = $this->makeDealer(['dealer_status' => 'distributor']);
        $dealer = $this->makeDealer(['distributor_id' => $dist->id]);
        [$a] = $this->devices(1);
        $this->send($dealer, [$a])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $ledger->update(['from_dealer_id' => $dist->id]);

        $this->edit($ledger->fresh(), $dealer, [$a->sim_number])->assertSessionHasErrors('transfer_edit');
        $this->undo($ledger->fresh())->assertSessionHasErrors('transfer');

        $this->assertSame($dealer->id, $a->fresh()->dealer_id);
        $this->assertNotNull(DealerTransferLedger::find($ledger->id));
    }

    // ================================================================== the PDF report

    public function test_the_report_is_a_real_pdf_with_data_and_without(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.stock_transfer.report'))->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('dealer_stock_transfer_report.pdf', (string) $res->headers->get('content-disposition'));

        $dealer = $this->makeDealer(['full_name' => '<b>Autotest Pdf Dealer</b>']);
        $this->send($dealer, $this->devices(2))->assertSessionHasNoErrors();

        $res2 = $this->get(route('admin.stock_transfer.report'))->assertOk();
        $this->assertStringStartsWith('%PDF', $res2->getContent());
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_use_any_stock_transfer_page(string $role): void
    {
        $dealer = $this->makeDealer();
        [$a] = $this->devices(1);
        // Set up straight in the database: a second login in one test would log the first one out.
        $ledger = DealerTransferLedger::create(['dealer_id' => $dealer->id, 'from_dealer_id' => null, 'device_category' => $this->category, 'quantity' => 1]);
        SetupShalotrackDevice::where('shdevice_id', $a->shdevice_id)->update(['dealer_id' => $dealer->id, 'transfer_id' => $ledger->id, 'allocated_at' => now()]);
        DealerTransferItem::create(['ledger_id' => $ledger->id, 'device_id' => $a->shdevice_id, 'imei_number' => $a->imei_number, 'sim_number' => $a->sim_number]);
        $me = $this->makeAdmin($role);

        $this->actingAs($me);
        $this->get(route('admin.stock_transfer'))->assertForbidden();
        $this->get(route('admin.stock_transfer.report'))->assertForbidden();
        $this->getJson(route('admin.stock_transfer.sim-numbers', $this->category))->assertForbidden();
        $this->getJson(route('admin.stock_transfer.edit-data', $ledger->id))->assertForbidden();
        $this->put(route('admin.stock_transfer.update', $ledger->id), ['dealer_id' => $dealer->id, 'sim_numbers' => [$a->sim_number]])->assertForbidden();
        $this->delete(route('admin.stock_transfer.destroy', $ledger->id))->assertForbidden();

        $this->assertNotNull(DealerTransferLedger::find($ledger->id));
        $this->assertSame($dealer->id, $a->fresh()->dealer_id);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_and_nothing_is_changed(): void
    {
        $dealer = $this->makeDealer();
        [$a] = $this->devices(1);
        $this->send($dealer, [$a])->assertSessionHasNoErrors();
        $ledger = $this->ledgerOf($dealer);
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        $this->get(route('admin.stock_transfer'))->assertRedirect();
        $this->delete(route('admin.stock_transfer.destroy', $ledger->id))->assertRedirect();

        $this->assertNotNull(DealerTransferLedger::find($ledger->id));
    }
}