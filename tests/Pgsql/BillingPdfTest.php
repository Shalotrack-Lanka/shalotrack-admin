<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\AuditLog;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE BILLING DOWNLOADS, on real PostgreSQL (autotest schema):
 *
 *   admin.billing.invoices.pdf    GET  an invoice as a PDF
 *   admin.billing.receipts.pdf    GET  a payment receipt as a PDF
 *   admin.billing.statement.pdf   GET  a buyer's statement as a PDF
 *   admin.billing.statement.csv   GET  the same statement as a CSV file
 *   dealer.billing.invoices.pdf / distributor.billing.invoices.pdf   a buyer's own invoice, from their portal
 *
 * (The pages, the forms and credit notes are in BillingAccessTest, BillingFlowTest and CreditNoteWebTest.)
 *
 * What the tests prove (simple words):
 *   - each download is a real PDF / CSV with a file name made from its number or its dates,
 *   - a payment that is still waiting for approval (or was rejected) has NO receipt, a valid or reversed one has,
 *   - the statement uses the dates asked for (swapped if backwards, refused if not Y-m-d), carries the opening and
 *     closing balance, and the CSV is safe to open in a spreadsheet (a name starting with = is not run as a formula),
 *   - every download is written to the audit log,
 *   - a dealer or distributor can download only what the portal lets them see (everything else is a 404),
 *   - only ADMIN may use the admin downloads, and they are limited to 30 a minute.
 *
 * Documents are made through the Billing service. ONE login per test (a second login mid-test is logged out by
 * AuthenticateSession). Every row is rolled back by DatabaseTransactions.
 */
class BillingPdfTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-PDF';

    private Billing $billing;
    private Admin $maker;
    private int $imeiSeq = 700;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        $details = [
            'legal_name'   => 'AUTOTEST Shalotrack Lanka (Pvt) Ltd',
            'address'      => '1 Autotest Road, Colombo',
            'tax_number'   => 'TIN-AUTOTEST',
            'bank_details' => 'Autotest Bank 000111',
        ];
        $company ? $company->forceFill($details)->save() : BillingParty::create(['kind' => BillingParty::KIND_COMPANY] + $details);

        $this->billing = app(Billing::class);
        $this->maker   = $this->makeAdmin('ADMIN');
        $this->actingAs($this->maker);
    }

    // ------------------------------------------------------------------ helpers

    private function makeDealer(string $name, ?Dealer $distributor = null, string $type = 'retailer'): Dealer
    {
        $n = ++$this->counter;

        return Dealer::create([
            'full_name' => $name, 'dealer_status' => $type, 'distributor_id' => $distributor?->id, 'region' => 'colombo',
            'country' => 'Sri Lanka', 'contact_email' => "autotest+pdf-{$n}@example.test", 'status' => 'active', 'created_by' => 'autotest',
        ]);
    }

    private function ledger(Dealer $to): DealerTransferLedger
    {
        $ledger = DealerTransferLedger::create([
            'dealer_id' => $to->id, 'from_dealer_id' => null, 'device_category' => self::CATEGORY, 'quantity' => 1, 'created_by_admin_id' => 'autotest',
        ]);
        $n = (string) $this->imeiSeq++;
        $device = SetupShalotrackDevice::create([
            'device_category' => self::CATEGORY, 'imei_number' => '99800000' . $n, 'sim_number' => '07300000' . $n,
            'iccid' => '8994300000' . $n, 'status' => DeviceStatus::Activated->value, 'dealer_id' => $to->id,
        ]);
        DealerTransferItem::create(['ledger_id' => $ledger->id, 'device_id' => $device->shdevice_id, 'imei_number' => $device->imei_number, 'sim_number' => $device->sim_number]);

        return $ledger;
    }

    private function invoiceFor(Dealer $buyer, string $price = '1000.00'): Invoice
    {
        return $this->billing->issueInvoice($buyer, [$this->ledger($buyer)->id => $price]);
    }

    private function today(): string
    {
        return now('Asia/Colombo')->toDateString();
    }

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
            'DISTRIBUTOR' => $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $this->makeDealer('Autotest Own Distributor', null, 'distributor')->id]),
            default       => $this->makeAdmin($role),
        };
    }

    /** @return list<list<string|null>> the rows of a CSV download */
    private function csvRows(\Illuminate\Testing\TestResponse $response): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($response->streamedContent())) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    private function assertPdf(\Illuminate\Testing\TestResponse $response, string $fileName): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString($fileName, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function logged(string $fileName): ?AuditLog
    {
        return AuditLog::where('action', 'billing.document_downloaded')->where('subject_id', $fileName)->first();
    }

    // ------------------------------------------------------------------ invoice pdf

    public function test_an_invoice_downloads_as_a_pdf_named_after_its_number(): void
    {
        $invoice = $this->invoiceFor($this->makeDealer('Autotest Buyer'));

        $this->assertPdf($this->get(route('admin.billing.invoices.pdf', $invoice)), $invoice->number . '.pdf');
    }

    public function test_an_invoice_download_is_written_to_the_audit_log(): void
    {
        $invoice = $this->invoiceFor($this->makeDealer('Autotest Buyer'));

        $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();

        $log = $this->logged($invoice->number . '.pdf');
        $this->assertNotNull($log);
        $this->assertSame('Invoice ' . $invoice->number, $log->subject_label);
        $this->assertSame((string) $this->maker->admin_id, (string) $log->actor_id);
    }

    public function test_an_invoice_that_does_not_exist_or_a_text_id_is_a_clean_404(): void
    {
        foreach (['abc', '999999999', '99999999999999999999', '1%20OR%201=1'] as $id) {
            $this->get('/admin/billing/invoices/' . $id . '/pdf')->assertNotFound();
        }
    }

    // ------------------------------------------------------------------ receipt pdf

    public function test_a_valid_receipt_downloads_as_a_pdf_named_after_its_number(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $this->assertSame(PaymentReceipt::VALID, $receipt->status);

        $this->assertPdf($this->get(route('admin.billing.receipts.pdf', $receipt)), $receipt->number . '.pdf');

        $log = $this->logged($receipt->number . '.pdf');
        $this->assertNotNull($log);
        $this->assertSame('Receipt ' . $receipt->number, $log->subject_label);
    }

    public function test_a_reversed_receipt_still_downloads_so_the_history_stays_readable(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $this->billing->reverseReceipt($receipt, 'paid by mistake');
        $this->assertSame(PaymentReceipt::REVERSED, $receipt->fresh()->status);

        $this->assertPdf($this->get(route('admin.billing.receipts.pdf', $receipt)), $receipt->number . '.pdf');
    }

    public function test_a_payment_waiting_for_approval_has_no_receipt_yet(): void
    {
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $this->assertSame(PaymentReceipt::PENDING, $receipt->status);

        $this->get(route('admin.billing.receipts.pdf', $receipt))->assertNotFound();
    }

    public function test_a_rejected_payment_has_no_receipt(): void
    {
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $this->switchTo($this->makeAdmin('ADMIN'));   // somebody else must decide on it
        $this->billing->rejectReceipt($receipt, 'not received');
        $this->assertSame(PaymentReceipt::REJECTED, $receipt->fresh()->status);

        $this->get(route('admin.billing.receipts.pdf', $receipt))->assertNotFound();
    }

    public function test_a_receipt_that_does_not_exist_or_a_text_id_is_a_clean_404(): void
    {
        foreach (['abc', '999999999', '99999999999999999999'] as $id) {
            $this->get('/admin/billing/receipts/' . $id . '/pdf')->assertNotFound();
        }
    }

    // ------------------------------------------------------------------ statement pdf

    public function test_a_statement_downloads_as_a_pdf_named_after_the_buyer_and_the_dates(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $name = 'statement-' . $buyer->id . '-' . $this->today() . '_' . $this->today() . '.pdf';

        $this->assertPdf($this->get(route('admin.billing.statement.pdf', [$buyer, 'from' => $this->today(), 'to' => $this->today()])), $name);

        $log = $this->logged($name);
        $this->assertNotNull($log);
        $this->assertSame('Statement Autotest Buyer', $log->subject_label);
    }

    public function test_a_statement_with_no_dates_covers_this_month_up_to_today(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $first = now('Asia/Colombo')->startOfMonth()->toDateString();

        $this->assertPdf($this->get(route('admin.billing.statement.pdf', $buyer)), 'statement-' . $buyer->id . '-' . $first . '_' . $this->today() . '.pdf');
    }

    public function test_dates_given_backwards_are_swapped_not_refused(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');

        $this->assertPdf(
            $this->get(route('admin.billing.statement.pdf', [$buyer, 'from' => '2026-12-31', 'to' => '2026-01-01'])),
            'statement-' . $buyer->id . '-2026-01-01_2026-12-31.pdf'
        );
    }

    public static function badDates(): array
    {
        return ['day first' => ['31-12-2026'], 'text' => ['yesterday'], 'impossible' => ['2026-13-45'], 'sql' => ["2026-01-01' OR '1'='1"]];
    }

    #[DataProvider('badDates')]
    public function test_a_bad_date_is_refused_for_both_the_pdf_and_the_csv(string $bad): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');

        $this->get(route('admin.billing.statement.pdf', [$buyer, 'from' => $bad]))->assertSessionHasErrors('from');
        $this->get(route('admin.billing.statement.pdf', [$buyer, 'to' => $bad]))->assertSessionHasErrors('to');
        $this->get(route('admin.billing.statement.csv', [$buyer, 'from' => $bad]))->assertSessionHasErrors('from');
        $this->get(route('admin.billing.statement.csv', [$buyer, 'to' => $bad]))->assertSessionHasErrors('to');
    }

    public function test_a_statement_for_a_dealer_that_does_not_exist_is_a_clean_404(): void
    {
        foreach (['abc', '999999999', '99999999999999999999'] as $id) {
            $this->get('/admin/billing/accounts/' . $id . '/pdf')->assertNotFound();
            $this->get('/admin/billing/accounts/' . $id . '/csv')->assertNotFound();
        }
    }

    public function test_the_statement_pdf_says_to_set_up_the_company_first_when_it_is_not_set_up(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        BillingParty::where('kind', BillingParty::KIND_COMPANY)->update(['legal_name' => '']);

        $this->from('/back-here')->get(route('admin.billing.statement.pdf', $buyer))
            ->assertRedirect('/back-here')
            ->assertSessionHasErrors('pdf');
    }

    // ------------------------------------------------------------------ statement csv

    public function test_the_csv_has_the_opening_rows_and_closing_balance(): void
    {
        $buyer = $this->makeDealer('Autotest CSV Buyer');
        $invoice = $this->invoiceFor($buyer, '1000.00');
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $today = $this->today();

        $response = $this->get(route('admin.billing.statement.csv', [$buyer, 'from' => $today, 'to' => $today]))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('statement-' . $buyer->id . '-' . $today . '_' . $today . '.csv', (string) $response->headers->get('Content-Disposition'));
        $rows = $this->csvRows($response);

        $this->assertSame(['Statement', 'Autotest CSV Buyer', $today, $today], $rows[0]);
        $this->assertSame(['Date', 'Type', 'Number', 'Note', 'Amount (LKR)', 'Balance (LKR)'], $rows[1]);
        $this->assertSame(['', 'Opening balance', '', '', '', '0.00'], $rows[2]);
        $this->assertSame([$today, 'Invoice', $invoice->number, '', '1000.00', '1000.00'], $rows[3]);
        $this->assertSame([$today, 'Payment', $receipt->number, 'Cash', '-400.00', '600.00'], $rows[4]);
        $this->assertSame(['', 'Closing balance', '', '', '', '600.00'], $rows[5]);
        $this->assertCount(6, $rows);
    }

    public function test_money_before_the_first_date_becomes_the_opening_balance(): void
    {
        $buyer = $this->makeDealer('Autotest CSV Buyer');
        $this->invoiceFor($buyer, '1000.00');
        $tomorrow = now('Asia/Colombo')->addDay()->toDateString();

        $rows = $this->csvRows($this->get(route('admin.billing.statement.csv', [$buyer, 'from' => $tomorrow, 'to' => $tomorrow]))->assertOk());

        $this->assertSame(['', 'Opening balance', '', '', '', '1000.00'], $rows[2]);
        $this->assertSame(['', 'Closing balance', '', '', '', '1000.00'], $rows[3], 'no rows in the range, so closing = opening');
        $this->assertCount(4, $rows);
    }

    public function test_a_reversed_payment_shows_as_two_rows_and_the_balance_returns(): void
    {
        $buyer = $this->makeDealer('Autotest CSV Buyer');
        $this->invoiceFor($buyer, '1000.00');
        $receipt = $this->billing->recordReceipt($buyer, '400.00', 'cash', null, $this->today());
        $this->billing->reverseReceipt($receipt, 'paid by mistake');

        $rows = $this->csvRows($this->get(route('admin.billing.statement.csv', [$buyer, 'from' => $this->today(), 'to' => $this->today()]))->assertOk());

        $types = array_column(array_slice($rows, 3, -1), 1);
        $this->assertSame(['Invoice', 'Payment', 'Payment reversed'], $types);
        $this->assertSame('1000.00', end($rows)[5], 'after the reversal the whole invoice is owed again');
        $this->assertSame('paid by mistake', $rows[5][3]);
    }

    public function test_a_name_that_looks_like_a_formula_is_made_safe_in_the_csv(): void
    {
        $buyer = $this->makeDealer('=HYPERLINK("http://evil.test","click")');

        $rows = $this->csvRows($this->get(route('admin.billing.statement.csv', $buyer))->assertOk());

        $this->assertSame('\'=HYPERLINK("http://evil.test","click")', $rows[0][1]);
    }

    public function test_the_csv_download_is_written_to_the_audit_log(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $before = AuditLog::where('action', 'billing.document_downloaded')->count();

        $this->get(route('admin.billing.statement.csv', $buyer))->assertOk()->streamedContent();

        $this->assertSame($before + 1, AuditLog::where('action', 'billing.document_downloaded')->count());
        $log = AuditLog::where('action', 'billing.document_downloaded')->latest('id')->first();
        $this->assertSame('Statement CSV Autotest Buyer', $log->subject_label);
    }

    public function test_one_buyers_statement_never_includes_another_buyers_documents(): void
    {
        $mine = $this->makeDealer('Autotest Mine');
        $other = $this->makeDealer('Autotest Other');
        $this->invoiceFor($mine, '100.00');
        $theirs = $this->invoiceFor($other, '999.00');

        $csv = $this->get(route('admin.billing.statement.csv', $mine))->assertOk()->streamedContent();

        $this->assertStringNotContainsString($theirs->number, $csv);
        $this->assertStringNotContainsString('999.00', $csv);
    }

    // ------------------------------------------------------------------ limits

    public function test_the_downloads_are_limited_to_30_a_minute(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');

        for ($i = 0; $i < 30; $i++) {
            $this->get(route('admin.billing.statement.csv', $buyer))->assertOk()->streamedContent();
        }

        $this->get(route('admin.billing.statement.csv', $buyer))->assertStatus(429);
    }

    // ------------------------------------------------------------------ dealer and distributor portals

    public function test_a_dealer_downloads_its_own_invoice_as_a_pdf_and_it_is_logged(): void
    {
        $dealer = $this->makeDealer('Autotest Own Dealer');
        $invoice = $this->invoiceFor($dealer);
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->assertPdf($this->get(route('dealer.billing.invoices.pdf', $invoice)), $invoice->number . '.pdf');
        $this->assertNotNull($this->logged($invoice->number . '.pdf'));
    }

    public function test_a_dealer_cannot_download_another_dealers_invoice_nor_an_admin_only_document(): void
    {
        $dealer = $this->makeDealer('Autotest Own Dealer');
        $other = $this->makeDealer('Autotest Other Dealer');
        $theirs = $this->invoiceFor($other);
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('dealer.billing.invoices.pdf', $theirs))->assertNotFound();
        $this->get(route('admin.billing.invoices.pdf', $theirs))->assertForbidden();
        $this->get(route('admin.billing.statement.pdf', $other))->assertForbidden();
    }

    public function test_a_distributor_downloads_its_own_invoice_and_its_dealers_but_not_a_strangers(): void
    {
        $distributor = $this->makeDealer('Autotest Own Distributor', null, 'distributor');
        $downline = $this->makeDealer('Autotest Downline Dealer', $distributor);
        $stranger = $this->makeDealer('Autotest Stranger Dealer');
        $own = $this->invoiceFor($distributor);
        $down = $this->invoiceFor($downline);
        $theirs = $this->invoiceFor($stranger);
        $this->switchTo($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]));

        $this->assertPdf($this->get(route('distributor.billing.invoices.pdf', $own)), $own->number . '.pdf');
        $this->assertPdf($this->get(route('distributor.billing.invoices.pdf', $down)), $down->number . '.pdf');
        $this->get(route('distributor.billing.invoices.pdf', $theirs))->assertNotFound();
    }

    public function test_a_dealer_login_with_no_dealer_attached_downloads_nothing(): void
    {
        $invoice = $this->invoiceFor($this->makeDealer('Autotest Buyer'));
        $this->switchTo($this->makeAdmin('DEALER', ['dealer_id' => null]));

        $this->get(route('dealer.billing.invoices.pdf', $invoice))->assertForbidden();
    }

    // ------------------------------------------------------------------ who may use the admin downloads

    public static function blockedRoles(): array
    {
        return ['a dealer login' => ['DEALER'], 'a distributor login' => ['DISTRIBUTOR'], 'a finance login' => ['FINANCE'], 'a technician login' => ['TECHNICIAN'], 'a supplier login' => ['SUPPLIER']];
    }

    #[DataProvider('blockedRoles')]
    public function test_a_non_admin_login_cannot_download_receipts_or_statements(string $role): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '100.00', 'cash', null, $this->today());
        $user = $this->loginFor($role);
        $this->switchTo($user);

        $this->get(route('admin.billing.receipts.pdf', $receipt))->assertForbidden();
        $this->get(route('admin.billing.statement.pdf', $buyer))->assertForbidden();
        $this->get(route('admin.billing.statement.csv', $buyer))->assertForbidden();
        $this->assertNull($this->logged($receipt->number . '.pdf'), 'a refused download is not logged as a download');
    }

    public function test_a_person_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $buyer = $this->makeDealer('Autotest Buyer');
        $invoice = $this->invoiceFor($buyer);
        $receipt = $this->billing->recordReceipt($buyer, '100.00', 'cash', null, $this->today());
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('admin.billing.invoices.pdf', $invoice))->assertRedirect(route('login'));
        $this->get(route('admin.billing.receipts.pdf', $receipt))->assertRedirect(route('login'));
        $this->get(route('admin.billing.statement.pdf', $buyer))->assertRedirect(route('login'));
        $this->get(route('admin.billing.statement.csv', $buyer))->assertRedirect(route('login'));
        $this->get(route('dealer.billing.invoices.pdf', $invoice))->assertRedirect(route('login'));
        $this->get(route('distributor.billing.invoices.pdf', $invoice))->assertRedirect(route('login'));
    }
}