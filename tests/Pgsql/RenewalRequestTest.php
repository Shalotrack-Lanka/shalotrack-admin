<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * RENEWAL REQUESTS (a customer pays by bank transfer and sends the slip from the app), on real PostgreSQL.
 *   GET  admin.renewal-requests              the list (ADMIN only)
 *   GET  admin.renewal-requests.slip         shows the bank slip through a protected route
 *   POST admin.renewal-requests.decide       approve or reject
 *
 * Why it matters (simple words): this is MONEY. The rules the portal promises are:
 *   - Approve never changes a subscription. It only closes the request, and only when the device row ALREADY shows
 *     the paid period the customer asked for (same device, same vehicle, Paid, same plan, ends in the future,
 *     saved after the slip arrived).
 *   - The portal never trusts the browser: it re-reads the waiting request from the mobile API before deciding.
 *   - A rejection must have a reason (the customer sees it).
 *   - If the mobile API is down the page says so. It never shows an empty list that looks like "no requests".
 *   - The bank slip is shown only through an audited, rate-limited route, only as an image or PDF.
 *
 * The mobile API is FAKED (nothing real is called). One login per test. Everything is rolled back.
 */
class RenewalRequestTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const API      = 'https://api.test';
    private const SYNC_KEY = 'sync-key-for-tests';

    /** What the fake API says: ok | down | error | not_json */
    private string $api = 'ok';
    /** What the fake API answers to a decision: ok | refuse | refuse_message | down */
    private string $decision = 'ok';
    /** What the fake API answers to a slip request: image/png etc. | missing | down */
    private string $slip = 'image/png';

    /** Requests the fake API holds, by status. */
    private array $requests = [];

    /** Every call the portal made to the fake API: [method, path, query, body, key]. */
    private array $sent = [];

    private ?Admin $admin = null;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.shalotrack_api.base_url' => self::API,
            'services.shalotrack_api.sync_key' => self::SYNC_KEY,
        ]);

        Http::fake(function (ClientRequest $request) {
            $parts = parse_url($request->url());
            $path  = $parts['path'] ?? '';
            parse_str($parts['query'] ?? '', $query);

            // The admin sidebar asks the API for the complaint count on every page. That is background noise for
            // these tests: answer it like the real API would (or fail like it when the API is down), but do not
            // count it as a renewal call.
            if (str_starts_with($path, '/api/internal/complaints/')) {
                if ($this->api === 'down') {
                    throw new ConnectionException('api is down');
                }

                return $this->api === 'error' ? Http::response([], 500) : Http::response(['data' => []], 200);
            }

            $this->sent[] = [
                'method' => $request->method(),
                'path'   => $path,
                'query'  => $query,
                'body'   => $request->data(),
                'key'    => $request->header('X-Admin-Sync-Key')[0] ?? null,
            ];

            if ($this->api === 'down') {
                throw new ConnectionException('api is down');
            }
            if ($this->api === 'error') {
                return Http::response(['message' => 'boom'], 500);
            }

            // GET /api/internal/renewals?status=...
            if ($request->method() === 'GET' && $path === '/api/internal/renewals') {
                if ($this->api === 'not_json') {
                    return Http::response('<html>gateway timeout</html>', 200);
                }
                $status = $query['status'] ?? 'PendingReview';
                $rows = $status === 'all'
                    ? array_merge(...array_values($this->requests ?: [[]]))
                    : ($this->requests[$status] ?? []);

                return Http::response(['data' => array_values($rows)], 200);
            }

            // GET /api/internal/renewals/{id}/slip
            if ($request->method() === 'GET' && preg_match('#^/api/internal/renewals/[^/]+/slip$#', $path)) {
                if ($this->slip === 'down') {
                    throw new ConnectionException('api is down');
                }
                if ($this->slip === 'missing') {
                    return Http::response('', 404);
                }

                return Http::response('SLIP-BYTES', 200, ['Content-Type' => $this->slip]);
            }

            // POST /api/internal/renewals/{id}/decision
            if ($request->method() === 'POST' && preg_match('#^/api/internal/renewals/[^/]+/decision$#', $path)) {
                return match ($this->decision) {
                    'down'           => throw new ConnectionException('api is down'),
                    'refuse'         => Http::response(['errors' => ['The slip does not match the request.']], 422),
                    'refuse_message' => Http::response(['message' => 'Already decided by someone else.'], 409),
                    default          => Http::response(['message' => 'Done.'], 200),
                };
            }

            return Http::response([], 404);
        });
    }

    // ------------------------------------------------------------------ helpers

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    /** A bound device that really shows the paid 1 Year period (every check passes). */
    private function boundDevice(array $overrides = []): ActivatedDevice
    {
        $n = ++$this->seq;

        return ActivatedDevice::create(array_merge([
            'vehicle_id'             => (string) Str::uuid(),
            'customer_id'            => (string) Str::uuid(),
            'customer_name'          => 'Autotest Customer ' . $n,
            'vehicle_number'         => 'AUTO-RQ' . $n,
            'model'                  => 'Autotest Model',
            'has_gps_device'         => true,
            'imei_number'            => '99900000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'sim_number'             => '07200000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'device_category'        => 'AUTOTEST-CAT',
            'status'                 => 'Activated',
            'payment_status'         => 'Paid',
            'subscription_model'     => '1 Year',
            'subscription_start_date' => now()->subDay(),
            'subscription_end_date'  => now()->addYear(),
            'original_activated_at'  => now()->subDay(),
        ], $overrides));
    }

    /** What the mobile API reports for a request about this device. */
    private function row(ActivatedDevice $device, array $overrides = []): array
    {
        return array_merge([
            'renewalRequestId' => (string) Str::uuid(),
            'status'           => 'PendingReview',
            'vehicleId'        => $device->vehicle_id,
            'vehicleNumber'    => $device->vehicle_number,
            'customerName'     => 'Autotest Customer',
            'customerPhone'    => '0711234567',
            'imeiNumber'       => $device->imei_number,
            'durationModel'    => '1 Year',
            'amountLkr'        => 2999,
            'slipUploadedAt'   => now()->subHour()->toIso8601String(),
            'slipSha256'       => str_repeat('a', 64),
            'hasSlip'          => true,
        ], $overrides);
    }

    /** Puts a row on the fake API under its status. */
    private function hold(array $row): array
    {
        $this->requests[$row['status']][] = $row;

        return $row;
    }

    private function decide(string $id, array $form)
    {
        return $this->actingAs($this->admin())->post(route('admin.renewal-requests.decide', $id), $form);
    }

    private function calls(string $method, string $pathPattern): array
    {
        return array_values(array_filter(
            $this->sent,
            fn ($c) => $c['method'] === $method && preg_match($pathPattern, $c['path']) === 1,
        ));
    }

    private function decisionCalls(): array
    {
        return $this->calls('POST', '#/decision$#');
    }

    private function audits(string $action)
    {
        return AuditLog::where('action', $action)->get();
    }

    private function apiError(): string
    {
        return (string) session('errors')->first('api');
    }

    // ------------------------------------------------------------------ the list

    public function test_the_list_shows_waiting_requests_and_says_when_the_device_is_ready(): void
    {
        $ready = $this->boundDevice();
        $this->hold($this->row($ready, ['customerName' => 'Ready Customer']));

        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertSee('Ready Customer')
            ->assertSee($ready->vehicle_number)
            ->assertSee('Ready to approve');

        $this->assertSame('PendingReview', $this->calls('GET', '#^/api/internal/renewals$#')[0]['query']['status']);
        $this->assertSame(self::SYNC_KEY, $this->sent[0]['key'], 'the portal must send its secret key to the API');
    }

    #[DataProvider('statuses')]
    public function test_every_status_tab_opens_and_asks_the_api_for_that_status(string $status): void
    {
        $this->actingAs($this->admin())->get(route('admin.renewal-requests', ['status' => $status]))->assertOk();

        $this->assertSame($status, $this->calls('GET', '#^/api/internal/renewals$#')[0]['query']['status']);
    }

    public static function statuses(): array
    {
        return array_map(fn ($s) => [$s], ['PendingReview', 'AwaitingSlip', 'Approved', 'Rejected', 'Cancelled', 'all']);
    }

    public function test_an_unknown_or_twisted_status_falls_back_to_waiting_for_review(): void
    {
        foreach (['pendingreview', 'Approved;drop table', '', '../etc', 'ALL'] as $junk) {
            $this->sent = [];
            $this->actingAs($this->admin())->get(route('admin.renewal-requests', ['status' => $junk]))->assertOk();
            $this->assertSame('PendingReview', $this->calls('GET', '#^/api/internal/renewals$#')[0]['query']['status'], "status '{$junk}'");
        }

        $this->sent = [];
        $this->actingAs($this->admin())->get('/admin/renewal-requests?status[]=Approved')->assertOk();
        $this->assertSame('PendingReview', $this->calls('GET', '#^/api/internal/renewals$#')[0]['query']['status'], 'a list is not a status');
    }

    public function test_an_empty_list_says_nothing_here(): void
    {
        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertSee('Nothing here.')
            ->assertDontSee('could not be reached');
    }

    #[DataProvider('brokenApis')]
    public function test_when_the_api_is_down_the_page_says_so_instead_of_showing_an_empty_list(string $mode): void
    {
        $this->api = $mode;

        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertSee('The mobile API could not be reached')
            ->assertDontSee('Nothing here.');
    }

    public static function brokenApis(): array
    {
        return [
            'connection fails'        => ['down'],
            'api answers 500'         => ['error'],
            'api answers a web page'  => ['not_json'],
        ];
    }

    public function test_a_request_for_a_device_that_is_not_bound_is_listed_as_not_ready(): void
    {
        $this->hold($this->row($this->boundDevice(), ['imeiNumber' => '000000000000000', 'vehicleNumber' => 'GHOST-1']));

        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertSee('GHOST-1')
            ->assertSee('not bound in the admin portal')
            ->assertSee('Not ready: ')
            ->assertDontSee('Ready to approve');
    }

    public function test_text_from_customers_is_shown_safely_never_as_html(): void
    {
        $device = $this->boundDevice();
        $this->hold($this->row($device, ['customerName' => '<script>alert(1)</script>', 'customerNote' => '<img src=x onerror=alert(2)>']));

        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_view_slip_link_shows_only_when_a_slip_exists(): void
    {
        $with = $this->row($this->boundDevice(), ['hasSlip' => true]);
        $without = $this->row($this->boundDevice(), ['hasSlip' => false]);
        $this->hold($with);
        $this->hold($without);

        $html = $this->actingAs($this->admin())->get(route('admin.renewal-requests'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.renewal-requests.slip', $with['renewalRequestId']), $html);
        $this->assertStringNotContainsString(route('admin.renewal-requests.slip', $without['renewalRequestId']), $html);
    }

    // ------------------------------------------------------------------ "ready" rules (list and approve use the same ones)

    /** Each case: how to spoil the device or the request, and the words the admin must be told. */
    public static function notReadyCases(): array
    {
        return [
            'device not bound'            => ['unbound', 'not bound to a customer'],
            'bound to another vehicle'    => ['other_vehicle', 'bound to a different vehicle'],
            'device not paid'             => ['not_paid', 'not marked Paid yet'],
            'different plan'              => ['other_plan', 'but the customer asked for'],
            'plan is empty'               => ['no_plan', 'but the customer asked for'],
            'subscription already ended'  => ['ended', 'no future end date'],
            'no end date at all'          => ['no_end', 'no future end date'],
            'device not updated since slip' => ['stale', 'has not been updated since the customer sent the slip'],
        ];
    }

    /** @return array{0: ActivatedDevice, 1: array} the device and the request, spoiled in one way */
    private function spoiled(string $how): array
    {
        $device = $this->boundDevice();
        $row    = $this->row($device);

        switch ($how) {
            case 'unbound':       $row['imeiNumber'] = '000000000000001'; break;
            case 'other_vehicle': $row['vehicleId'] = (string) Str::uuid(); break;
            case 'not_paid':      $device->forceFill(['payment_status' => 'not-Paid'])->save(); break;
            case 'other_plan':    $row['durationModel'] = '2 Years'; break;
            case 'no_plan':       $device->forceFill(['subscription_model' => null])->save(); break;
            case 'ended':         $device->forceFill(['subscription_end_date' => now()->subDay()])->save(); break;
            case 'no_end':        $device->forceFill(['subscription_end_date' => null])->save(); break;
            case 'stale':         $row['slipUploadedAt'] = now()->addHour()->toIso8601String(); break;
        }

        return [$device, $row];
    }

    #[DataProvider('notReadyCases')]
    public function test_the_list_explains_why_a_request_is_not_ready(string $how, string $words): void
    {
        [, $row] = $this->spoiled($how);
        $this->hold($row);

        $this->actingAs($this->admin())->get(route('admin.renewal-requests'))
            ->assertOk()
            ->assertSee('Not ready: ')
            ->assertSee($words)
            ->assertDontSee('Ready to approve');
    }

    #[DataProvider('notReadyCases')]
    public function test_approve_is_refused_when_the_device_does_not_show_the_renewal(string $how, string $words): void
    {
        [$device, $row] = $this->spoiled($how);
        $this->hold($row);
        $before = $device->fresh()->only(['payment_status', 'subscription_model', 'subscription_end_date']);

        $response = $this->decide($row['renewalRequestId'], ['decision' => 'approve']);

        $response->assertSessionHasErrors('api');
        $this->assertStringContainsString('Not approved: ', $this->apiError());
        $this->assertStringContainsString($words, $this->apiError());
        $this->assertCount(0, $this->decisionCalls(), 'the API must not be told to approve');
        $this->assertCount(0, $this->audits('renewal.approved'));
        $this->assertEquals($before, $device->fresh()->only(['payment_status', 'subscription_model', 'subscription_end_date']));
    }

    public function test_the_vehicle_is_compared_ignoring_upper_and_lower_case(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device, ['vehicleId' => strtoupper($device->vehicle_id)]));

        $this->decide($row['renewalRequestId'], ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->decisionCalls());
    }

    public function test_a_request_with_no_slip_time_skips_only_the_stale_check(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device, ['slipUploadedAt' => null]));

        $this->decide($row['renewalRequestId'], ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->decisionCalls());
    }

    // ------------------------------------------------------------------ approve

    public function test_approving_closes_the_request_audits_it_and_does_not_touch_the_device(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));
        $before = $device->fresh()->only(['payment_status', 'subscription_model', 'subscription_start_date', 'subscription_end_date', 'updated_at']);

        $response = $this->decide($row['renewalRequestId'], ['decision' => 'approve']);

        $response->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Renewal approved for {$device->vehicle_number}. The customer was notified.");

        // What the API was told: the decision, who decided, and the slip fingerprint, nothing else.
        $calls = $this->decisionCalls();
        $this->assertCount(1, $calls);
        $this->assertSame("/api/internal/renewals/{$row['renewalRequestId']}/decision", $calls[0]['path']);
        $this->assertSame(self::SYNC_KEY, $calls[0]['key']);
        $this->assertEquals(['decision' => 'approve', 'decidedBy' => 'Autotest ADMIN', 'slipSha256' => str_repeat('a', 64)], $calls[0]['body']);

        // The audit trail says who approved what.
        $logs = $this->audits('renewal.approved');
        $this->assertCount(1, $logs);
        $this->assertSame('vehicle', $logs[0]->subject_type);
        $this->assertSame($device->vehicle_id, $logs[0]->subject_id);
        $this->assertSame($this->admin()->admin_id, $logs[0]->actor_id);
        $this->assertSame($device->imei_number, $logs[0]->meta['imei']);
        $this->assertSame('1 Year', $logs[0]->meta['duration']);
        $this->assertSame($row['renewalRequestId'], $logs[0]->meta['request_id']);

        // Approve closes the request only. The subscription is exactly as staff left it.
        $this->assertEquals($before, $device->fresh()->only(array_keys($before)));
    }

    public function test_the_portal_decides_from_the_apis_copy_never_from_what_the_browser_sends(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));

        $this->decide($row['renewalRequestId'], [
            'decision'      => 'approve',
            'vehicleNumber' => 'HACKED-1',
            'imeiNumber'    => '123456789012345',
            'durationModel' => '6 Years',
            'slipSha256'    => str_repeat('f', 64),
            'decidedBy'     => 'Someone Else',
        ])->assertSessionHas('success', "Renewal approved for {$device->vehicle_number}. The customer was notified.");

        $this->assertEquals(
            ['decision' => 'approve', 'decidedBy' => 'Autotest ADMIN', 'slipSha256' => str_repeat('a', 64)],
            $this->decisionCalls()[0]['body'],
        );
        $this->assertNotEmpty($this->calls('GET', '#^/api/internal/renewals$#'), 'the waiting request is re-read first');
    }

    public function test_a_request_that_is_no_longer_waiting_is_not_decided(): void
    {
        $device = $this->boundDevice();
        $gone = $this->row($device, ['status' => 'Approved']);   // someone else already approved it
        $this->hold($gone);

        $response = $this->decide($gone['renewalRequestId'], ['decision' => 'approve']);

        $response->assertSessionHasErrors('api');
        $this->assertStringContainsString('no longer waiting for review', $this->apiError());
        $this->assertCount(0, $this->decisionCalls());
        $this->assertCount(0, $this->audits('renewal.approved'));

        // An id that never existed gets the same answer.
        $this->decide((string) Str::uuid(), ['decision' => 'reject', 'reason' => 'x'])->assertSessionHasErrors('api');
        $this->assertCount(0, $this->decisionCalls());
    }

    public function test_when_the_api_is_down_nothing_is_decided(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));
        $this->api = 'down';

        $this->decide($row['renewalRequestId'], ['decision' => 'approve'])->assertSessionHasErrors('api');

        $this->assertStringContainsString('could not be reached. Nothing was changed.', $this->apiError());
        $this->assertCount(0, $this->decisionCalls());
        $this->assertCount(0, $this->audits('renewal.approved'));
    }

    #[DataProvider('refusingApis')]
    public function test_when_the_api_refuses_the_decision_the_admin_sees_why_and_no_audit_is_written(string $mode, string $expected, string $decision): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));
        $this->decision = $mode;

        $this->decide($row['renewalRequestId'], $decision === 'approve' ? ['decision' => 'approve'] : ['decision' => 'reject', 'reason' => 'Slip is blurry'])
            ->assertSessionHasErrors('api');

        $this->assertStringContainsString($expected, $this->apiError());
        $this->assertCount(0, $this->audits('renewal.approved'));
        $this->assertCount(0, $this->audits('renewal.rejected'));
    }

    public static function refusingApis(): array
    {
        return [
            'approve, api gives a reason'      => ['refuse', 'The slip does not match the request.', 'approve'],
            'approve, api gives only a message' => ['refuse_message', 'Already decided by someone else.', 'approve'],
            'approve, api goes down mid-call'  => ['down', 'could not be reached', 'approve'],
            'reject, api gives a reason'       => ['refuse', 'The slip does not match the request.', 'reject'],
            'reject, api goes down mid-call'   => ['down', 'could not be reached', 'reject'],
        ];
    }

    // ------------------------------------------------------------------ reject

    public function test_rejecting_sends_the_reason_and_is_audited_with_it(): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));

        $this->decide($row['renewalRequestId'], ['decision' => 'reject', 'reason' => '  Slip is blurry, please send again  '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Renewal rejected for {$device->vehicle_number}. The customer was notified.");

        $calls = $this->decisionCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals(['decision' => 'reject', 'decidedBy' => 'Autotest ADMIN', 'reason' => 'Slip is blurry, please send again'], $calls[0]['body']);

        $logs = $this->audits('renewal.rejected');
        $this->assertCount(1, $logs);
        $this->assertSame($device->vehicle_id, $logs[0]->subject_id);
        $this->assertSame('Slip is blurry, please send again', $logs[0]->changes['reason']['to']);
        $this->assertSame($row['renewalRequestId'], $logs[0]->meta['request_id']);
    }

    public function test_a_request_can_be_rejected_even_when_the_device_is_not_ready(): void
    {
        [, $row] = $this->spoiled('not_paid');
        $this->hold($row);

        $this->decide($row['renewalRequestId'], ['decision' => 'reject', 'reason' => 'Payment not received'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->decisionCalls());
        $this->assertCount(1, $this->audits('renewal.rejected'));
    }

    public function test_the_longest_allowed_reason_is_accepted(): void
    {
        $row = $this->hold($this->row($this->boundDevice()));

        $this->decide($row['renewalRequestId'], ['decision' => 'reject', 'reason' => str_repeat('r', 300)])->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('r', 300), $this->decisionCalls()[0]['body']['reason']);
    }

    #[DataProvider('badDecisionForms')]
    public function test_a_bad_decision_form_is_refused_before_anything_is_called(array $form): void
    {
        $row = $this->hold($this->row($this->boundDevice()));
        $this->sent = [];

        $this->decide($row['renewalRequestId'], $form)->assertSessionHasErrors();

        $this->assertSame([], $this->sent, 'nothing may reach the API');
        $this->assertCount(0, $this->audits('renewal.approved'));
        $this->assertCount(0, $this->audits('renewal.rejected'));
    }

    public static function badDecisionForms(): array
    {
        return [
            'nothing sent'                => [[]],
            'only a reason'               => [['reason' => 'x']],
            'unknown decision'            => [['decision' => 'delete']],
            'capital letters'             => [['decision' => 'APPROVE']],
            'decision is a list'          => [['decision' => ['approve']]],
            'reject with no reason'       => [['decision' => 'reject']],
            'reject with a blank reason'  => [['decision' => 'reject', 'reason' => '     ']],
            'reason too long'             => [['decision' => 'reject', 'reason' => str_repeat('r', 301)]],
            'reason is a list'            => [['decision' => 'reject', 'reason' => ['a', 'b']]],
        ];
    }

    // ------------------------------------------------------------------ the bank slip

    #[DataProvider('slipTypesAllowed')]
    public function test_a_slip_is_shown_only_through_the_protected_route_and_is_audited(string $type, string $expectedType): void
    {
        $this->slip = $type;
        $id = (string) Str::uuid();

        $response = $this->actingAs($this->admin())->get(route('admin.renewal-requests.slip', $id));

        $response->assertOk();
        $this->assertSame('SLIP-BYTES', $response->getContent());
        $this->assertSame($expectedType, $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $call = $this->calls('GET', '#/slip$#')[0];
        $this->assertSame("/api/internal/renewals/{$id}/slip", $call['path']);
        $this->assertSame(self::SYNC_KEY, $call['key']);

        $logs = $this->audits('renewal.slip_viewed');
        $this->assertCount(1, $logs);
        $this->assertSame('renewal_request', $logs[0]->subject_type);
        $this->assertSame($id, $logs[0]->subject_id);
    }

    public static function slipTypesAllowed(): array
    {
        return [
            'jpeg'                     => ['image/jpeg', 'image/jpeg'],
            'png'                      => ['image/png', 'image/png'],
            'pdf'                      => ['application/pdf', 'application/pdf'],
            'png with extra text'      => ['image/png; charset=binary', 'image/png'],
            'jpeg in capital letters'  => ['IMAGE/JPEG', 'image/jpeg'],
        ];
    }

    #[DataProvider('slipTypesRefused')]
    public function test_a_slip_of_any_other_type_is_never_passed_to_the_browser(string $type): void
    {
        $this->slip = $type;

        $this->actingAs($this->admin())->get(route('admin.renewal-requests.slip', (string) Str::uuid()))->assertNotFound();

        $this->assertCount(0, $this->audits('renewal.slip_viewed'));
    }

    public static function slipTypesRefused(): array
    {
        return [
            'web page'          => ['text/html'],
            'svg picture'       => ['image/svg+xml'],
            'script'            => ['application/javascript'],
            'gif'               => ['image/gif'],
            'unknown bytes'     => ['application/octet-stream'],
        ];
    }

    #[DataProvider('slipFailures')]
    public function test_a_missing_slip_or_a_dead_api_is_a_404_with_no_audit_entry(string $mode): void
    {
        $this->slip = $mode;

        $this->actingAs($this->admin())->get(route('admin.renewal-requests.slip', (string) Str::uuid()))->assertNotFound();

        $this->assertCount(0, $this->audits('renewal.slip_viewed'));
    }

    public static function slipFailures(): array
    {
        return ['slip does not exist' => ['missing'], 'api is down' => ['down']];
    }

    public function test_an_id_that_tries_to_climb_out_of_the_slip_address_never_reaches_the_api(): void
    {
        $this->actingAs($this->admin());

        foreach (['..%2F..%2Fadmin%2Fusers', '%2E%2E%2Fsecret'] as $evil) {
            $this->get('/admin/renewal-requests/' . $evil . '/slip')->assertNotFound();
        }

        $this->assertSame([], $this->sent);
    }

    public function test_the_slip_route_is_rate_limited(): void
    {
        Cache::flush();
        $this->actingAs($this->admin());
        $id = (string) Str::uuid();

        for ($i = 1; $i <= 60; $i++) {
            $this->get(route('admin.renewal-requests.slip', $id))->assertOk();
        }
        $this->get(route('admin.renewal-requests.slip', $id))->assertStatus(429);
    }

    // ------------------------------------------------------------------ who may use it

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_list_view_or_decide_and_the_api_is_never_called(string $role): void
    {
        $device = $this->boundDevice();
        $row = $this->hold($this->row($device));
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.renewal-requests'))->assertForbidden();
        $this->get(route('admin.renewal-requests.slip', $row['renewalRequestId']))->assertForbidden();
        $this->post(route('admin.renewal-requests.decide', $row['renewalRequestId']), ['decision' => 'approve'])->assertForbidden();

        $this->assertSame([], $this->sent, "{$role} must not cause any call to the API");
        $this->assertCount(0, $this->audits('renewal.approved'));
        $this->assertCount(0, $this->audits('renewal.slip_viewed'));
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $id = (string) Str::uuid();

        $this->get(route('admin.renewal-requests'))->assertRedirect('/login');
        $this->get(route('admin.renewal-requests.slip', $id))->assertRedirect('/login');
        $this->post(route('admin.renewal-requests.decide', $id), ['decision' => 'approve'])->assertRedirect('/login');

        $this->assertSame([], $this->sent);
    }
}