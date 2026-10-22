<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\BillingParty;
use App\Models\Dealer;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\SetupShalotrackDevice;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Billing through the WEB PAGES, on real PostgreSQL (autotest schema):
 *   - who may open the admin billing pages (only ADMIN) and who may change anything,
 *   - the admin forms refuse bad input and never double-save,
 *   - a dealer sees only its own billing, a distributor only its own and its own dealers' (everything else is a 404).
 *
 * Documents are created through the Billing service (already proven in BillingFlowTest), then the pages are
 * called as a logged-in user. ONE login is used per test (a second login mid-test is logged out by AuthenticateSession).
 * Every row is rolled back by DatabaseTransactions.
 */
class BillingAccessTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-GT06';

    private Billing $billing;
    private Admin $maker;
    private int $imeiSeq = 500;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        $details = [
            'legal_name' => 'AUTOTEST Shalotrack Lanka (Pvt) Ltd',
            'address'    => '1 Autotest Road, Colombo',
            'tax_number' => 'TIN-AUTOTEST',
            'bank_details' => 'Autotest Bank 000111',
        ];
        $company ? $company->forceFill($details)->save() : BillingParty::create(['kind' => BillingParty::KIND_COMPANY] + $details);

        $this->billing = app(Billing::class);
        $this->maker   = $this->makeAdmin('ADMIN');
        $this->actingAs($this->maker);
    }

    // ------------------------------------------------------------------ helpers

    private function makeDealer(string $name, ?Dealer $distributor = null, string $type = 'retailer', string $status = 'active'): Dealer
    {
        $n = ++$this->counter;

        return Dealer::create([
            'full_name'      => $name,
            'dealer_status'  => $type,
            'distributor_id' => $distributor?->id,
            'region'         => 'colombo',
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+access-{$n}@example.test",
            'status'         => $status,
            'created_by'     => 'autotest',
        ]);
    }

    private function makeDistributor(string $name, string $status = 'active'): Dealer
    {
        return $this->makeDealer($name, null, 'distributor', $status);
    }

    private function ledger(Dealer $to, int $qty = 1): DealerTransferLedger
    {
        $ledger = DealerTransferLedger::create([
            'dealer_id' => $to->id, 'from_dealer_id' => null, 'device_category' => self::CATEGORY,
            'quantity' => $qty, 'created_by_admin_id' => 'autotest',
        ]);

        for ($i = 0; $i < $qty; $i++) {
            $n = (string) $this->imeiSeq++;
            $device = SetupShalotrackDevice::create([
                'device_category' => self::CATEGORY, 'imei_number' => '99900000' . $n, 'sim_number' => '07200000' . $n,
                'iccid' => '8994200000' . $n, 'status' => DeviceStatus::Activated->value, 'dealer_id' => $to->id,
            ]);
            DealerTransferItem::create([
                'ledger_id' => $ledger->id, 'device_id' => $device->shdevice_id,
                'imei_number' => $device->imei_number, 'sim_number' => $device->sim_number,
            ]);
        }

        return $ledger;
    }

    /** An issued invoice for a buyer (through the service, as the maker). */
    private function invoiceFor(Dealer $buyer, string $price = '1000.00'): Invoice
    {
        $ledger = $this->ledger($buyer);

        return $this->billing->issueInvoice($buyer, [$ledger->id => $price]);
    }

    private function today(): string
    {
        return now('Asia/Colombo')->toDateString();
    }

    /** Become somebody else for the rest of the test (no password-hash problem: no request has been made yet). */
    private function switchTo(Admin $admin): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($admin);
    }

    private function loginFor(string $role): Admin
    {
        return match ($role) {
            'DEALER'      => $this->makeAdmin('DEALER', ['dealer_id' => $this->makeDealer('Autotest Own Dealer')->id]),
            'DISTRIBUTOR' => $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $this->makeDistributor('Autotest Own Distributor')->id]),
            default       => $this->makeAdmin($role),
        };
    }

    public static function blockedRoles(): array
    {
        return ['a dealer login' => ['DEALER'], 'a distributor login' => ['DISTRIBUTOR'], 'a finance login' => ['FINANCE']];
    }

    // ------------------------------------------------------------------ not an admin: no access at all

    #[DataProvider('blockedRoles')]
    public function test_a_non_admin_login_cannot_open_any_admin_billing_page(string $role): void
    {
        $buyer   = $this->makeDealer('Autotest Buyer');
        $invoice = $this->invoiceFor($buyer);

        $this->switchTo($this->loginFor($role));

        $pages = [
            route('admin.billing.home'),
            route('admin.billing.invoices.index'),
            route('admin.billing.invoices.new'),
            route('admin.billing.invoices.show', $invoice),
            route('admin.billing.invoices.pdf', $invoice),
            route('admin.billing.invoices.credit', $invoice),
            route('admin.billing.receipts.index'),
            route('admin.billing.receipts.new'),
            route('admin.billing.accounts'),
            route('admin.billing.statement', $buyer),
            route('admin.billing.statement.csv', $buyer),
            route('admin.billing.settings'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    #[DataProvider('blockedRoles')]
    public function test_a_non_admin_login_cannot_change_anything_in_billing(string $role): void
    {
        $buyer   = $this->makeDealer('Autotest Buyer');
        $invoice = $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '100.00', 'cash', null, $this->today());
        $ledger  = $this->ledger($buyer);

        $this->switchTo($this->loginFor($role));

        // This route is limited to 6 requests a minute, and the limiter counts EVERY limited request of the same
        // login (not per page) and runs before the permission check. So it goes first: later it would answer 429.
        $this->post(route('admin.billing.verify'))->assertForbidden();

        $this->post(route('admin.billing.invoices.store'), [
            'dealer_id' => $buyer->id, 'prices' => [$ledger->id => '10.00'], 'token' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->post(route('admin.billing.invoices.review'), ['dealer_id' => $buyer->id, 'prices' => [$ledger->id => '10.00']])->assertForbidden();
        $this->post(route('admin.billing.invoices.credit.store', $invoice), ['reason' => 'x', 'qty' => [1]])->assertForbidden();
        $this->post(route('admin.billing.receipts.store'), [
            'dealer_id' => $buyer->id, 'amount' => '10.00', 'method' => 'cash', 'received_date' => $this->today(), 'token' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->post(route('admin.billing.receipts.approve', $receipt))->assertForbidden();
        $this->post(route('admin.billing.receipts.reject', $receipt), ['reason' => 'x'])->assertForbidden();
        $this->post(route('admin.billing.receipts.reverse', $receipt), ['reason' => 'x'])->assertForbidden();
        $this->put(route('admin.billing.settings.update'), ['legal_name' => 'HACKED', 'address' => 'x', 'payment_terms_days' => 1])->assertForbidden();
        $this->put(route('admin.billing.party.update', $buyer), ['legal_name' => 'HACKED'])->assertForbidden();

        // Nothing changed.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame('valid', $receipt->fresh()->status, 'the payment must not be reversed');
        $this->assertSame('AUTOTEST Shalotrack Lanka (Pvt) Ltd', BillingParty::where('kind', 'company')->value('legal_name'));
        $this->assertNotSame('HACKED', BillingParty::where('dealer_id', $buyer->id)->value('legal_name'));
    }

    public function test_a_person_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('admin.billing.home'))->assertRedirect(route('login'));
        $this->get(route('dealer.billing.index'))->assertRedirect(route('login'));
        $this->get(route('distributor.billing.index'))->assertRedirect(route('login'));
        $this->post(route('admin.billing.invoices.store'), [])->assertRedirect(route('login'));
        $this->post(route('admin.billing.receipts.store'), [])->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ admin: pages and forms

    public function test_an_admin_can_open_the_billing_pages(): void
    {
        $buyer   = $this->makeDealer('Autotest Buyer');
        $invoice = $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '100.00', 'cash', null, $this->today());

        $this->get(route('admin.billing.home'))->assertOk();
        $this->get(route('admin.billing.invoices.index'))->assertOk();
        $this->get(route('admin.billing.invoices.new', ['dealer' => $buyer->id]))->assertOk();
        $this->get(route('admin.billing.invoices.show', $invoice))->assertOk()->assertSee($invoice->number);
        $this->get(route('admin.billing.receipts.index'))->assertOk();
        $this->get(route('admin.billing.receipts.new', ['dealer' => $buyer->id]))->assertOk();
        $this->get(route('admin.billing.receipts.show', $receipt))->assertOk()->assertSee($receipt->number);
        $this->get(route('admin.billing.statement', $buyer))->assertOk();
        $this->get(route('admin.billing.settings'))->assertOk();
        $this->get(route('admin.billing.accounts'))->assertRedirect(route('admin.billing.home'));

        // A date that is not Y-m-d is refused, not guessed.
        $this->get(route('admin.billing.statement', [$buyer, 'from' => '31-12-2026']))->assertSessionHasErrors('from');
    }

    public function test_an_admin_issues_an_invoice_through_the_page_and_a_double_click_makes_only_one(): void
    {
        $buyer  = $this->makeDealer('Autotest Buyer');
        $ledger = $this->ledger($buyer, 2);
        $token  = (string) Str::uuid();
        $form   = ['dealer_id' => $buyer->id, 'prices' => [$ledger->id => '1250.50'], 'token' => $token];

        $this->post(route('admin.billing.invoices.store'), $form)->assertSessionHasNoErrors();

        $invoice = Invoice::where('buyer_dealer_id', $buyer->id)->first();
        $this->assertNotNull($invoice, 'the invoice was not created');
        $this->assertSame(250100, (int) round(((float) $invoice->fresh()->total) * 100));

        $this->post(route('admin.billing.invoices.store'), $form)->assertRedirect(route('admin.billing.invoices.show', $invoice));
        $this->assertSame(1, Invoice::where('buyer_dealer_id', $buyer->id)->count(), 'the same form sent twice makes one invoice');
    }

    public function test_the_invoice_form_refuses_bad_input(): void
    {
        $buyer  = $this->makeDealer('Autotest Buyer');
        $other  = $this->makeDealer('Autotest Other Buyer');
        $ledger = $this->ledger($buyer);
        $foreign = $this->ledger($other);
        $ok     = ['dealer_id' => $buyer->id, 'prices' => [$ledger->id => '10.00'], 'token' => (string) Str::uuid()];

        $this->post(route('admin.billing.invoices.store'), array_diff_key($ok, ['token' => 1]))->assertSessionHasErrors('token');
        $this->post(route('admin.billing.invoices.store'), ['token' => 'not-a-uuid'] + $ok)->assertSessionHasErrors('token');
        $this->post(route('admin.billing.invoices.store'), ['prices' => []] + $ok)->assertSessionHasErrors('prices');
        $this->post(route('admin.billing.invoices.store'), ['dealer_id' => 999999999] + $ok)->assertSessionHasErrors('dealer_id');
        $this->post(route('admin.billing.invoices.store'), ['prices' => [$ledger->id => ['1', '2']]] + $ok)->assertSessionHasErrors("prices.{$ledger->id}");
        $this->post(route('admin.billing.invoices.store'), ['notes' => str_repeat('n', 501)] + $ok)->assertSessionHasErrors('notes');

        // A price with 3 decimals is refused by the service and sent back to the form.
        $this->post(route('admin.billing.invoices.store'), ['prices' => [$ledger->id => '1.999']] + $ok)
            ->assertRedirect(route('admin.billing.invoices.new', ['dealer' => $buyer->id]))
            ->assertSessionHasErrors('prices');

        // Somebody else's transfer cannot be billed to this buyer by editing the form.
        $this->post(route('admin.billing.invoices.store'), ['prices' => [$foreign->id => '10.00']] + $ok)->assertSessionHasErrors('prices');

        $this->assertSame(0, Invoice::count(), 'none of the refused forms may create an invoice');
    }

    public function test_an_admin_records_a_payment_through_the_page_and_the_form_is_guarded(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer, '1000.00');
        $form = ['dealer_id' => $buyer->id, 'amount' => '400.00', 'method' => 'cash', 'received_date' => $this->today(), 'token' => (string) Str::uuid()];

        $this->post(route('admin.billing.receipts.store'), $form)->assertSessionHasNoErrors();
        $receipt = PaymentReceipt::where('buyer_dealer_id', $buyer->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('valid', $receipt->status);

        // The same form sent again is the same payment.
        $this->post(route('admin.billing.receipts.store'), $form)->assertRedirect(route('admin.billing.receipts.show', $receipt));
        $this->assertSame(1, PaymentReceipt::where('buyer_dealer_id', $buyer->id)->count());

        $fresh = fn (array $over) => array_merge($form, ['token' => (string) Str::uuid()], $over);
        $this->post(route('admin.billing.receipts.store'), $fresh(['amount' => '600.01']))->assertSessionHasErrors('amount');   // more than owed
        $this->post(route('admin.billing.receipts.store'), $fresh(['amount' => 'abc']))->assertSessionHasErrors('amount');
        $this->post(route('admin.billing.receipts.store'), $fresh(['method' => 'gold']))->assertSessionHasErrors('method');
        $this->post(route('admin.billing.receipts.store'), $fresh(['method' => 'bank_transfer', 'reference' => '']))->assertSessionHasErrors('amount');
        $this->post(route('admin.billing.receipts.store'), $fresh(['received_date' => now('Asia/Colombo')->addDay()->toDateString()]))->assertSessionHasErrors('amount');
        $this->post(route('admin.billing.receipts.store'), array_diff_key($form, ['token' => 1]))->assertSessionHasErrors('token');
        $this->post(route('admin.billing.receipts.store'), $fresh(['dealer_id' => 999999999]))->assertSessionHasErrors('dealer_id');

        $this->assertSame(1, PaymentReceipt::where('buyer_dealer_id', $buyer->id)->count(), 'only the one good payment was saved');
    }

    public function test_with_approval_on_the_recorder_cannot_approve_or_reject_through_the_page(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);

        $this->post(route('admin.billing.receipts.store'), [
            'dealer_id' => $buyer->id, 'amount' => '400.00', 'method' => 'cash', 'received_date' => $this->today(), 'token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $receipt = PaymentReceipt::where('buyer_dealer_id', $buyer->id)->first();
        $this->assertSame('pending', $receipt->status);

        $this->post(route('admin.billing.receipts.approve', $receipt))->assertSessionHasErrors('action');
        $this->post(route('admin.billing.receipts.reject', $receipt), ['reason' => 'Autotest'])->assertSessionHasErrors('action');
        $this->post(route('admin.billing.receipts.reject', $receipt), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame('pending', $receipt->fresh()->status, 'the payment is still waiting for a different person');
        $this->assertSame(100000, (int) round($this->billing->account($buyer->id)['balance']), 'a waiting payment is not counted');
    }

    public function test_reversing_a_payment_through_the_page_needs_a_reason_and_works_once(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer, '1000.00');
        $receipt = $this->billing->recordReceipt($buyer, '1000.00', 'cash', null, $this->today());
        $this->assertSame(0, $this->billing->account($buyer->id)['balance']);

        $this->post(route('admin.billing.receipts.reverse', $receipt), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('valid', $receipt->fresh()->status);

        $this->post(route('admin.billing.receipts.reverse', $receipt), ['reason' => 'Cheque bounced'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $receipt->fresh()->status);
        $this->assertSame(100000, $this->billing->account($buyer->id)['balance']);

        $this->post(route('admin.billing.receipts.reverse', $receipt), ['reason' => 'again'])->assertSessionHasErrors('action');
    }

    public function test_the_settings_page_refuses_bad_values_and_saves_good_ones(): void
    {
        $good = [
            'legal_name' => 'AUTOTEST New Name Ltd', 'address' => '2 Autotest Lane', 'payment_terms_days' => 45,
            'tax_enabled' => '1', 'tax_rate' => '8.00', 'tax_label' => 'VAT',
        ];
        $url = route('admin.billing.settings.update');

        $this->put($url, ['legal_name' => ''] + $good)->assertSessionHasErrors('legal_name');
        $this->put($url, ['address' => ''] + $good)->assertSessionHasErrors('address');
        $this->put($url, ['payment_terms_days' => 400] + $good)->assertSessionHasErrors('payment_terms_days');
        $this->put($url, ['payment_terms_days' => -1] + $good)->assertSessionHasErrors('payment_terms_days');
        $this->put($url, ['tax_rate' => '8.555'] + $good)->assertSessionHasErrors('tax_rate');
        $this->put($url, ['tax_rate' => '101'] + $good)->assertSessionHasErrors('tax_rate');
        $this->put($url, ['tax_rate' => '0'] + $good)->assertSessionHasErrors('tax_rate');   // tax on, rate zero
        $this->put($url, ['email' => 'not-an-email'] + $good)->assertSessionHasErrors('email');

        $this->assertSame('AUTOTEST Shalotrack Lanka (Pvt) Ltd', BillingParty::where('kind', 'company')->value('legal_name'), 'refused forms change nothing');
        $this->assertSame('30', app(BillingSettings::class)->get('payment_terms_days'));

        $this->put($url, $good)->assertSessionHasNoErrors();

        $settings = app(BillingSettings::class);
        $this->assertSame('45', $settings->get('payment_terms_days'));
        $this->assertSame('1', $settings->get('tax_enabled'));
        $this->assertEquals(8.0, (float) $settings->get('tax_rate'));
        $this->assertSame('AUTOTEST New Name Ltd', BillingParty::where('kind', 'company')->value('legal_name'));
    }

    public function test_an_admin_can_run_the_tamper_check_from_the_settings_page(): void
    {
        $this->invoiceFor($this->makeDealer('Autotest Buyer'));

        $this->post(route('admin.billing.verify'))
            ->assertSessionHas('verify', fn ($result) => $result['problems'] === []);
    }

    // ------------------------------------------------------------------ dealer portal: own billing only

    public function test_a_dealer_opens_its_own_billing_pages(): void
    {
        $dealer  = $this->makeDealer('Autotest Own Dealer');
        $invoice = $this->invoiceFor($dealer);
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('dealer.billing.index'))->assertOk()->assertSee($invoice->number);
        $this->get(route('dealer.billing.invoices.show', $invoice))->assertOk()->assertSee($invoice->number);
        $this->get(route('dealer.billing.statement'))->assertOk();
    }

    public function test_a_dealer_never_sees_the_invoices_of_anybody_else(): void
    {
        $distributor = $this->makeDistributor('Autotest Distributor A');
        $dealer      = $this->makeDealer('Autotest Dealer A1', $distributor);
        $sibling     = $this->makeDealer('Autotest Dealer A2', $distributor);
        $direct      = $this->makeDealer('Autotest Direct Dealer');

        $own     = $this->invoiceFor($dealer);
        $others  = [$this->invoiceFor($sibling), $this->invoiceFor($direct), $this->invoiceFor($distributor)];

        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $page = $this->get(route('dealer.billing.index'))->assertOk()->assertSee($own->number);
        foreach ($others as $other) {
            $page->assertDontSee($other->number);
        }

        // Typing another invoice's address looks exactly like "not found" (nothing about it is revealed).
        foreach ($others as $other) {
            $this->get(route('dealer.billing.invoices.show', $other))->assertNotFound();
            $this->get(route('dealer.billing.invoices.pdf', $other))->assertNotFound();
        }
    }

    public function test_a_dealer_login_that_is_not_linked_to_a_dealer_is_refused(): void
    {
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => null]));

        $this->get(route('dealer.billing.index'))->assertForbidden();
        $this->get(route('dealer.billing.statement'))->assertForbidden();
    }

    public function test_a_dealer_cannot_open_the_distributor_billing_pages(): void
    {
        $dealer = $this->makeDealer('Autotest Own Dealer');
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('distributor.billing.index'))->assertForbidden();
        $this->get(route('distributor.billing.statement'))->assertForbidden();
    }

    // ------------------------------------------------------------------ distributor portal: own + own dealers

    public function test_a_distributor_sees_its_own_billing_and_its_own_dealers_billing(): void
    {
        $distributor = $this->makeDistributor('Autotest Distributor A');
        $dealer1     = $this->makeDealer('Autotest Dealer A1', $distributor);
        $dealer2     = $this->makeDealer('Autotest Dealer A2', $distributor);
        $outsider    = $this->makeDealer('Autotest Dealer B1', $this->makeDistributor('Autotest Distributor B'));

        $own = $this->invoiceFor($distributor);
        $inv1 = $this->invoiceFor($dealer1);
        $inv2 = $this->invoiceFor($dealer2);

        $this->switchTo($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]));

        $this->get(route('distributor.billing.index'))
            ->assertOk()
            ->assertSee($own->number)
            ->assertSee('Autotest Dealer A1')
            ->assertSee('Autotest Dealer A2')
            ->assertDontSee($outsider->full_name);

        foreach ([$own, $inv1, $inv2] as $invoice) {
            $this->get(route('distributor.billing.invoices.show', $invoice))->assertOk()->assertSee($invoice->number);
        }

        $this->get(route('distributor.billing.statement'))->assertOk();
        $this->get(route('distributor.billing.statement', $dealer1))->assertOk();
    }

    public function test_a_distributor_never_sees_another_networks_or_a_direct_dealers_billing(): void
    {
        $distributor = $this->makeDistributor('Autotest Distributor A');
        $otherDist   = $this->makeDistributor('Autotest Distributor B');
        $outsider    = $this->makeDealer('Autotest Dealer B1', $otherDist);
        $direct      = $this->makeDealer('Autotest Direct Dealer');

        $outsiderInvoice = $this->invoiceFor($outsider);
        $directInvoice   = $this->invoiceFor($direct);
        $otherDistInvoice = $this->invoiceFor($otherDist);

        $this->switchTo($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]));

        foreach ([$outsiderInvoice, $directInvoice, $otherDistInvoice] as $invoice) {
            $this->get(route('distributor.billing.invoices.show', $invoice))->assertNotFound();
            $this->get(route('distributor.billing.invoices.pdf', $invoice))->assertNotFound();
        }

        foreach ([$outsider, $direct, $otherDist] as $buyer) {
            $this->get(route('distributor.billing.statement', $buyer))->assertNotFound();
        }

        $this->get(route('distributor.billing.index'))
            ->assertOk()
            ->assertDontSee($outsiderInvoice->number)
            ->assertDontSee($directInvoice->number)
            ->assertDontSee($otherDistInvoice->number);
    }

    public function test_a_distributor_that_is_not_active_is_refused(): void
    {
        $archived = $this->makeDistributor('Autotest Archived Distributor', 'archived');
        $this->switchTo($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $archived->id]));

        $this->get(route('distributor.billing.index'))->assertForbidden();
    }

    public function test_a_distributor_cannot_open_the_dealer_billing_pages(): void
    {
        $distributor = $this->makeDistributor('Autotest Distributor A');
        $this->switchTo($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]));

        $this->get(route('dealer.billing.index'))->assertForbidden();
        $this->get(route('dealer.billing.statement'))->assertForbidden();
    }
}