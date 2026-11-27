<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\BillingParty;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Two things that had no test through the real screens, on real PostgreSQL (autotest schema).
 *
 * 1. BANK SLIP UPLOAD through the Customer Device Management forms (activate and edit). Slips are payment
 *    documents, so every new slip must go to the PRIVATE disk and never to the public one, an old slip
 *    must be removed from wherever it is (private or the old public disk) when it is replaced, and a bad
 *    file (not an image, too big, an SVG that can carry scripts) must be refused and store nothing.
 *
 * 2. BILLING DETAILS OF A DEALER (PUT admin.billing.party.update): the name, address, tax number, phone and
 *    email printed on invoices; admin only, validated and written to the audit log.
 *
 * Files go to fake disks. Everything is rolled back after each test.
 */
class BankSlipFormsAndBillingPartyPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Storage::fake('public');
        Storage::fake('private_slips');
    }

    // ------------------------------------------------------------------ helpers

    private function vehicle(): VehicleAd
    {
        $n = $this->nextIntakeNumber();

        return VehicleAd::create([
            'vehicle_id' => (string) Str::uuid(), 'customer_id' => (string) Str::uuid(),
            'customer_name' => "Autotest Slip Customer {$n}", 'vehicle_number' => "AUTO-SLIP-{$n}",
            'model' => 'Autotest Model', 'has_gps_device' => false,
        ]);
    }

    private function device(): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        return SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(),
            'sim_number' => '07' . str_pad((string) $n, 9, '2', STR_PAD_LEFT),
            'status' => DeviceStatus::Activated->value,
        ]);
    }

    private function payload(SetupShalotrackDevice $device, array $over = []): array
    {
        return $over + [
            'imei_number' => $device->imei_number, 'sim_number' => $device->sim_number,
            'device_category' => $device->device_category, 'payment_status' => 'not-Paid',
        ];
    }

    private ?Admin $admin = null;

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    /** Binds a device with a first slip, as an admin would, and returns the stored row. */
    private function boundWithSlip(): ActivatedDevice
    {
        $vehicle = $this->vehicle();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device, ['bank_slip' => UploadedFile::fake()->image('first.jpg')]))
            ->assertSessionHasNoErrors();

        return ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->firstOrFail();
    }

    private function edit(ActivatedDevice $bound, array $extra = [])
    {
        return $this->actingAs($this->admin())->patch(route('admin.customer-device-management.update', $bound->activated_device_id), [
            'imei_number' => $bound->imei_number, 'sim_number' => $bound->sim_number,
            'device_category' => $bound->device_category, 'payment_status' => 'not-Paid',
        ] + $extra);
    }

    // ================================================================== bank slips through the forms

    public function test_a_slip_uploaded_while_activating_is_saved_on_the_private_disk_only(): void
    {
        $bound = $this->boundWithSlip();

        $this->assertStringStartsWith('bank_slips/', (string) $bound->bank_slip);
        Storage::disk('private_slips')->assertExists($bound->bank_slip);
        Storage::disk('public')->assertMissing($bound->bank_slip);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing may reach the public disk');
    }

    public function test_the_slip_saved_by_the_form_can_be_opened_by_an_admin_with_the_download_route(): void
    {
        $bound = $this->boundWithSlip();

        $this->actingAs($this->admin())
            ->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_replacing_a_slip_removes_the_old_private_file_and_keeps_only_the_new_one(): void
    {
        $bound = $this->boundWithSlip();
        $old = $bound->bank_slip;

        $this->edit($bound, ['bank_slip' => UploadedFile::fake()->image('second.png')])->assertSessionHasNoErrors();

        $new = $bound->fresh()->bank_slip;
        $this->assertNotSame($old, $new);
        Storage::disk('private_slips')->assertMissing($old);
        Storage::disk('private_slips')->assertExists($new);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_an_old_slip_that_is_still_on_the_public_disk_deletes_it_from_there(): void
    {
        $bound = $this->boundWithSlip();
        // The state before the move command ran: the slip sits on the public disk only.
        Storage::disk('private_slips')->delete($bound->bank_slip);
        Storage::disk('public')->put($bound->bank_slip, 'LEGACY-SLIP');

        $this->edit($bound, ['bank_slip' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($bound->bank_slip);
        Storage::disk('private_slips')->assertExists($bound->fresh()->bank_slip);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editing_without_a_new_file_keeps_the_slip(): void
    {
        $bound = $this->boundWithSlip();
        $path = $bound->bank_slip;

        $this->edit($bound, ['install_notes' => 'Autotest note'])->assertSessionHasNoErrors();

        $this->assertSame($path, $bound->fresh()->bank_slip);
        Storage::disk('private_slips')->assertExists($path);
    }

    public function test_replacing_a_slip_is_written_to_the_audit_log_without_the_file_path(): void
    {
        $bound = $this->boundWithSlip();

        $this->edit($bound, ['bank_slip' => UploadedFile::fake()->image('again.jpg')])->assertSessionHasNoErrors();

        $audit = AuditLog::where('action', 'subscription.updated')->where('subject_id', $bound->vehicle_id)->first();
        $this->assertNotNull($audit, 'the slip replacement must be audited');
        $this->assertStringContainsString('bank_slip_replaced', json_encode($audit->details ?? $audit->meta ?? $audit->toArray()));
    }

    public static function badFiles(): array
    {
        return [
            'a text file'   => [fn () => UploadedFile::fake()->createWithContent('slip.txt', 'not a picture'), 'text file'],
            'a PHP file'    => [fn () => UploadedFile::fake()->createWithContent('slip.php', '<?php echo 1;'), 'PHP file'],
            'an SVG'        => [fn () => UploadedFile::fake()->createWithContent('slip.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG'],
            'too big (3 MB)' => [fn () => UploadedFile::fake()->image('big.jpg')->size(3072), '3 MB image'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_a_bad_file_is_refused_when_activating_and_nothing_is_stored(callable $file, string $label): void
    {
        $vehicle = $this->vehicle();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device, ['bank_slip' => $file()]))
            ->assertSessionHasErrors('bank_slip');

        $this->assertNull(ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first(), "{$label} must not activate the device");
        $this->assertSame([], Storage::disk('private_slips')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_a_bad_file_is_refused_when_editing_and_the_old_slip_stays(callable $file, string $label): void
    {
        $bound = $this->boundWithSlip();
        $path = $bound->bank_slip;

        $this->edit($bound, ['bank_slip' => $file()])->assertSessionHasErrors('bank_slip');

        $this->assertSame($path, $bound->fresh()->bank_slip, "{$label} must not replace the slip");
        $this->assertSame([$path], Storage::disk('private_slips')->allFiles());
    }

    // ================================================================== billing details of a dealer

    private function partyForm(array $over = []): array
    {
        return $over + [
            'legal_name' => 'Autotest Legal Name (Pvt) Ltd', 'address' => '12 Autotest Road, Colombo',
            'tax_number' => 'VAT-AUTOTEST-1', 'phone' => '0112345678', 'email' => 'billing@example.test',
        ];
    }

    public function test_admin_saves_a_dealers_billing_details_and_the_change_is_audited(): void
    {
        $dealer = $this->makeDealer();

        $this->actingAs($this->admin())
            ->put(route('admin.billing.party.update', $dealer->id), $this->partyForm())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $party = BillingParty::where('dealer_id', $dealer->id)->firstOrFail();
        $this->assertSame('Autotest Legal Name (Pvt) Ltd', $party->legal_name);
        $this->assertSame('12 Autotest Road, Colombo', $party->address);
        $this->assertSame('VAT-AUTOTEST-1', $party->tax_number);
        $this->assertSame('0112345678', $party->phone);
        $this->assertSame('billing@example.test', $party->email);

        $audit = AuditLog::where('action', 'billing.party_updated')->where('subject_id', $party->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($dealer->full_name, $audit->subject_label);
    }

    public function test_saving_again_updates_the_same_party_and_does_not_create_a_second_one(): void
    {
        $dealer = $this->makeDealer();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.billing.party.update', $dealer->id), $this->partyForm())->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.billing.party.update', $dealer->id), $this->partyForm(['legal_name' => 'Autotest Renamed Ltd']))->assertSessionHasNoErrors();

        $this->assertSame(1, BillingParty::where('dealer_id', $dealer->id)->count());
        $this->assertSame('Autotest Renamed Ltd', BillingParty::where('dealer_id', $dealer->id)->value('legal_name'));
    }

    public static function badPartyForms(): array
    {
        return [
            'no legal name'     => [['legal_name' => ''], 'legal_name'],
            'name over 200'     => [['legal_name' => str_repeat('n', 201)], 'legal_name'],
            'address over 500'  => [['address' => str_repeat('a', 501)], 'address'],
            'tax number over 60' => [['tax_number' => str_repeat('t', 61)], 'tax_number'],
            'phone over 40'     => [['phone' => str_repeat('1', 41)], 'phone'],
            'email not valid'   => [['email' => 'not-an-email'], 'email'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPartyForms')]
    public function test_bad_billing_details_are_refused_and_nothing_changes(array $over, string $field): void
    {
        $dealer = $this->makeDealer();
        $before = AuditLog::where('action', 'billing.party_updated')->count();

        $this->actingAs($this->admin())
            ->put(route('admin.billing.party.update', $dealer->id), $this->partyForm($over))
            ->assertSessionHasErrors($field);

        $this->assertSame($before, AuditLog::where('action', 'billing.party_updated')->count());
        $this->assertNull(BillingParty::where('dealer_id', $dealer->id)->where('legal_name', 'Autotest Legal Name (Pvt) Ltd')->first());
    }

    public static function notAllowed(): array
    {
        return ['dealer' => ['DEALER'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER'], 'distributor' => ['DISTRIBUTOR']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAllowed')]
    public function test_nobody_but_an_admin_can_change_billing_details(string $role): void
    {
        $dealer = $this->makeDealer();
        $login = $this->makeAdmin($role, ['dealer_id' => $dealer->id]);

        $this->actingAs($login)->put(route('admin.billing.party.update', $dealer->id), $this->partyForm())->assertForbidden();

        $this->assertSame(0, BillingParty::where('dealer_id', $dealer->id)->where('legal_name', 'Autotest Legal Name (Pvt) Ltd')->count());
    }

    public function test_a_visitor_is_sent_to_the_login_page(): void
    {
        $dealer = $this->makeDealer();

        $this->put(route('admin.billing.party.update', $dealer->id), $this->partyForm())->assertRedirect(route('login'));
    }
}
