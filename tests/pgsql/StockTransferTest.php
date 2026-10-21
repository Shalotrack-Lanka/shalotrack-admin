<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Admin > Stock Transfer (POST admin.stock_transfer.store).
 * Business rule under test: only ACTIVATED, unsold, SIM-bearing company stock can go to a dealer,
 * and a device can never be given to two dealers. Rolled back after every test; assertions use
 * only the tagged rows created here (category "AUTOTEST-CAT"), never table totals.
 */
class StockTransferTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-CAT';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function makeDealer(string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Dealer ' . $suffix,
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+stock-{$suffix}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function makeDevice(string $n, ?string $status = null, ?int $dealerId = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => self::CATEGORY,
            'imei_number'     => '99900000000' . $n,
            'sim_number'      => '07000000' . $n,
            'iccid'           => '8994000000000' . $n,
            'status'          => $status ?? DeviceStatus::Activated->value,
            'dealer_id'       => $dealerId,
        ]);
    }

    private function payload(Dealer $dealer, array $sims): array
    {
        return [
            'device_category' => self::CATEGORY,
            'dealer_id'       => $dealer->id,
            'sim_numbers'     => $sims,
        ];
    }

    public function test_activated_company_stock_is_transferred_to_the_dealer(): void
    {
        $admin  = $this->makeAdmin('ADMIN');
        $dealer = $this->makeDealer('a');
        $d1 = $this->makeDevice('11');
        $d2 = $this->makeDevice('12');

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), $this->payload($dealer, [$d1->sim_number, $d2->sim_number]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $ledger = DealerTransferLedger::where('dealer_id', $dealer->id)->where('device_category', self::CATEGORY)->first();
        $this->assertNotNull($ledger, 'transfer ledger row missing');
        $this->assertSame(2, (int) $ledger->quantity);

        foreach ([$d1, $d2] as $d) {
            $d->refresh();
            $this->assertSame($dealer->id, (int) $d->dealer_id);
            $this->assertSame($ledger->id, (int) $d->transfer_id);
            $this->assertNotNull($d->allocated_at);
        }
    }

    public function test_a_not_activated_device_cannot_be_transferred(): void
    {
        $admin  = $this->makeAdmin('ADMIN');
        $dealer = $this->makeDealer('b');
        $d      = $this->makeDevice('21', DeviceStatus::NotActivated->value);

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), $this->payload($dealer, [$d->sim_number]))
            ->assertSessionHasErrors('transfer');

        $this->assertNull($d->refresh()->dealer_id);
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $dealer->id)->count());
    }

    public function test_a_device_already_with_another_dealer_cannot_be_given_to_a_second_dealer(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $first   = $this->makeDealer('c1');
        $second  = $this->makeDealer('c2');
        $d       = $this->makeDevice('31', null, $first->id);

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), $this->payload($second, [$d->sim_number]))
            ->assertSessionHasErrors('transfer');

        $this->assertSame($first->id, (int) $d->refresh()->dealer_id);
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $second->id)->count());
    }

    public function test_one_unavailable_sim_stops_the_whole_transfer(): void
    {
        $admin  = $this->makeAdmin('ADMIN');
        $dealer = $this->makeDealer('d');
        $good   = $this->makeDevice('41');

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), $this->payload($dealer, [$good->sim_number, '0799999999']))
            ->assertSessionHasErrors('transfer');

        $this->assertNull($good->refresh()->dealer_id, 'a partial transfer must not happen');
        $this->assertSame(0, DealerTransferLedger::where('dealer_id', $dealer->id)->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), [])
            ->assertSessionHasErrors(['device_category', 'dealer_id', 'sim_numbers']);
    }

    public function test_unknown_dealer_is_rejected(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $d     = $this->makeDevice('51');

        $this->actingAs($admin)
            ->post(route('admin.stock_transfer.store'), [
                'device_category' => self::CATEGORY,
                'dealer_id'       => 999999999,
                'sim_numbers'     => [$d->sim_number],
            ])
            ->assertSessionHasErrors('dealer_id');

        $this->assertNull($d->refresh()->dealer_id);
    }

    public function test_a_dealer_login_cannot_transfer_stock(): void
    {
        $dealerLogin = $this->makeAdmin('DEALER');
        $dealer      = $this->makeDealer('e');
        $d           = $this->makeDevice('61');

        $status = $this->actingAs($dealerLogin)
            ->post(route('admin.stock_transfer.store'), $this->payload($dealer, [$d->sim_number]))
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertNull($d->refresh()->dealer_id);
    }
}