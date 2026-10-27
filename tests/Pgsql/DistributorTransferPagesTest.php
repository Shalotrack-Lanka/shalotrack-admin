<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsDistributorData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * The distributor's transfer screens (the POST itself is in DistributorTransferTest):
 *   GET distributor.transfer          the form: own ready stock, active dealers of the network, ?dealer= preselect
 *   GET distributor.transfers         history with filters
 *   GET distributor.transfers.show    one transfer with its devices
 *   GET/PUT distributor.profile       the distributor's own details
 *
 * A distributor sees only the transfers it sent to its dealers and the ones the company sent to it. Bad filter
 * values are refused and ids that are not numbers or are far too big must give a plain 404, not a database error.
 * Runs on real Postgres in the autotest schema; every row is rolled back.
 */
class DistributorTransferPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsDistributorData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function get_(string $route, array $query = [], array $params = [])
    {
        return $this->actingAs($this->login)->get(route($route, $params + $query));
    }

    // ------------------------------------------------------------------ the transfer form

    public function test_the_form_offers_only_own_ready_stock_and_only_active_dealers_of_the_network(): void
    {
        $dist   = $this->distributor('Autotest Distributor D');
        $active = $this->dealerUnder($dist, 'Autotest Dealer Active');
        $old    = $this->dealerUnder($dist, 'Autotest Dealer Archived', 'archived');
        $other  = $this->dealerUnder($this->distributor('Autotest Distributor Z'), 'Autotest Dealer Foreign');

        $x1 = $this->device($dist, 'AUTOTEST-CAT-X');
        $x2 = $this->device($dist, 'AUTOTEST-CAT-X');
        $y1 = $this->device($dist, 'AUTOTEST-CAT-Y');
        $idle = $this->device($dist, 'AUTOTEST-CAT-X', 'Not Activated');
        $sold = $this->device($dist, 'AUTOTEST-CAT-X', null, $this->lead($dist));
        $dealersOwn = $this->device($active);
        $this->device($active);
        $foreign = $this->device($other);
        $this->asDistributor($dist);

        $r = $this->get_('distributor.transfer')->assertOk()
            ->assertSee($x1->imei_number)->assertSee($y1->imei_number)
            ->assertDontSee($idle->imei_number)->assertDontSee($sold->imei_number)
            ->assertDontSee($dealersOwn->imei_number)->assertDontSee($foreign->imei_number)
            ->assertSee('Autotest Dealer Active')
            ->assertDontSee('Autotest Dealer Archived')->assertDontSee('Autotest Dealer Foreign');

        $this->assertSame(3, $r->viewData('stockTotal'));
        $this->assertSame(['AUTOTEST-CAT-X' => 2, 'AUTOTEST-CAT-Y' => 1], array_map('intval', $r->viewData('stock')->map->count()->all()));
        $this->assertSame(2, (int) $r->viewData('dealerStock')[$active->id]);
        $this->assertSame([$active->id], $r->viewData('dealers')->pluck('id')->all());
    }

    public function test_the_form_opens_with_no_stock_and_no_dealers(): void
    {
        $this->asDistributor($this->distributor('Autotest Distributor Empty'));

        $r = $this->get_('distributor.transfer')->assertOk();

        $this->assertSame(0, $r->viewData('stockTotal'));
        $this->assertCount(0, $r->viewData('dealers'));
    }

    public function test_a_dealer_can_be_preselected_only_when_it_is_an_active_dealer_of_the_network(): void
    {
        $dist   = $this->distributor('Autotest Distributor D');
        $mine   = $this->dealerUnder($dist, 'Autotest Dealer Mine');
        $old    = $this->dealerUnder($dist, 'Autotest Dealer Archived', 'archived');
        $other  = $this->dealerUnder($this->distributor('Autotest Distributor Z'), 'Autotest Dealer Foreign');
        $this->asDistributor($dist);

        $this->assertSame($mine->id, $this->get_('distributor.transfer', ['dealer' => $mine->id])->assertOk()->viewData('preselected'));

        foreach ([$old->id, $other->id, '0', '-1', 'abc', '1e3', ' 5', '99999999999999999999', '9223372036854775807', ['1']] as $bad) {
            $this->assertNull($this->get_('distributor.transfer', ['dealer' => $bad])->assertOk()->viewData('preselected'), 'preselect must ignore ' . json_encode($bad));
        }
    }

    // ------------------------------------------------------------------ history

    /** Sent to dealer A on 5 Mar and 20 Mar, to dealer B on 10 Mar, received from the company on 1 Mar; noise around it. */
    private function historySet(): array
    {
        $dist = $this->distributor('Autotest Distributor D');
        $a = $this->dealerUnder($dist, 'Autotest Dealer A');
        $b = $this->dealerUnder($dist, 'Autotest Dealer B');
        $other = $this->distributor('Autotest Distributor Z');
        $otherDealer = $this->dealerUnder($other, 'Autotest Dealer Foreign');

        $rec  = $this->ledger($dist, null, 'AUTOTEST-CAT-X', 10, '2026-03-01 09:00:00');
        $s1   = $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 2, '2026-03-05 09:00:00');
        $s2   = $this->ledger($b, $dist, 'AUTOTEST-CAT-Y', 3, '2026-03-10 09:00:00');
        $s3   = $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 4, '2026-03-20 09:00:00');
        $foreignSent = $this->ledger($otherDealer, $other, 'AUTOTEST-CAT-X', 50, '2026-03-12 09:00:00');
        $foreignRec  = $this->ledger($other, null, 'AUTOTEST-CAT-X', 60, '2026-03-12 10:00:00');
        $companyToMyDealer = $this->ledger($a, null, 'AUTOTEST-CAT-X', 70, '2026-03-12 11:00:00');
        $strangerToMe = $this->ledger($dist, $otherDealer, 'AUTOTEST-CAT-X', 80, '2026-03-12 12:00:00');   // addressed to me, but not from the company
        $this->asDistributor($dist);

        return [$dist, $a, $b, $rec, $s1, $s2, $s3, [$foreignSent, $foreignRec, $companyToMyDealer, $strangerToMe]];
    }

    private function ids($response): array
    {
        return collect($response->viewData('transfers')->items())->pluck('id')->all();
    }

    public function test_history_shows_what_was_sent_and_received_newest_first_and_nothing_else(): void
    {
        [, , , $rec, $s1, $s2, $s3, $noise] = $this->historySet();

        $r = $this->get_('distributor.transfers')->assertOk();

        $this->assertSame([$s3->id, $s2->id, $s1->id, $rec->id], $this->ids($r));
        $this->assertSame(4, (int) $r->viewData('totals')->records);
        $this->assertSame(19, (int) $r->viewData('totals')->devices);
        foreach ($noise as $n) {
            $this->assertNotContains($n->id, $this->ids($r));
        }
    }

    public function test_history_filters_by_direction(): void
    {
        [, , , $rec, $s1, $s2, $s3] = $this->historySet();

        $sent = $this->get_('distributor.transfers', ['direction' => 'sent'])->assertOk();
        $this->assertSame([$s3->id, $s2->id, $s1->id], $this->ids($sent));
        $this->assertSame(9, (int) $sent->viewData('totals')->devices);

        $got = $this->get_('distributor.transfers', ['direction' => 'received'])->assertOk();
        $this->assertSame([$rec->id], $this->ids($got));

        $this->assertCount(4, $this->ids($this->get_('distributor.transfers', ['direction' => 'all'])->assertOk()));
    }

    public function test_history_filters_by_dealer_and_never_shows_another_networks_dealer(): void
    {
        [$dist, $a, $b, , $s1, $s2, $s3] = $this->historySet();
        $foreignDealer = $this->dealerUnder($this->distributor('Autotest Distributor Y'), 'Autotest Dealer Foreign Two');
        $this->ledger($foreignDealer, $this->distributor('Autotest Distributor X'), 'AUTOTEST-CAT-X', 1);

        $this->assertSame([$s3->id, $s1->id], $this->ids($this->get_('distributor.transfers', ['dealer_id' => $a->id])->assertOk()));
        $this->assertSame([$s2->id], $this->ids($this->get_('distributor.transfers', ['dealer_id' => $b->id])->assertOk()));
        $this->assertSame([], $this->ids($this->get_('distributor.transfers', ['dealer_id' => $foreignDealer->id])->assertOk()));
        $this->assertSame([], $this->ids($this->get_('distributor.transfers', ['dealer_id' => $dist->id])->assertOk()), 'the distributor itself is not one of its dealers');
    }

    public function test_history_filters_by_date_and_both_ends_are_included(): void
    {
        [, , , $rec, $s1, $s2, $s3] = $this->historySet();

        $this->assertSame([$s2->id, $s1->id], $this->ids($this->get_('distributor.transfers', ['from' => '2026-03-05', 'to' => '2026-03-10'])->assertOk()));
        $this->assertSame([$s3->id], $this->ids($this->get_('distributor.transfers', ['from' => '2026-03-11'])->assertOk()));
        $this->assertSame([$rec->id], $this->ids($this->get_('distributor.transfers', ['to' => '2026-03-04'])->assertOk()));
        $this->assertSame([], $this->ids($this->get_('distributor.transfers', ['from' => '2027-01-01'])->assertOk()));
        $this->assertSame([], $this->ids($this->get_('distributor.transfers', ['from' => '2026-03-10', 'to' => '2026-03-05'])->assertOk()), 'an upside-down range finds nothing');
    }

    public function test_filters_can_be_combined(): void
    {
        [, $a, , , , , $s3] = $this->historySet();

        $this->assertSame([$s3->id], $this->ids($this->get_('distributor.transfers', ['direction' => 'sent', 'dealer_id' => $a->id, 'from' => '2026-03-06'])->assertOk()));
    }

    public static function badHistoryFilters(): array
    {
        return [
            'unknown direction'  => [['direction' => 'all-of-them'], 'direction'],
            'direction as list'  => [['direction' => ['sent']], 'direction'],
            'dealer not a number' => [['dealer_id' => 'abc'], 'dealer_id'],
            'dealer as decimal'  => [['dealer_id' => '1.5'], 'dealer_id'],
            'dealer as list'     => [['dealer_id' => ['1']], 'dealer_id'],
            'dealer too big for any number' => [['dealer_id' => '99999999999999999999'], 'dealer_id'],
            'from not a date'    => [['from' => 'yesterday-ish'], 'from'],
            'from impossible day' => [['from' => '2026-02-30'], 'from'],
            'to not a date'      => [['to' => '31/31/2026'], 'to'],
            'to as list'         => [['to' => ['2026-03-01']], 'to'],
        ];
    }

    #[DataProvider('badHistoryFilters')]
    public function test_bad_history_filters_are_refused_with_a_message(array $query, string $field): void
    {
        $this->historySet();

        $this->get_('distributor.transfers', $query)->assertSessionHasErrors($field);
    }

    public function test_a_huge_dealer_number_that_is_still_a_valid_integer_gives_an_empty_list_not_an_error(): void
    {
        $this->historySet();

        foreach (['9223372036854775807', '4294967296', '2147483648'] as $id) {
            $this->assertSame([], $this->ids($this->get_('distributor.transfers', ['dealer_id' => $id])->assertOk()));
        }
    }

    public function test_history_is_paged_by_twenty_and_a_silly_page_cannot_crash_it(): void
    {
        $dist = $this->distributor('Autotest Distributor P');
        $dealer = $this->dealerUnder($dist, 'Autotest Dealer P');
        for ($i = 0; $i < 23; $i++) {
            $this->ledger($dealer, $dist, 'AUTOTEST-CAT-X', 1);
        }
        $this->asDistributor($dist);

        $this->assertCount(20, $this->ids($this->get_('distributor.transfers')->assertOk()));
        $this->assertCount(3, $this->ids($this->get_('distributor.transfers', ['page' => 2])->assertOk()));
        foreach (['0', '-1', 'abc', '9223372036854775807', '99999999999999999999999'] as $page) {
            $this->get_('distributor.transfers', ['page' => $page])->assertOk();
        }
    }

    public function test_a_deleted_transfer_disappears_from_history_and_its_page(): void
    {
        [, , , , $s1] = $this->historySet();
        $s1->delete();   // soft delete: the row is kept, the screens must not show it

        $this->assertNotContains($s1->id, $this->ids($this->get_('distributor.transfers')->assertOk()));
        $this->get_('distributor.transfers.show', [], ['ledger' => $s1->id])->assertNotFound();
    }

    // ------------------------------------------------------------------ one transfer

    public function test_a_sent_transfer_lists_its_devices_and_says_who_got_them(): void
    {
        $dist = $this->distributor('Autotest Distributor D');
        $a = $this->dealerUnder($dist, 'Autotest Dealer A');
        $d1 = $this->device($a); $d2 = $this->device($a);
        $ledger = $this->ledger($a, $dist, 'AUTOTEST-CAT-X', 2, null, [$d1, $d2]);
        $this->asDistributor($dist);

        $r = $this->get_('distributor.transfers.show', [], ['ledger' => $ledger->id])->assertOk()
            ->assertSee($d1->imei_number)->assertSee($d2->imei_number)->assertSee('Autotest Dealer A');

        $this->assertTrue($r->viewData('sentByMe'));
        $this->assertCount(2, $r->viewData('items'));
    }

    public function test_a_transfer_the_company_sent_to_the_distributor_can_be_opened_and_is_marked_received(): void
    {
        $dist = $this->distributor('Autotest Distributor D');
        $d1 = $this->device($dist);
        $ledger = $this->ledger($dist, null, 'AUTOTEST-CAT-X', 1, null, [$d1]);
        $this->asDistributor($dist);

        $r = $this->get_('distributor.transfers.show', [], ['ledger' => $ledger->id])->assertOk()->assertSee($d1->imei_number);

        $this->assertFalse($r->viewData('sentByMe'));
    }

    public function test_other_peoples_transfers_are_a_plain_404(): void
    {
        $dist = $this->distributor('Autotest Distributor D');
        $other = $this->distributor('Autotest Distributor Z');
        $theirDealer = $this->dealerUnder($other, 'Autotest Dealer Z');
        $myDealer = $this->dealerUnder($dist, 'Autotest Dealer Mine');
        $this->asDistributor($dist);

        $sentByOther      = $this->ledger($theirDealer, $other);
        $receivedByOther  = $this->ledger($other, null);
        $companyToMyDealer = $this->ledger($myDealer, null);   // not sent by this distributor and not received by it
        $strangerToMe      = $this->ledger($dist, $theirDealer);   // addressed to me but not sent by the company

        foreach ([$sentByOther, $receivedByOther, $companyToMyDealer, $strangerToMe] as $l) {
            $this->get_('distributor.transfers.show', [], ['ledger' => $l->id])->assertNotFound();
        }
    }

    public static function strangeLedgerIds(): array
    {
        return [
            'letters'        => ['abc'],
            'negative'       => ['-1'],
            'decimal'        => ['1.5'],
            'zero'           => ['0'],
            'does not exist' => ['987654321'],
            'above int4'     => ['4294967296'],
            'bigint maximum' => ['9223372036854775807'],
            'too many digits' => ['99999999999999999999999'],
            'sql text'       => ["1'%20OR%20'1'='1"],
        ];
    }

    #[DataProvider('strangeLedgerIds')]
    public function test_a_strange_transfer_number_is_a_404_and_never_a_server_error(string $id): void
    {
        $this->asDistributor($this->distributor('Autotest Distributor D'));

        $this->actingAs($this->login)->get('/distributor/transfers/' . $id)->assertNotFound();
    }

    // ------------------------------------------------------------------ profile

    private function profileLogin(array $over = []): Admin
    {
        return $this->login = $this->makeAdmin('DISTRIBUTOR', $over + ['dealer_id' => $this->distributor('Autotest Distributor D')->id]);
    }

    public function test_the_profile_page_opens_for_a_distributor(): void
    {
        $me = $this->profileLogin(['full_name' => 'Autotest Dist Person']);

        $this->get_('distributor.profile')->assertOk()->assertSee('Autotest Dist Person')->assertSee($me->email);
    }

    public function test_the_profile_page_opens_even_when_the_login_has_no_active_distributor(): void
    {
        $this->login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => null]);

        $this->get_('distributor.profile')->assertOk();   // only the business pages need a distributor; the person can still fix their own details
    }

    public function test_a_distributor_changes_its_own_name_email_and_phone_and_it_is_audited(): void
    {
        $me = $this->profileLogin();

        $this->actingAs($me)->put(route('distributor.profile.update'), [
            'full_name' => '  Autotest New Name ', 'email' => ' autotest+new-' . $me->admin_id . '@example.test ', 'phone_number' => '+94 77 123 4567',
        ])->assertSessionHasNoErrors('profile')->assertSessionHas('success', 'Your details were saved.');

        $me->refresh();
        $this->assertSame('Autotest New Name', $me->full_name);
        $this->assertSame('autotest+new-' . $me->admin_id . '@example.test', $me->email);
        $this->assertSame('+94 77 123 4567', $me->phone_number);
        $this->assertTrue(AuditLog::where('action', 'account.profile_updated')->where('subject_id', $me->admin_id)->exists());
    }

    public function test_saving_the_same_details_says_nothing_changed_and_writes_no_audit_row(): void
    {
        $me = $this->profileLogin();

        $this->actingAs($me)->put(route('distributor.profile.update'), [
            'full_name' => $me->full_name, 'email' => $me->email, 'phone_number' => '',
        ])->assertSessionHas('success', 'Nothing was changed.');

        $this->assertFalse(AuditLog::where('action', 'account.profile_updated')->where('subject_id', $me->admin_id)->exists());
    }

    public function test_the_role_the_link_and_the_username_cannot_be_changed_from_the_profile_form(): void
    {
        $me = $this->profileLogin();
        $other = $this->distributor('Autotest Distributor Z');
        $before = $me->only(['role', 'dealer_id', 'username', 'status', 'supplier_id']);

        $this->actingAs($me)->put(route('distributor.profile.update'), [
            'full_name' => 'Autotest Same', 'email' => $me->email,
            'role' => 'ADMIN', 'dealer_id' => $other->id, 'username' => 'autotest-hacker', 'status' => 'INACTIVE', 'supplier_id' => 1, 'password' => 'Hacked#12345',
        ]);

        $me->refresh();
        $this->assertSame($before, $me->only(['role', 'dealer_id', 'username', 'status', 'supplier_id']));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Autotest#12345', $me->password), 'the password cannot be changed here');
        $this->get_('distributor.dashboard')->assertOk();   // still a distributor of the same network
    }

    public static function badProfiles(): array
    {
        return [
            'no name'         => [['full_name' => ''], 'full_name'],
            'name too long'   => [['full_name' => str_repeat('n', 151)], 'full_name'],
            'name as list'    => [['full_name' => ['x']], 'full_name'],
            'no email'        => [['email' => ''], 'email'],
            'bad email'       => [['email' => 'not-an-email'], 'email'],
            'email too long'  => [['email' => str_repeat('a', 185) . '@example.test'], 'email'],
            'phone letters'   => [['phone_number' => 'call me'], 'phone_number'],
            'phone too short' => [['phone_number' => '12345'], 'phone_number'],
            'phone too long'  => [['phone_number' => str_repeat('1', 21)], 'phone_number'],
        ];
    }

    #[DataProvider('badProfiles')]
    public function test_bad_profile_details_are_refused_and_nothing_is_saved(array $bad, string $field): void
    {
        $me = $this->profileLogin(['full_name' => 'Autotest Keep Me']);

        $this->actingAs($me)->put(route('distributor.profile.update'), $bad + [
            'full_name' => 'Autotest Valid', 'email' => $me->email, 'phone_number' => '0771234567',
        ])->assertSessionHasErrorsIn('profile', $field);

        $this->assertSame('Autotest Keep Me', $me->fresh()->full_name);
    }

    public function test_an_email_already_used_by_someone_else_is_refused_even_in_other_letters(): void
    {
        $someone = $this->makeAdmin('DEALER', ['email' => 'autotest+taken@example.test']);
        $me = $this->profileLogin();

        $this->actingAs($me)->put(route('distributor.profile.update'), [
            'full_name' => 'Autotest Valid', 'email' => 'AUTOTEST+Taken@Example.test',
        ])->assertSessionHasErrorsIn('profile', 'email');

        $this->assertNotSame('AUTOTEST+Taken@Example.test', $me->fresh()->email);
    }

    public function test_a_dealer_login_cannot_use_the_distributor_profile_urls(): void
    {
        $this->login = $this->makeAdmin('DEALER');

        $this->get_('distributor.profile')->assertForbidden();
        $this->actingAs($this->login)->put(route('distributor.profile.update'), ['full_name' => 'Autotest X', 'email' => $this->login->email])->assertForbidden();
    }
}