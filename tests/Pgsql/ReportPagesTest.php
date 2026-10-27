<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use App\Models\ExpiredDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * REPORT PAGES AND PDF REPORTS (admin), on real PostgreSQL.
 *
 * Rules in simple words:
 *   - The three report screens (stock-in, credit invoice, activation) open for an ADMIN and for nobody else.
 *   - The PDF reports (active / inactive customers, the all-in-one customer device report, customers added by dealers)
 *     open as a real PDF for an ADMIN, with data or without it, and for nobody else. An unknown report type falls back
 *     to the default instead of causing an error.
 *   - A script typed into a customer or dealer name is never run inside a report.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class ReportPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function assertPdf($response, ?string $fileName = null): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        if ($fileName !== null) {
            $this->assertStringContainsString($fileName, (string) $response->headers->get('content-disposition'));
        }
    }

    // ================================================================== the three report screens

    public static function screens(): array
    {
        return ['stock-in report' => ['admin.stock-in-report'], 'credit invoice report' => ['admin.credit-invoice-report'], 'activation report' => ['admin.activation-report']];
    }

    #[DataProvider('screens')]
    public function test_a_report_screen_opens_for_an_admin(string $route): void
    {
        $this->actingAs($this->admin())->get(route($route))->assertOk();
    }

    public static function screensByRole(): array
    {
        $out = [];
        foreach (['admin.stock-in-report', 'admin.credit-invoice-report', 'admin.activation-report'] as $route) {
            foreach (['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
                $out["$route as $role"] = [$route, $role];
            }
        }

        return $out;
    }

    #[DataProvider('screensByRole')]
    public function test_a_report_screen_is_closed_to_every_other_role(string $route, string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route($route))->assertForbidden();
    }

    #[DataProvider('screens')]
    public function test_a_report_screen_sends_a_visitor_to_log_in(string $route): void
    {
        $this->get(route($route))->assertRedirect();
    }

    // ================================================================== customers PDF

    public function test_the_active_customers_report_is_a_pdf_with_its_own_file_name(): void
    {
        CustomerAd::create(['customer_id' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Autotest Verified', 'cus_status' => 'verified']);

        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-setup.report', ['type' => 'active'])), 'active_customers_report.pdf');
    }

    public function test_the_inactive_customers_report_is_a_pdf_with_its_own_file_name(): void
    {
        CustomerAd::create(['customer_id' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Autotest Unverified', 'cus_status' => 'not_verified']);

        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-setup.report', ['type' => 'inactive'])), 'inactive_customers_report.pdf');
    }

    #[DataProvider('oddTypes')]
    public function test_an_unknown_report_type_falls_back_to_the_active_report(mixed $type): void
    {
        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-setup.report', ['type' => $type])), 'active_customers_report.pdf');
    }

    public static function oddTypes(): array
    {
        return ['unknown' => ['bogus'], 'a list' => [['inactive']], 'a script' => ['<script>'], 'long' => [str_repeat('a', 5000)]];
    }

    public function test_the_customers_report_works_with_no_customers_and_with_a_script_in_a_name(): void
    {
        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-setup.report')));

        CustomerAd::create(['customer_id' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => '<script>alert(1)</script>', 'cus_status' => 'verified']);

        $response = $this->actingAs($this->admin())->get(route('admin.customer-setup.report'));
        $this->assertPdf($response);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
    }

    // ================================================================== all-in-one device report PDF

    public function test_the_device_report_is_a_pdf_with_active_expired_and_vehicle_only_rows(): void
    {
        $active = $this->makeBound(['payment_status' => 'Paid', 'subscription_end_date' => now()->addDays(40)]);
        $lapsed = $this->makeBound(['payment_status' => 'not-Paid']);
        $this->makeExpiredRecord($lapsed, '2026-01-01');

        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-device-management.report')), 'device_report.pdf');
    }

    public function test_the_device_report_works_when_there_is_nothing_to_list(): void
    {
        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-device-management.report')));
    }

    public function test_the_device_report_first_moves_lapsed_paid_subscriptions_into_the_expired_list(): void
    {
        // The report runs the same sweep as the Device Management page, so what it prints is up to date.
        $lapsed = $this->makeBound(['payment_status' => 'Paid', 'subscription_start_date' => now()->subYear()->subDay(), 'subscription_end_date' => now()->subDay()]);

        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.customer-device-management.report')));

        $this->assertSame('not-Paid', $lapsed->fresh()->payment_status);
        $this->assertSame(1, ExpiredDevice::where('imei_number', $lapsed->imei_number)->count());
    }

    public function test_running_the_device_report_twice_does_not_expire_the_same_device_twice(): void
    {
        $lapsed = $this->makeBound(['payment_status' => 'Paid', 'subscription_start_date' => now()->subYear()->subDay(), 'subscription_end_date' => now()->subDay()]);

        $this->actingAs($this->admin())->get(route('admin.customer-device-management.report'))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.customer-device-management.report'))->assertOk();

        $this->assertSame(1, ExpiredDevice::where('imei_number', $lapsed->imei_number)->count());
    }

    // ================================================================== customers added by dealers PDF

    public function test_the_dealer_customers_report_is_a_pdf(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest PDF Dealer']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Autotest Lead', 'contact' => '0771110001', 'email' => 'lead@example.test', 'no_of_devices' => 2, 'imei_numbers' => []]);

        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.dealer-customers.report')), 'customers_added_by_dealers_report.pdf');
    }

    public function test_the_dealer_customers_report_works_when_empty_and_with_a_script_in_a_name(): void
    {
        $this->assertPdf($this->actingAs($this->admin())->get(route('admin.dealer-customers.report')));

        $dealer = $this->makeDealer(['full_name' => '<script>alert(1)</script>']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => '<script>alert(2)</script>', 'contact' => '0771110002', 'email' => 'x@example.test', 'no_of_devices' => 1, 'imei_numbers' => []]);

        $response = $this->actingAs($this->admin())->get(route('admin.dealer-customers.report'));
        $this->assertPdf($response);
        $this->assertStringNotContainsString('<script>alert', $response->getContent());
    }

    // ================================================================== who may enter the PDFs

    public static function pdfRoutes(): array
    {
        return ['customers' => ['admin.customer-setup.report'], 'devices' => ['admin.customer-device-management.report'], 'dealer customers' => ['admin.dealer-customers.report']];
    }

    public static function pdfByRole(): array
    {
        $out = [];
        foreach (['admin.customer-setup.report', 'admin.customer-device-management.report', 'admin.dealer-customers.report'] as $route) {
            foreach (['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
                $out["$route as $role"] = [$route, $role];
            }
        }

        return $out;
    }

    #[DataProvider('pdfByRole')]
    public function test_a_pdf_report_is_closed_to_every_other_role(string $route, string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route($route))->assertForbidden();
    }

    #[DataProvider('pdfRoutes')]
    public function test_a_pdf_report_sends_a_visitor_to_log_in(string $route): void
    {
        $this->get(route($route))->assertRedirect();
    }
}