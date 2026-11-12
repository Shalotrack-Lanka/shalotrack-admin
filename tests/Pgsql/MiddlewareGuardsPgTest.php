<?php

namespace Tests\Pgsql;

use App\Http\Middleware\AuditAdminActivity;
use App\Http\Middleware\EnsureDeveloper;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE FOUR GUARDS THAT RUN AROUND EVERY PAGE, on real PostgreSQL (autotest schema):
 *
 *   EnforceAdminStatus     a person deactivated while signed in is thrown out on their next click
 *   EnforcePasswordChange  a person with a temporary password can reach only "choose a new password" and Logout
 *   EnsureDeveloper        only the company's developer login gets through
 *   AuditAdminActivity     one automatic audit entry for every change or download, with secrets removed
 *
 * What the tests prove (simple words): each guard blocks exactly what it should for every role, lets everyone else
 * through untouched, and the audit guard records who/what/outcome without ever storing a password, a token, a
 * national ID or a file's content, and without ever breaking the page when the audit write itself fails.
 */
class MiddlewareGuardsPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private const ROLES = ['ADMIN', 'DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'];

    public static function roles(): array
    {
        return array_map(fn ($r) => [$r], self::ROLES);
    }

    /** Where a given role lands after sign-in, so a "passes the guard" request has a page that opens. */
    private function homeFor(string $role): string
    {
        return route(['ADMIN' => 'admin.dashboard', 'DEALER' => 'dealer.dashboard', 'DISTRIBUTOR' => 'distributor.dashboard',
            'FINANCE' => 'finance.dashboard', 'TECHNICIAN' => 'technician.dashboard', 'SUPPLIER' => 'supplier.dashboard'][$role]);
    }

    // ================================================================== EnforceAdminStatus

    #[DataProvider('roles')]
    public function test_an_active_person_gets_through(string $role): void
    {
        $extra = $role === 'DISTRIBUTOR' ? ['dealer_id' => $this->makeDealer(['dealer_status' => 'distributor'])->id] : [];

        $this->actingAs($this->makeAdmin($role, $extra))->get($this->homeFor($role))->assertSuccessful();
    }

    #[DataProvider('roles')]
    public function test_a_person_deactivated_while_signed_in_is_sent_to_login_with_a_message(string $role): void
    {
        $admin = $this->makeAdmin($role, ['status' => 'INACTIVE']);

        $this->actingAs($admin)->get($this->homeFor($role))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Your account has been deactivated. Please contact an administrator.']);
        $this->assertGuest();
    }

    public function test_the_forced_sign_out_is_audited_as_forced_not_chosen(): void
    {
        $admin = $this->makeAdmin('DEALER', ['status' => 'INACTIVE']);

        $this->actingAs($admin)->get($this->homeFor('DEALER'));

        $row = AuditLog::where('action', 'auth.logout')->where('actor_id', $admin->admin_id)->latest('id')->first();
        $this->assertNotNull($row, 'the forced sign-out must leave a trail');
        $this->assertSame('forced out: the account was deactivated while signed in', $row->meta['how']);
    }

    public function test_a_status_that_is_not_exactly_active_is_treated_as_deactivated(): void
    {
        foreach (['inactive', 'Active ', ''] as $status) {
            // The database only allows ACTIVE / INACTIVE, so the odd value is set in memory only (never saved).
            $admin = $this->makeAdmin('ADMIN');
            $admin->status = $status;

            $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('login'));
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_a_guest_is_left_to_the_login_check_and_reaches_the_login_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }

    // ================================================================== EnforcePasswordChange

    #[DataProvider('roles')]
    public function test_a_person_with_a_temporary_password_is_held_on_the_change_password_screen(string $role): void
    {
        $admin = $this->makeAdmin($role, ['must_change_password' => true]);

        $this->actingAs($admin)->get($this->homeFor($role))->assertRedirect(route('account.password.edit'));
    }

    public function test_the_change_password_screen_itself_and_logout_stay_reachable(): void
    {
        $admin = $this->makeAdmin('DEALER', ['must_change_password' => true]);

        $this->actingAs($admin)->get(route('account.password.edit'))->assertOk();
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_wrong_password_on_the_change_screen_is_not_blocked_by_the_hold(): void
    {
        $admin = $this->makeAdmin('ADMIN', ['must_change_password' => true]);

        $response = $this->actingAs($admin)->put(route('account.password.update'), ['current_password' => 'wrong', 'password' => 'x', 'password_confirmation' => 'x']);

        $response->assertSessionHasErrors();   // the screen's own validation answered, not the hold's redirect
    }

    public function test_a_json_call_while_on_hold_gets_423_not_a_redirect(): void
    {
        $admin = $this->makeAdmin('ADMIN', ['must_change_password' => true]);

        $this->actingAs($admin)->getJson($this->homeFor('ADMIN'))->assertStatus(423)->assertExactJson(['message' => 'Choose a new password first.']);
    }

    public function test_a_change_request_is_held_too_not_only_page_views(): void
    {
        $admin = $this->makeAdmin('ADMIN', ['must_change_password' => true]);

        $this->actingAs($admin)->post(route('admin.device-types.store'), ['x' => 'y'])->assertRedirect(route('account.password.edit'));
    }

    public function test_once_the_password_is_chosen_the_hold_ends(): void
    {
        $admin = $this->makeAdmin('ADMIN', ['must_change_password' => false]);

        $this->actingAs($admin)->get($this->homeFor('ADMIN'))->assertOk();
    }

    // ================================================================== EnsureDeveloper (called directly)

    private function developerGate(?object $user): Response
    {
        $request = Request::create('/dev', 'GET');
        $request->setUserResolver(fn () => $user);

        return (new EnsureDeveloper())->handle($request, fn () => new Response('through'));
    }

    private function assertRefused(?object $user): void
    {
        try {
            $this->developerGate($user);
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('You do not have permission to perform this action.', $e->getMessage());
        }
    }

    public function test_the_developer_login_gets_through(): void
    {
        config(['erp.developer_usernames' => ['dev.autotest']]);

        $response = $this->developerGate($this->makeAdmin('ADMIN', ['username' => 'dev.autotest']));

        $this->assertSame('through', $response->getContent());
    }

    public function test_the_developer_username_is_matched_ignoring_letter_case(): void
    {
        config(['erp.developer_usernames' => ['dev.autotest']]);

        $this->assertSame('through', $this->developerGate($this->makeAdmin('ADMIN', ['username' => 'Dev.AutoTest']))->getContent());
    }

    public function test_an_ordinary_administrator_is_refused(): void
    {
        config(['erp.developer_usernames' => ['dev.autotest']]);

        $this->assertRefused($this->makeAdmin('ADMIN'));
    }

    public function test_a_guest_is_refused(): void
    {
        $this->assertRefused(null);
    }

    public function test_a_deactivated_developer_is_refused(): void
    {
        config(['erp.developer_usernames' => ['dev.autotest']]);

        $this->assertRefused($this->makeAdmin('ADMIN', ['username' => 'dev.autotest', 'status' => 'INACTIVE']));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_a_login_with_the_developers_name_but_another_role_is_refused(string $role): void
    {
        config(['erp.developer_usernames' => ['dev.autotest']]);

        $this->assertRefused($this->makeAdmin($role, ['username' => 'dev.autotest']));
    }

    public static function nonAdminRoles(): array
    {
        return array_map(fn ($r) => [$r], array_slice(self::ROLES, 1));
    }

    public function test_something_that_is_not_a_login_object_is_refused(): void
    {
        $this->assertRefused(new \stdClass());
    }

    public function test_an_empty_developer_list_means_nobody_is_a_developer(): void
    {
        config(['erp.developer_usernames' => []]);

        $this->assertRefused($this->makeAdmin('ADMIN', ['username' => 'admin001']));
    }

    // ================================================================== AuditAdminActivity (called directly)

    private function rows(): \Illuminate\Database\Eloquent\Builder
    {
        return AuditLog::whereIn('action', ['request.write', 'data.downloaded']);
    }

    /** Runs the guard's after-response step for a request made by $admin. */
    private function terminate(Request $request, Response $response, $admin = null): void
    {
        if ($admin !== false) {
            $this->actingAs($admin ?? $this->makeAdmin('ADMIN'));
        }
        (new AuditAdminActivity())->terminate($request, $response);
    }

    private function newest(): ?AuditLog
    {
        return $this->rows()->latest('id')->first();
    }

    public function test_the_guard_passes_the_request_on_untouched(): void
    {
        $response = new Response('x', 201);
        $request = Request::create('/x', 'POST');

        $this->assertSame($response, (new AuditAdminActivity())->handle($request, fn () => $response));
    }

    public function test_a_plain_page_view_is_not_recorded(): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create('/admin/dashboard', 'GET'), new Response('page'));

        $this->assertSame($before, $this->rows()->count());
    }

    public function test_a_change_is_recorded_with_who_what_and_the_outcome(): void
    {
        $admin = $this->makeAdmin('FINANCE');

        $this->terminate(Request::create('/admin/things/5', 'POST', ['name' => 'Widget', 'qty' => 3]), new Response('ok', 200), $admin);

        $row = $this->newest();
        $this->assertSame('request.write', $row->action);
        $this->assertSame($admin->admin_id, $row->actor_id);
        $this->assertSame('FINANCE', $row->actor_role);
        $this->assertSame('POST', $row->subject_type);
        $this->assertSame('admin/things/5', $row->subject_label);
        $this->assertSame(200, $row->meta['status']);
        $this->assertSame('done', $row->meta['outcome']);
        $this->assertEquals(['name' => 'Widget', 'qty' => 3], $row->meta['input']);
    }

    public static function outcomes(): array
    {
        return ['server error' => [500, 'failed (server error)'], 'bad gateway' => [502, 'failed (server error)'], 'forbidden' => [403, 'blocked (no permission)'],
            'expired form' => [419, 'blocked (expired form)'], 'validation' => [422, 'refused (validation)'], 'not found' => [404, 'failed'],
            'bad request' => [400, 'failed'], 'too many' => [429, 'failed'], 'redirect' => [302, 'done'], 'created' => [201, 'done']];
    }

    #[DataProvider('outcomes')]
    public function test_the_outcome_is_worked_out_from_the_status(int $status, string $outcome): void
    {
        $this->terminate(Request::create('/admin/x', 'POST', ['a' => 1]), new Response('', $status));

        $this->assertSame($outcome, $this->newest()->meta['outcome']);
        $this->assertSame($status, $this->newest()->meta['status']);
    }

    public function test_a_redirect_back_to_the_form_with_messages_is_recorded_as_refused(): void
    {
        $request = Request::create('/admin/x', 'POST', ['a' => 1]);
        $session = $this->app['session']->driver();
        $session->put('errors', new \Illuminate\Support\ViewErrorBag());
        $request->setLaravelSession($session);

        $this->terminate($request, new Response('', 302));

        $this->assertSame('refused (message shown to the user)', $this->newest()->meta['outcome']);
    }

    public static function writeMethods(): array
    {
        return array_map(fn ($m) => [$m], ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    #[DataProvider('writeMethods')]
    public function test_every_changing_method_is_recorded(string $method): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create('/admin/x', $method), new Response('', 200));

        $this->assertSame($before + 1, $this->rows()->count());
        $this->assertSame($method, $this->newest()->subject_type);
    }

    public function test_head_and_options_are_not_recorded(): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create('/admin/x', 'HEAD'), new Response('', 200));
        $this->terminate(Request::create('/admin/x', 'OPTIONS'), new Response('', 200));

        $this->assertSame($before, $this->rows()->count());
    }

    public static function skipped(): array
    {
        return array_map(fn ($p) => [$p], ['login', '/login', 'logout', 'forgot-password', 'reset-password', 'admin/master-pages/setup-device/scan/check']);
    }

    #[DataProvider('skipped')]
    public function test_sign_in_screens_and_read_only_lookups_are_never_recorded_here(string $path): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create($path, 'POST', ['username' => 'x', 'password' => 'secret']), new Response('', 302));

        $this->assertSame($before, $this->rows()->count());
    }

    public function test_a_path_that_only_starts_like_a_skipped_one_is_recorded(): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create('/login/extra', 'POST'), new Response('', 200));

        $this->assertSame($before + 1, $this->rows()->count());
    }

    public function test_a_guest_is_never_recorded(): void
    {
        $this->app['auth']->forgetGuards();
        $before = $this->rows()->count();

        $this->terminate(Request::create('/admin/x', 'POST', ['a' => 1]), new Response('', 200), false);

        $this->assertSame($before, $this->rows()->count());
    }

    public function test_a_request_the_controller_already_recorded_in_detail_is_not_recorded_twice(): void
    {
        $request = Request::create('/admin/x', 'POST', ['a' => 1]);
        $request->attributes->set('audit.recorded', true);
        $before = $this->rows()->count();

        $this->terminate($request, new Response('', 200));

        $this->assertSame($before, $this->rows()->count());
    }

    public function test_secrets_in_the_submitted_form_are_never_stored(): void
    {
        $this->terminate(Request::create('/admin/x', 'POST', [
            '_token' => 'csrf-token-value', '_method' => 'PUT', 'name' => 'Visible Name',
            'password' => 'P@ssw0rd!', 'current_password' => 'Old#1234', 'password_confirmation' => 'P@ssw0rd!',
            'api_key' => 'sk-123', 'access_token' => 'tok', 'otp' => '123456', 'nic_number' => '900000000V', 'nic_or_id' => '911111111V',
            'card_number' => '4111111111111111', 'cvv' => '123', 'iban' => 'GB00', 'account_number' => '123456789', 'session_id' => 'abc',
            'nested' => ['secret_note' => 'hide me', 'plain' => 'show me'],
        ]), new Response('', 200));

        $input = $this->newest()->meta['input'];
        $stored = json_encode($this->newest()->meta);

        $this->assertSame('Visible Name', $input['name']);
        $this->assertSame('show me', $input['nested']['plain']);
        foreach (['P@ssw0rd!', 'Old#1234', 'sk-123', '"tok"', '123456"', '900000000V', '911111111V', '4111111111111111', 'hide me', 'csrf-token-value', 'abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, $secret);
        }
        $this->assertArrayNotHasKey('_token', $input);
        $this->assertArrayNotHasKey('_method', $input);
        $this->assertSame('[redacted]', $input['password']);
    }

    public function test_a_change_with_no_form_fields_stores_no_input(): void
    {
        $this->terminate(Request::create('/admin/x', 'DELETE'), new Response('', 200));

        $this->assertArrayNotHasKey('input', $this->newest()->meta);
        $this->assertArrayNotHasKey('files', $this->newest()->meta);
    }

    public function test_a_token_only_form_stores_no_input(): void
    {
        $this->terminate(Request::create('/admin/x', 'POST', ['_token' => 'abc', '_method' => 'DELETE']), new Response('', 200));

        $this->assertArrayNotHasKey('input', $this->newest()->meta);
    }

    public function test_the_form_size_limit_is_exactly_4000_bytes(): void
    {
        // {"k":"<n x>"} is n + 8 bytes of JSON
        $this->terminate(Request::create('/admin/x', 'POST', ['k' => str_repeat('x', 3992)]), new Response('', 200));
        $stored = $this->newest()->meta['input'];
        $this->assertArrayHasKey('k', $stored, '4000 bytes is still stored');
        $this->assertArrayNotHasKey('note', $stored);

        $this->terminate(Request::create('/admin/x', 'POST', ['k' => str_repeat('x', 3993)]), new Response('', 200));
        $this->assertSame(['k'], $this->newest()->meta['input']['fields'], '4001 bytes is not');
    }

    public function test_a_download_file_name_is_cut_at_120_characters(): void
    {
        $name = str_repeat('a', 130);

        $this->terminate(Request::create('/admin/reports/export', 'GET'), new Response('x', 200, ['Content-Disposition' => "attachment; filename=\"{$name}\""]));

        $this->assertSame(str_repeat('a', 120), $this->newest()->meta['file']);
    }

    public function test_a_very_large_form_stores_only_its_field_names(): void
    {
        $this->terminate(Request::create('/admin/x', 'POST', ['big' => str_repeat('x', 5000), 'small' => 'y']), new Response('', 200));

        $input = $this->newest()->meta['input'];
        $this->assertSame(['big', 'small'], $input['fields']);
        $this->assertSame('too large to store; field names only', $input['note']);
        $this->assertStringNotContainsString('xxxxx', json_encode($input));
    }

    public function test_a_form_with_more_than_40_fields_is_cut_and_says_how_many_were_left_out(): void
    {
        $data = [];
        foreach (range(1, 55) as $i) {
            $data["f{$i}"] = 'v';
        }

        $this->terminate(Request::create('/admin/x', 'POST', $data), new Response('', 200));

        $input = $this->newest()->meta['input'];
        $this->assertCount(41, $input);
        $this->assertSame('15 more fields not shown', $input['…']);
        $this->assertArrayHasKey('f40', $input);
        $this->assertArrayNotHasKey('f41', $input);
    }

    public function test_uploaded_files_are_recorded_by_name_and_size_never_by_content(): void
    {
        $file = UploadedFile::fake()->createWithContent('slip.csv', "secret,row\n" . str_repeat('x', 3000));
        $request = Request::create('/admin/x', 'POST', ['a' => 1], [], ['slip' => $file]);

        $this->terminate($request, new Response('', 200));

        $this->assertSame(['slip.csv (3 KB)'], $this->newest()->meta['files']);
        $this->assertStringNotContainsString('secret,row', json_encode($this->newest()->meta));
    }

    public function test_at_most_ten_files_are_listed_and_long_names_are_cut(): void
    {
        $files = [];
        foreach (range(1, 12) as $i) {
            $files["f{$i}"] = UploadedFile::fake()->create(str_repeat('n', 150) . "{$i}.pdf", 1);
        }

        $this->terminate(Request::create('/admin/x', 'POST', [], [], $files), new Response('', 200));

        $listed = $this->newest()->meta['files'];
        $this->assertCount(10, $listed);
        $this->assertSame(str_repeat('n', 100) . ' (1 KB)', $listed[0]);
    }

    public function test_a_file_download_is_recorded_with_its_name_and_filters(): void
    {
        $response = new Response('csv', 200, ['Content-Disposition' => 'attachment; filename="report-2026.csv"']);

        $this->terminate(Request::create('/admin/reports/export', 'GET', ['from' => '2026-01-01', 'dealer' => 7]), $response);

        $row = $this->newest();
        $this->assertSame('data.downloaded', $row->action);
        $this->assertSame('report-2026.csv', $row->meta['file']);
        $this->assertEquals(['from' => '2026-01-01', 'dealer' => '7'], $row->meta['filters']);
        $this->assertArrayNotHasKey('input', $row->meta);
    }

    public function test_the_download_check_ignores_letter_case_and_unquoted_names(): void
    {
        $this->terminate(Request::create('/admin/r', 'GET'), new Response('x', 200, ['Content-Disposition' => 'Attachment; filename=plain.pdf']));

        $this->assertSame('plain.pdf', $this->newest()->meta['file']);
    }

    public function test_an_inline_page_or_a_failed_download_is_not_recorded(): void
    {
        $before = $this->rows()->count();

        $this->terminate(Request::create('/admin/r', 'GET'), new Response('x', 200, ['Content-Disposition' => 'inline; filename="a.pdf"']));
        $this->terminate(Request::create('/admin/r', 'GET'), new Response('x', 404, ['Content-Disposition' => 'attachment; filename="a.pdf"']));

        $this->assertSame($before, $this->rows()->count());
    }

    public function test_a_failing_audit_write_never_breaks_the_page(): void
    {
        Schema::drop('audit_logs');   // rolled back with the test's transaction
        Log::spy();

        $this->terminate(Request::create('/admin/x', 'POST', ['a' => 1]), new Response('', 200));

        $this->addToAssertionCount(1);   // reaching here without an exception is the point
    }

    public function test_an_unexpected_error_inside_the_guard_is_logged_not_thrown(): void
    {
        $broken = new class extends Response {
            public function getStatusCode(): int
            {
                throw new \RuntimeException('boom');
            }
        };
        Log::spy();

        $this->terminate(Request::create('/admin/x', 'POST', ['a' => 1]), $broken);

        Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx = []) => $m === 'Activity audit failed' && ($ctx['error'] ?? null) === 'boom')->once();
    }

    // ================================================================== end to end through the real pipeline

    public function test_a_real_form_submission_leaves_one_automatic_entry_without_the_token(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $before = $this->rows()->count();

        $this->actingAs($admin)->post(route('admin.device-types.store'), ['_token' => 'x'])->assertSessionHasErrors();

        $this->assertSame($before + 1, $this->rows()->count());
        $row = $this->newest();
        $this->assertSame($admin->admin_id, $row->actor_id);
        $this->assertSame('POST', $row->subject_type);
        $this->assertSame('admin.device-types.store', $row->subject_id);
        $this->assertSame('refused (message shown to the user)', $row->meta['outcome']);
        $this->assertArrayNotHasKey('_token', $row->meta['input'] ?? []);
    }

    public function test_opening_a_page_through_the_real_pipeline_leaves_no_entry(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $before = $this->rows()->count();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame($before, $this->rows()->count());
    }
}