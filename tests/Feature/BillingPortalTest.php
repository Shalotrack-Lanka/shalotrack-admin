<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingParty;
use App\Models\Dealer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Concerns\BillingTestSchema;
use Tests\TestCase;

/** Step 3 screens: admin flows, read-only distributor / dealer views, scoping, abilities, PDFs. */
class BillingPortalTest extends TestCase
{
    use BillingTestSchema;

    private int $distA;
    private int $distB;
    private int $dealerA1;
    private int $dealerB1;
    private int $direct;
    private Billing $billing;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Cache::flush();
        $this->buildBillingSchema();
        Schema::create('dealer_customer_ads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->integer('no_of_devices')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        $this->seedCompany();
        $this->billing = app(Billing::class);
        $this->distA = $this->makeDealer('Dist Alpha', 'distributor');
        $this->distB = $this->makeDealer('Dist Beta', 'distributor');
        $this->dealerA1 = $this->makeDealer('Alpha One', 'retailer', $this->distA);
        $this->dealerB1 = $this->makeDealer('Beta One', 'retailer', $this->distB);
        $this->direct = $this->makeDealer('Direct Dan', 'retailer');
        $this->be($this->admin('boss'));
    }

    private function as(string $role, ?int $dealerId = null, string $name = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($this->admin(($name ?? strtolower($role) . ($dealerId ?? '')) . '-' . (++$this->seq), $role, $dealerId));

        return $this;
    }

    private function invoiceFor(int $dealerId, string $price = '1000.00', int $qty = 2): Invoice
    {
        $ledger = $this->makeLedger($dealerId, 'GT06', $qty);

        return $this->billing->issueInvoice(Dealer::find($dealerId), [$ledger->id => $price]);
    }

    // ------------------------------------------------------------ admin flow

