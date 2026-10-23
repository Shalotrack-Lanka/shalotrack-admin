<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Dealer;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * A distributor passes devices from its OWN stock to one of ITS dealers.
 *   POST distributor.transfer.store
 *   GET  distributor.transfers.show
 *
 * The server must decide everything itself: the receiver has to be an active dealer of this distributor and
 * every device has to be in this distributor's own available stock, whatever ids the form sends.
 * Every row is rolled back by DatabaseTransactions; assertions look only at the tagged rows created here
 * (IMEI prefix 99900000 3xx).
 */
class DistributorTransferTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-CAT';

    /** One login per test: a second login mid-test has a different password hash and AuthenticateSession logs the session out. */
    private ?\App\Models\Admin $login = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function makeDistributor(string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Distributor ' . $suffix,
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+dist-{$suffix}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function makeDealerUnder(Dealer $distributor, string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'      => 'Autotest Dealer ' . $suffix,
            'dealer_status'  => 'retailer',
            'distributor_id' => $distributor->id,
            'region'         => 'colombo',
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+distdealer-{$suffix}@example.test",
            'status'         => 'active',
            'created_by'     => 'autotest',
        ]);
    }

    private function makeDevice(string $n, ?int $holderId, string $category = self::CATEGORY, ?string $status = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => $category,
            'imei_number'     => '99900000' . $n,
            'sim_number'      => '07200000' . $n,
            'iccid'           => '8994200000' . $n,
            'status'          => $status ?? DeviceStatus::Activated->value,
            'dealer_id'       => $holderId,
        ]);
    }

    private function send(Dealer $distributor, Dealer $receiver, array $deviceIds)
    {
        $this->login ??= $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]);

        return $this->actingAs($this->login)->post(route('distributor.transfer.store'), [
            'dealer_id'  => $receiver->id,
            'device_ids' => $deviceIds,
        ]);
    }

    public function test_a_distributor_passes_its_own_stock_to_its_own_dealer(): void
    {
        $dist   = $this->makeDistributor('a');
        $dealer = $this->makeDealerUnder($dist, 'a');
        $d1 = $this->makeDevice('301', $dist->id);
        $d2 = $this->makeDevice('302', $dist->id);

        $this->send($dist, $dealer, [$d1->shdevice_id, $d2->shdevice_id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($dealer->id, (int) $d1->fresh()->dealer_id);
        $this->assertSame($dealer->id, (int) $d2->fresh()->dealer_id);

        $ledger = DealerTransferLedger::where('dealer_id', $dealer->id)->where('from_dealer_id', $dist->id)->first();
        $this->assertNotNull($ledger, 'transfer record missing');
        $this->assertSame(2, (int) $ledger->quantity);
        $this->assertSame(self::CATEGORY, $ledger->device_category);
        $this->assertSame(2, DealerTransferItem::where('ledger_id', $ledger->id)->count());
    }

    public function test_devices_of_two_categories_make_one_transfer_record_each(): void
    {
        $dist   = $this->makeDistributor('b');
        $dealer = $this->makeDealerUnder($dist, 'b');
        $d1 = $this->makeDevice('303', $dist->id, 'AUTOTEST-CAT-A');
        $d2 = $this->makeDevice('304', $dist->id, 'AUTOTEST-CAT-B');

        $this->send($dist, $dealer, [$d1->shdevice_id, $d2->shdevice_id])->assertSessionHasNoErrors();

        $this->assertSame(2, DealerTransferLedger::where('dealer_id', $dealer->id)->where('from_dealer_id', $dist->id)->count());
    }

    public function test_a_device_that_belongs_to_another_distributor_cannot_be_moved(): void
    {
        $mine   = $this->makeDistributor('c');
        $other  = $this->makeDistributor('d');
        $dealer = $this->makeDealerUnder($mine, 'c');
        $foreign = $this->makeDevice('305', $other->id);

        $this->send($mine, $dealer, [$foreign->shdevice_id])->assertSessionHasErrors('transfer');

        $this->assertSame($other->id, (int) $foreign->fresh()->dealer_id, 'the other distributor keeps its device');
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $dealer->id)->count());
    }

    public function test_the_receiver_must_be_a_dealer_of_this_distributor(): void
    {
        $mine        = $this->makeDistributor('e');
        $other       = $this->makeDistributor('f');
        $strangerDealer = $this->makeDealerUnder($other, 'f');
        $device      = $this->makeDevice('306', $mine->id);

        // Looks exactly like "not found": nothing about other networks is revealed.
        $this->send($mine, $strangerDealer, [$device->shdevice_id])->assertNotFound();

        $this->assertSame($mine->id, (int) $device->fresh()->dealer_id, 'the device stays in the distributor stock');
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $strangerDealer->id)->count());
    }

    public function test_a_device_that_is_not_available_stock_cannot_be_passed_on(): void
    {
        $dist   = $this->makeDistributor('g');
        $dealer = $this->makeDealerUnder($dist, 'g');
        $sold   = $this->makeDevice('307', $dist->id, self::CATEGORY, DeviceStatus::AssignedToCustomer->value);

        $this->send($dist, $dealer, [$sold->shdevice_id])->assertSessionHasErrors('transfer');

        $this->assertSame($dist->id, (int) $sold->fresh()->dealer_id);
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $dealer->id)->count());
    }

    public function test_one_bad_device_stops_the_whole_transfer(): void
    {
        $dist   = $this->makeDistributor('h');
        $dealer = $this->makeDealerUnder($dist, 'h');
        $good   = $this->makeDevice('308', $dist->id);
        $bad    = $this->makeDevice('309', $dist->id, self::CATEGORY, DeviceStatus::AssignedToCustomer->value);

        $this->send($dist, $dealer, [$good->shdevice_id, $bad->shdevice_id])->assertSessionHasErrors('transfer');

        $this->assertSame($dist->id, (int) $good->fresh()->dealer_id, 'nothing moves when any device is refused');
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $dealer->id)->count());
    }

    public function test_an_empty_or_repeated_device_list_is_rejected(): void
    {
        $dist   = $this->makeDistributor('i');
        $dealer = $this->makeDealerUnder($dist, 'i');
        $device = $this->makeDevice('310', $dist->id);

        $this->send($dist, $dealer, [])->assertSessionHasErrors('device_ids');

        $this->send($dist, $dealer, [$device->shdevice_id, $device->shdevice_id])->assertSessionHasErrors();

        $this->assertSame($dist->id, (int) $device->fresh()->dealer_id);
    }

    public function test_a_distributor_login_not_linked_to_an_active_distributor_is_refused(): void
    {
        $dist   = $this->makeDistributor('j');
        $dealer = $this->makeDealerUnder($dist, 'j');
        $device = $this->makeDevice('311', $dist->id);

        $login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => null]);

        $this->actingAs($login)->post(route('distributor.transfer.store'), [
            'dealer_id'  => $dealer->id,
            'device_ids' => [$device->shdevice_id],
        ])->assertForbidden();

        $this->assertSame($dist->id, (int) $device->fresh()->dealer_id);
    }

    public function test_a_dealer_login_cannot_use_the_distributor_transfer(): void
    {
        $dist   = $this->makeDistributor('k');
        $dealer = $this->makeDealerUnder($dist, 'k');
        $device = $this->makeDevice('312', $dist->id);

        $login = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);

        $status = $this->actingAs($login)->post(route('distributor.transfer.store'), [
            'dealer_id'  => $dealer->id,
            'device_ids' => [$device->shdevice_id],
        ])->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertSame($dist->id, (int) $device->fresh()->dealer_id);
    }

    public function test_a_distributor_cannot_open_a_transfer_between_other_people(): void
    {
        $mine   = $this->makeDistributor('l');
        $other  = $this->makeDistributor('m');
        $dealer = $this->makeDealerUnder($other, 'm');

        $ledger = DealerTransferLedger::create([
            'dealer_id'           => $dealer->id,
            'from_dealer_id'      => $other->id,
            'device_category'     => self::CATEGORY,
            'quantity'            => 1,
            'created_by_admin_id' => 'autotest',
        ]);

        $login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $mine->id]);

        $this->actingAs($login)->get(route('distributor.transfers.show', $ledger->id))->assertNotFound();
    }
}