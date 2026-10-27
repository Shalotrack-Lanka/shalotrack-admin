<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\BillingParty;
use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\Invoice;
use App\Models\SetupShalotrackDevice;
use App\Services\Billing\Billing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * The credit-note screens of the admin billing area, tested the way a browser uses them.
 *   GET  admin.billing.invoices.credit        the form
 *   POST admin.billing.invoices.credit.store  issue the note (qty per invoice line + reason + one-time token)
 *   GET  admin.billing.credit-notes.show / .pdf
 *
 * The money rules themselves are in BillingFlowTest (service level). Here the question is: what does the web layer let through?
 * Bad quantities, a missing reason or token, a line of another invoice, a double click, a strange id, a login that
 * is not an admin. Runs on real Postgres in the autotest schema; every row is rolled back. The company row is
 * created inside the test (the autotest schema starts empty).
 */
class CreditNoteWebTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-GT06';

    private Billing $billing;
    private Admin $admin;
    private Dealer $buyer;
    private int $imeiSeq = 800;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $details = ['legal_name' => 'AUTOTEST Shalotrack Lanka (Pvt) Ltd', 'address' => '1 Autotest Road, Colombo', 'tax_number' => 'TIN-AUTOTEST', 'bank_details' => 'Autotest Bank 000111'];
        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        $company ? $company->forceFill($details)->save() : BillingParty::create(['kind' => BillingParty::KIND_COMPANY] + $details);

        $this->billing = app(Billing::class);
        $this->buyer   = $this->makeBuyer();
        $this->admin   = $this->makeAdmin('ADMIN');
        $this->actingAs($this->admin);
    }

    // ------------------------------------------------------------------ helpers

    private function makeBuyer(): Dealer
    {
        $k = ++$this->seq;

        return Dealer::create([
            'full_name' => 'Autotest Credit Buyer ' . $k, 'dealer_status' => 'retailer', 'region' => 'colombo', 'country' => 'Sri Lanka',
            'contact_email' => "autotest+credit-{$k}@example.test", 'status' => 'active', 'created_by' => 'autotest',
        ]);
    }

    /** A new invoice for $qty devices at $price each. */
    private function invoice(int $qty = 2, string $price = '1000.00', ?Dealer $to = null): Invoice
    {
        $to ??= $this->buyer;
        $ledger = DealerTransferLedger::create([
            'dealer_id' => $to->id, 'from_dealer_id' => null, 'device_category' => self::CATEGORY, 'quantity' => $qty, 'created_by_admin_id' => 'autotest',
        ]);
        for ($i = 0; $i < $qty; $i++) {
            $n = (string) $this->imeiSeq++;
            $device = SetupShalotrackDevice::create([
                'device_category' => self::CATEGORY, 'imei_number' => '99900000' . $n, 'sim_number' => '07200000' . $n,
                'iccid' => '8994200000' . $n, 'status' => DeviceStatus::Activated->value, 'dealer_id' => $to->id,
            ]);
            DealerTransferItem::create(['ledger_id' => $ledger->id, 'device_id' => $device->shdevice_id, 'imei_number' => $device->imei_number, 'sim_number' => $device->sim_number]);
        }

        return $this->billing->issueInvoice($to, [$ledger->id => $price]);
    }

    private function lineId(Invoice $invoice): int
    {
        return (int) $invoice->lines()->first()->id;
    }

    private function token(): string
    {
        return (string) Str::uuid();
    }

    private function credit(Invoice $invoice, array $body)
    {
        return $this->post(route('admin.billing.invoices.credit.store', $invoice), $body);
    }

    private function notesOf(Invoice $invoice): int
    {
        return CreditNote::where('invoice_id', $invoice->id)->count();
    }

    private function balance(): int
    {
        return $this->billing->account($this->buyer->id)['balance'];
    }

    // ------------------------------------------------------------------ the form

    public function test_the_form_shows_every_line_with_what_can_still_be_credited_and_a_fresh_token(): void
    {
        $invoice = $this->invoice(3);

        $r = $this->get(route('admin.billing.invoices.credit', $invoice))->assertOk()
            ->assertSee($invoice->number)->assertSee('name="qty[' . $this->lineId($invoice) . ']"', false);

        $this->assertSame([$this->lineId($invoice) => 3], $r->viewData('creditable'));
        $this->assertTrue(Str::isUuid($r->viewData('token')));

        $other = $this->get(route('admin.billing.invoices.credit', $invoice))->viewData('token');
        $this->assertNotSame($r->viewData('token'), $other, 'each page load gets its own token');
    }

    public function test_a_line_that_is_fully_credited_has_nothing_left_to_credit(): void
    {
        $invoice = $this->invoice(2);
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 2], 'reason' => 'All back', 'token' => $this->token()])->assertSessionHasNoErrors();

        $r = $this->get(route('admin.billing.invoices.credit', $invoice))->assertOk();
        $this->assertSame([$this->lineId($invoice) => 0], $r->viewData('creditable'));
        $this->get(route('admin.billing.invoices.show', $invoice))->assertOk()->assertDontSee('Issue credit note');
    }

    public function test_the_invoice_page_offers_the_credit_link_while_something_can_be_credited(): void
    {
        $invoice = $this->invoice(2);

        $this->get(route('admin.billing.invoices.show', $invoice))->assertOk()->assertSee('Issue credit note');
    }

    // ------------------------------------------------------------------ issuing

    public function test_a_partial_credit_note_is_issued_and_the_buyer_owes_less(): void
    {
        $invoice = $this->invoice(2, '1000.00');
        $this->assertSame(200000, $this->balance());

        $response = $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'One unit came back', 'token' => $this->token()]);

        $note = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('admin.billing.credit-notes.show', $note))
            ->assertSessionHas('success', "Credit note {$note->number} issued.");

        $this->assertSame(100000, \App\Support\Money::dbToCents($note->total));
        $this->assertSame('One unit came back', $note->reason);
        $this->assertSame($this->admin->admin_id, $note->issued_by);
        $this->assertSame(100000, $this->balance());
        $this->assertSame(1, DB::table('credit_note_lines')->where('credit_note_id', $note->id)->count());
        $this->assertTrue(AuditLog::where('action', 'credit_note.issued')->where('subject_label', $note->number)->exists());
    }

    public function test_the_rest_can_be_credited_later_and_then_nothing_more(): void
    {
        $invoice = $this->invoice(2, '1000.00');
        $line = $this->lineId($invoice);

        $this->credit($invoice, ['qty' => [$line => 1], 'reason' => 'First', 'token' => $this->token()])->assertSessionHasNoErrors();
        $this->credit($invoice, ['qty' => [$line => 1], 'reason' => 'Second', 'token' => $this->token()])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance(), 'the two notes cancel the invoice exactly');

        $this->credit($invoice, ['qty' => [$line => 1], 'reason' => 'Third', 'token' => $this->token()])->assertSessionHasErrors('qty');
        $this->assertSame(2, $this->notesOf($invoice));
        $this->assertSame(0, $this->balance());
    }

    public function test_asking_for_more_than_is_left_is_refused_and_nothing_is_written(): void
    {
        $invoice = $this->invoice(2);
        $notes = CreditNote::count();

        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 3], 'reason' => 'Too many', 'token' => $this->token()])->assertSessionHasErrors('qty');

        $this->assertSame($notes, CreditNote::count());
        $this->assertSame(0, DB::table('credit_note_lines')->count());
        $this->assertSame(200000, $this->balance());
    }

    public function test_choosing_nothing_is_refused(): void
    {
        $invoice = $this->invoice(2);
        $line = $this->lineId($invoice);

        foreach ([[$line => 0], [$line => ''], [$line => null]] as $qty) {
            $this->credit($invoice, ['qty' => $qty, 'reason' => 'Nothing chosen', 'token' => $this->token()])->assertSessionHasErrors('qty');
        }
        $this->assertSame(0, $this->notesOf($invoice));
    }

    public function test_a_line_of_another_invoice_cannot_be_credited_through_this_one(): void
    {
        $mine  = $this->invoice(2);
        $other = $this->invoice(2, '5000.00', $this->makeBuyer());

        $this->credit($mine, ['qty' => [$this->lineId($other) => 1], 'reason' => 'Wrong line', 'token' => $this->token()])->assertSessionHasErrors('qty');

        $this->assertSame(0, $this->notesOf($mine));
        $this->assertSame(0, $this->notesOf($other));
    }

    public function test_line_numbers_that_do_not_exist_or_are_not_numbers_are_refused_without_a_server_error(): void
    {
        $invoice = $this->invoice(2);

        foreach (['999999999', 'abc', '-1', '9223372036854775807', '99999999999999999999'] as $key) {
            $this->credit($invoice, ['qty' => [$key => 1], 'reason' => 'Odd line', 'token' => $this->token()])->assertSessionHasErrors('qty');
        }
        $this->assertSame(0, $this->notesOf($invoice));
    }

    public function test_a_blank_quantity_on_one_line_is_skipped_when_another_line_is_chosen(): void
    {
        $invoice = $this->invoice(2);
        $line = $this->lineId($invoice);

        $this->credit($invoice, ['qty' => [$line => 1, 'junk' => ''], 'reason' => 'Mixed', 'token' => $this->token()]);

        // 'junk' is not a line: blank means "not chosen", so only the real line counts.
        $this->assertSame(1, $this->notesOf($invoice));
    }

    public static function badQuantities(): array
    {
        return [
            'negative'      => ['-1'],
            'decimal'       => ['1.5'],
            'letters'       => ['two'],
            'above the cap' => ['100001'],
            'a list'        => [['1']],
            'exponent'      => ['1e2'],
            'huge'          => ['99999999999999999999'],
        ];
    }

    #[DataProvider('badQuantities')]
    public function test_bad_quantity_values_are_refused(mixed $bad): void
    {
        $invoice = $this->invoice(2);

        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => $bad], 'reason' => 'Bad qty', 'token' => $this->token()])->assertSessionHasErrors();

        $this->assertSame(0, $this->notesOf($invoice));
    }

    public function test_the_quantity_list_itself_must_be_a_list_and_not_too_long(): void
    {
        $invoice = $this->invoice(2);

        $this->credit($invoice, ['reason' => 'No list', 'token' => $this->token()])->assertSessionHasErrors('qty');
        $this->credit($invoice, ['qty' => 'one', 'reason' => 'Not a list', 'token' => $this->token()])->assertSessionHasErrors('qty');
        $this->credit($invoice, ['qty' => array_fill(1, 501, 0), 'reason' => 'Too long', 'token' => $this->token()])->assertSessionHasErrors('qty');
        $this->assertSame(0, $this->notesOf($invoice));
    }

    public static function badReasons(): array
    {
        return [
            'missing'        => [null],
            'empty'          => [''],
            'only spaces'    => ['     '],
            'too long'       => [str_repeat('r', 256)],
            'a list'         => [['x']],
        ];
    }

    #[DataProvider('badReasons')]
    public function test_a_credit_note_needs_a_proper_reason(mixed $reason): void
    {
        $invoice = $this->invoice(2);
        $body = ['qty' => [$this->lineId($invoice) => 1], 'token' => $this->token()];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        $this->credit($invoice, $body)->assertSessionHasErrors('reason');

        $this->assertSame(0, $this->notesOf($invoice));
    }

    public function test_a_reason_of_exactly_255_characters_including_sinhala_is_kept_whole(): void
    {
        $invoice = $this->invoice(2);
        $reason = str_repeat('අ', 255);

        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => $reason, 'token' => $this->token()])->assertSessionHasNoErrors();

        $this->assertSame($reason, CreditNote::where('invoice_id', $invoice->id)->firstOrFail()->reason);
    }

    public function test_html_in_the_reason_is_shown_as_text_not_run(): void
    {
        $invoice = $this->invoice(2);
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => '<script>alert(1)</script>', 'token' => $this->token()])->assertSessionHasNoErrors();
        $note = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();

        $this->get(route('admin.billing.credit-notes.show', $note))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public static function badTokens(): array
    {
        return [
            'missing'      => [null],
            'not a uuid'   => ['hello'],
            'too long'     => [str_repeat('a', 65)],
            'a list'       => [['x']],
            'sql text'     => ["' OR 1=1 --"],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_the_one_time_token_must_be_a_uuid(mixed $token): void
    {
        $invoice = $this->invoice(2);
        $body = ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Bad token'];
        if ($token !== null) {
            $body['token'] = $token;
        }

        $this->credit($invoice, $body)->assertSessionHasErrors('token');

        $this->assertSame(0, $this->notesOf($invoice));
    }

    public function test_a_double_click_with_the_same_token_makes_one_note_only(): void
    {
        $invoice = $this->invoice(2);
        $body = ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Double click', 'token' => $this->token()];

        $this->credit($invoice, $body)->assertSessionHasNoErrors();
        $this->credit($invoice, $body)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->notesOf($invoice));
        $this->assertSame(100000, $this->balance(), 'credited once, not twice');
        $this->assertSame(1, AuditLog::where('action', 'credit_note.issued')->where('subject_label', CreditNote::where('invoice_id', $invoice->id)->value('number'))->count());
    }

    public function test_a_used_token_cannot_be_used_to_credit_a_different_invoice(): void
    {
        $first  = $this->invoice(2);
        $second = $this->invoice(2, '1000.00', $this->makeBuyer());
        $token  = $this->token();

        $this->credit($first, ['qty' => [$this->lineId($first) => 1], 'reason' => 'First invoice', 'token' => $token])->assertSessionHasNoErrors();
        $this->credit($second, ['qty' => [$this->lineId($second) => 1], 'reason' => 'Second invoice', 'token' => $token]);

        $this->assertSame(1, $this->notesOf($first));
        $this->assertSame(0, $this->notesOf($second), 'nothing may be issued (or claimed) for the second invoice');
    }

    // ------------------------------------------------------------------ show and pdf

    public function test_the_credit_note_page_and_pdf_open(): void
    {
        $invoice = $this->invoice(2);
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Show me', 'token' => $this->token()]);
        $note = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();

        $this->get(route('admin.billing.credit-notes.show', $note))->assertOk()->assertSee($note->number)->assertSee('Show me')->assertSee($invoice->number);
        $this->get(route('admin.billing.credit-notes.pdf', $note))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.billing.invoices.show', $invoice))->assertOk()->assertSee($note->number);
    }

    public static function strangeIds(): array
    {
        return ['letters' => ['abc'], 'zero' => ['0'], 'negative' => ['-1'], 'decimal' => ['1.5'], 'missing' => ['987654321'], 'above int4' => ['4294967296'], 'bigint max' => ['9223372036854775807'], 'too many digits' => ['99999999999999999999999'], 'sql text' => ["1'%20OR%20'1'='1"]];
    }

    #[DataProvider('strangeIds')]
    public function test_strange_invoice_and_credit_note_numbers_are_a_404_not_a_server_error(string $id): void
    {
        $this->get('/admin/billing/invoices/' . $id . '/credit')->assertNotFound();
        $this->post('/admin/billing/invoices/' . $id . '/credit', ['qty' => [1 => 1], 'reason' => 'x', 'token' => $this->token()])->assertNotFound();
        $this->get('/admin/billing/credit-notes/' . $id)->assertNotFound();
        $this->get('/admin/billing/credit-notes/' . $id . '/pdf')->assertNotFound();
    }

    // ------------------------------------------------------------------ who may do it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_no_other_role_can_open_or_issue_credit_notes(string $role): void
    {
        $invoice = $this->invoice(2);
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Existing', 'token' => $this->token()]);
        $note = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $before = CreditNote::count();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.billing.invoices.credit', $invoice))->assertForbidden();
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Sneaky', 'token' => $this->token()])->assertForbidden();
        $this->get(route('admin.billing.credit-notes.show', $note))->assertForbidden();
        $this->get(route('admin.billing.credit-notes.pdf', $note))->assertForbidden();

        $this->assertSame($before, CreditNote::count());
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_the_login_page(): void
    {
        $invoice = $this->invoice(2);
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Existing', 'token' => $this->token()]);
        $note = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $before = CreditNote::count();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('admin.billing.invoices.credit', $invoice))->assertRedirect(route('login'));
        $this->credit($invoice, ['qty' => [$this->lineId($invoice) => 1], 'reason' => 'Sneaky', 'token' => $this->token()])->assertRedirect(route('login'));
        $this->get(route('admin.billing.credit-notes.show', $note))->assertRedirect(route('login'));
        $this->get(route('admin.billing.credit-notes.pdf', $note))->assertRedirect(route('login'));

        $this->assertSame($before, CreditNote::count());
    }
}