    public function test_admin_screens_render(): void
    {
        $inv = $this->invoiceFor($this->dealerA1);
        $rec = $this->billing->recordReceipt(Dealer::find($this->dealerA1), '100', 'cash', null, now('Asia/Colombo')->toDateString());
        foreach ([
            route('admin.billing.home'), route('admin.billing.invoices.index'), route('admin.billing.invoices.new'),
            route('admin.billing.invoices.new', ['dealer' => $this->dealerB1]), route('admin.billing.invoices.show', $inv),
            route('admin.billing.invoices.credit', $inv), route('admin.billing.receipts.index'),
            route('admin.billing.receipts.new', ['dealer' => $this->dealerA1]), route('admin.billing.receipts.show', $rec),
            route('admin.billing.statement', $this->dealerA1), route('admin.billing.settings'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('admin.billing.home'))->assertSee('Alpha One')->assertSee('Accounts');
    }

    public function test_issue_invoice_through_the_screens_and_download_pdf(): void
    {
        $ledger = $this->makeLedger($this->dealerA1, 'GT06', 3);

        $this->post(route('admin.billing.invoices.review'), [
            'dealer_id' => $this->dealerA1, 'include' => [$ledger->id], 'prices' => [$ledger->id => '1,500.50'],
        ])->assertOk()->assertSee('4,501.50')->assertSee('Issue invoice');

        $token = (string) \Illuminate\Support\Str::uuid();
        $payload = ['dealer_id' => $this->dealerA1, 'prices' => [$ledger->id => '1,500.50'], 'token' => $token];
        $res = $this->post(route('admin.billing.invoices.store'), $payload);
        $inv = Invoice::firstOrFail();
        $res->assertRedirect(route('admin.billing.invoices.show', $inv));
        $this->post(route('admin.billing.invoices.store'), $payload);          // double submit
        $this->assertSame(1, Invoice::count());

        $this->get(route('admin.billing.invoices.show', $inv))->assertOk()->assertSee($inv->number)->assertSee('4,501.50');
        $pdf = $this->get(route('admin.billing.invoices.pdf', $inv));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, AuditLog::where('action', 'billing.document_downloaded')->count());
    }

    public function test_review_refuses_wrong_buyer_bad_price_and_nothing_ticked(): void
    {
        $mine = $this->makeLedger($this->dealerA1);
        $theirs = $this->makeLedger($this->dealerB1);

        $this->post(route('admin.billing.invoices.review'), ['dealer_id' => $this->dealerA1, 'include' => [$theirs->id], 'prices' => [$theirs->id => '10']])->assertSessionHasErrors('prices');
        $this->post(route('admin.billing.invoices.review'), ['dealer_id' => $this->dealerA1, 'include' => [$mine->id], 'prices' => [$mine->id => 'abc']])->assertSessionHasErrors('prices');
        $this->post(route('admin.billing.invoices.review'), ['dealer_id' => $this->dealerA1, 'prices' => [$mine->id => '10']])->assertSessionHasErrors('include');
        $this->assertSame(0, Invoice::count());
    }

    public function test_receipts_and_approval_flow_over_http(): void
    {
        $inv = $this->invoiceFor($this->dealerA1, '1000.00', 1);
        app(BillingSettings::class)->save(['approval_required' => '1'], 'x');

        $this->post(route('admin.billing.receipts.store'), [
            'dealer_id' => $this->dealerA1, 'amount' => '400', 'method' => 'bank_transfer', 'reference' => 'TX1',
            'received_date' => now('Asia/Colombo')->toDateString(), 'token' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertRedirect();
        $r = PaymentReceipt::firstOrFail();
        $this->assertSame('pending', $r->status);

        $this->post(route('admin.billing.receipts.approve', $r))->assertRedirect()->assertSessionHasErrors('action');   // maker
        $this->assertSame('pending', $r->fresh()->status);

        $this->as('ADMIN', null, 'checker')->post(route('admin.billing.receipts.approve', $r))->assertSessionHasNoErrors();
        $this->assertSame('valid', $r->fresh()->status);
        $this->get(route('admin.billing.receipts.pdf', $r))->assertOk();

        $this->post(route('admin.billing.receipts.reverse', $r), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.billing.receipts.reverse', $r), ['reason' => 'Cheque bounced'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $r->fresh()->status);
    }

    public function test_credit_note_over_http(): void
    {
        $inv = $this->invoiceFor($this->dealerA1, '100.00', 2);
        $line = $inv->lines()->first();
        $this->post(route('admin.billing.invoices.credit.store', $inv), ['qty' => [$line->id => 1], 'reason' => 'One unit returned', 'token' => (string) \Illuminate\Support\Str::uuid()])->assertRedirect();
        $this->assertSame(10000, $this->billing->account($this->dealerA1)['balance']);
        $note = \App\Models\CreditNote::firstOrFail();
        $this->get(route('admin.billing.credit-notes.show', $note))->assertOk()->assertSee($note->number);
        $this->get(route('admin.billing.credit-notes.pdf', $note))->assertOk();
        $this->post(route('admin.billing.invoices.credit.store', $inv), ['qty' => [$line->id => 5], 'reason' => 'x', 'token' => (string) \Illuminate\Support\Str::uuid()])->assertSessionHasErrors('qty');
    }

    public function test_settings_save_audit_and_tax_rule(): void
    {
        $base = ['legal_name' => 'Shalotrack Lanka (Pvt) Ltd', 'address' => 'Colombo', 'payment_terms_days' => 14];
        $this->put(route('admin.billing.settings.update'), $base + ['tax_enabled' => 1, 'tax_rate' => '0'])->assertSessionHasErrors('tax_rate');
        $this->put(route('admin.billing.settings.update'), $base + ['tax_enabled' => 1, 'tax_rate' => '8', 'tax_label' => 'VAT', 'approval_required' => 1])->assertSessionHasNoErrors();
        $s = app(BillingSettings::class)->all();
        $this->assertSame(['1', '8.00', '14', '1'], [$s['tax_enabled'], $s['tax_rate'], $s['payment_terms_days'], $s['approval_required']]);
        $this->assertGreaterThanOrEqual(1, AuditLog::where('action', 'billing.settings_changed')->count());
        $this->post(route('admin.billing.verify'))->assertSessionHas('verify');
    }

    public function test_statement_csv_neutralises_formulas(): void
    {
        DB::table('billing_parties')->where('kind', 'company')->update(['legal_name' => 'Co']);
        DB::table('dealers')->where('id', $this->dealerA1)->update(['full_name' => '=HYPERLINK("http://evil")']);
        $this->invoiceFor($this->dealerA1);
        $csv = $this->get(route('admin.billing.statement.csv', $this->dealerA1))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->get(route('admin.billing.statement.pdf', $this->dealerA1))->assertOk();
    }

    public function test_buyer_names_are_escaped_on_screens(): void
    {
        $evil = '<script>alert(1)</script>';
        DB::table('dealers')->where('id', $this->dealerA1)->update(['full_name' => $evil]);
        $inv = $this->invoiceFor($this->dealerA1);
        foreach ([route('admin.billing.invoices.show', $inv), route('admin.billing.home'), route('admin.billing.invoices.index'), route('admin.billing.statement', $this->dealerA1)] as $url) {
            $this->get($url)->assertOk()->assertDontSee($evil, false);
        }
    }

    // ------------------------------------------------------------ roles and abilities

    public function test_only_admin_reaches_admin_billing_and_only_the_right_portal_reaches_its_own(): void
    {
        $inv = $this->invoiceFor($this->dealerA1);
        foreach (['DEALER', 'DISTRIBUTOR', 'TECHNICIAN', 'FINANCE', 'SUPPLIER'] as $role) {
            $this->as($role, $role === 'DEALER' ? $this->dealerA1 : ($role === 'DISTRIBUTOR' ? $this->distA : null));
            $this->get(route('admin.billing.home'))->assertForbidden();
            $this->get(route('admin.billing.invoices.show', $inv))->assertForbidden();
            $this->post(route('admin.billing.receipts.store'), [])->assertForbidden();
            $this->put(route('admin.billing.settings.update'), [])->assertForbidden();
        }
        $this->as('ADMIN', null, 'a2');
        $this->get(route('distributor.billing.index'))->assertForbidden();
        $this->get(route('dealer.billing.index'))->assertForbidden();
        $this->as('DEALER', $this->dealerA1);
        $this->get(route('distributor.billing.index'))->assertForbidden();
        $this->as('DISTRIBUTOR', $this->distA);
        $this->get(route('dealer.billing.index'))->assertForbidden();
    }

    public function test_abilities_come_from_the_config_map_so_finance_can_be_granted_later(): void
    {
        $finance = $this->admin('fin', 'FINANCE');
        $this->assertFalse(Gate::forUser($finance)->allows('billing.view'));
        config(['billing.abilities.FINANCE' => ['billing.view', 'billing.receipt.record']]);
        $this->assertTrue(Gate::forUser($finance)->allows('billing.view'));
        $this->assertFalse(Gate::forUser($finance)->allows('billing.receipt.approve'));
        $dealer = $this->admin('d', 'DEALER', $this->dealerA1);
        $this->assertTrue(Gate::forUser($dealer)->allows('billing.view'));
        $this->assertFalse(Gate::forUser($dealer)->allows('billing.receipt.record'));
        $this->assertFalse(Gate::forUser($dealer)->allows('billing.settings.manage'));
    }

    // ------------------------------------------------------------ read-only portals and scoping

    public function test_dealer_sees_only_own_billing(): void
    {
        $mine = $this->invoiceFor($this->dealerA1, '500.00', 1);
        $other = $this->invoiceFor($this->dealerB1, '700.00', 1);

        $this->as('DEALER', $this->dealerA1);
        $this->get(route('dealer.billing.index'))->assertOk()->assertSee($mine->number)->assertDontSee($other->number)->assertDontSee('Issue invoice');
        $this->get(route('dealer.billing.invoices.show', $mine))->assertOk()->assertSee('500.00');
        $this->get(route('dealer.billing.invoices.pdf', $mine))->assertOk();
        $this->get(route('dealer.billing.statement'))->assertOk()->assertSee('Statement');
        $this->get(route('dealer.billing.invoices.show', $other))->assertNotFound();
        $this->get(route('dealer.billing.invoices.pdf', $other))->assertNotFound();
    }

    public function test_distributor_sees_own_and_own_dealers_only(): void
    {
        $own = $this->invoiceFor($this->distA, '900.00', 1);
        $mine = $this->invoiceFor($this->dealerA1, '500.00', 1);
        $foreignDealer = $this->invoiceFor($this->dealerB1, '700.00', 1);
        $foreignDist = $this->invoiceFor($this->distB, '800.00', 1);
        $direct = $this->invoiceFor($this->direct, '600.00', 1);

        $this->as('DISTRIBUTOR', $this->distA);
        $this->get(route('distributor.billing.index'))->assertOk()->assertSee($own->number)->assertSee('Alpha One')
            ->assertDontSee('Beta One')->assertDontSee('Direct Dan')->assertDontSee($foreignDealer->number);
        $this->get(route('distributor.billing.invoices.show', $own))->assertOk();
        $this->get(route('distributor.billing.invoices.show', $mine))->assertOk();
        $this->get(route('distributor.billing.statement', $this->dealerA1))->assertOk()->assertSee('Alpha One');

        foreach ([$foreignDealer, $foreignDist, $direct] as $inv) {
            $this->get(route('distributor.billing.invoices.show', $inv))->assertNotFound();
            $this->get(route('distributor.billing.invoices.pdf', $inv))->assertNotFound();
        }
        foreach ([$this->dealerB1, $this->distB, $this->direct] as $id) {
            $this->get(route('distributor.billing.statement', $id))->assertNotFound();
        }
    }

    public function test_unlinked_portal_logins_fail_closed_and_portals_cannot_write(): void
    {
        $this->as('DISTRIBUTOR', null, 'ghost');
        $this->get(route('distributor.billing.index'))->assertForbidden();
        $this->as('DEALER', null, 'ghost2');
        $this->get(route('dealer.billing.index'))->assertForbidden();

        $this->as('DEALER', $this->dealerA1);
        $this->post(route('admin.billing.receipts.store'), ['dealer_id' => $this->dealerA1, 'amount' => '1', 'method' => 'cash', 'received_date' => now()->toDateString(), 'token' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->assertSame(0, PaymentReceipt::count());
    }

    public function test_navigation_links_are_present(): void
    {
        $this->get(route('admin.billing.home'))->assertSee('Billing Settings');
        $this->as('DISTRIBUTOR', $this->distA);
        $this->get(route('distributor.billing.index'))->assertOk()->assertSee('Billing &amp; Invoices', false)->assertSee('Your account with ShaloTrack');
    }
}
