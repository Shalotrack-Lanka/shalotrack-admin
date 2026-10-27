<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * COMPLAINTS AND ENQUIRIES, on real PostgreSQL.
 *
 * Complaints are kept by the mobile API, not in this database. This portal is the window the admin and the
 * dealer use: list, reply, escalate to the company (dealer), resolve, close, and two "anything new?" polls.
 * The rules the portal promises (simple words):
 *   - A dealer only ever sees and acts on their OWN complaints. The dealer id sent to the API comes from the
 *     login, never from anything the browser sends.
 *   - Only ADMIN opens /admin/complaints, only DEALER opens /dealer/complaints. Everybody else gets 403.
 *   - A reply is 1 to 2000 characters. Ids in the URL are letters, digits and dashes only (up to 64), so they
 *     cannot smuggle a new path or query into the API address.
 *   - If the API is down or answers with an error, the admin/dealer sees a clear message. Never a crash page,
 *     and never the API's raw error text.
 *   - Text from customers is shown as text, never run as a script.
 *   - The "new complaint" and "new reply" polls tell about NEW things only, and an admin or dealer is not
 *     told about their own reply.
 *
 * The API is FAKED (no real network). Nothing is written to the database except test logins and one dealer,
 * rolled back after every test.
 */
class ComplaintsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CUSTOMER = 0;
    private const DEALER = 1;
    private const ADMIN = 2;

    private const WITH_DEALER = 0;
    private const WITH_ADMIN = 1;
    private const RESOLVED = 2;
    private const CLOSED = 3;

    /** @var array<int,array> what the fake API returns for the admin (open complaints) */
    private array $adminList = [];
    private array $adminResolved = [];
    /** @var array<int,array<int,array>> dealer id => complaints */
    private array $dealerLists = [];
    /** ok | down | error | not_json */
    private string $apiMode = 'ok';
    /** @var array<int,array{path:string,query:?string,body:array}> every POST the portal made */
    private array $posts = [];
    /** @var array<int,string> every GET path the portal made */
    private array $gets = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        config([
            'services.shalotrack_api.base_url' => 'https://api.test',
            'services.shalotrack_api.sync_key' => 'sync-key-for-tests',
        ]);

        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = parse_url($request->url(), PHP_URL_QUERY);

            if ($this->apiMode === 'down') {
                throw new ConnectionException('api is down');
            }

            $isPost = $request->method() === 'POST';
            if ($isPost) {
                $this->posts[] = ['path' => $path, 'query' => $query, 'body' => $request->data()];
            } else {
                $this->gets[] = $path;
            }

            if ($this->apiMode === 'error') {
                return Http::response('<h1>SECRET internal stack trace</h1>', 500);
            }
            if ($this->apiMode === 'not_json') {
                return Http::response('<html>maintenance</html>', 200);
            }

            if (! $isPost && $path === '/api/internal/complaints/for-admin') {
                return Http::response(['data' => $this->adminList], 200);
            }
            if (! $isPost && $path === '/api/internal/complaints/for-admin/resolved') {
                return Http::response(['data' => $this->adminResolved], 200);
            }
            if (! $isPost && preg_match('#^/api/internal/complaints/by-dealer/(\d+)$#', $path, $m)) {
                return Http::response(['data' => $this->dealerLists[(int) $m[1]] ?? []], 200);
            }
            if ($isPost && preg_match('#^/api/internal/complaints/[A-Za-z0-9\-]+/(reply|resolve|close|escalate)$#', $path)) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response([], 404);
        });
    }

    // ------------------------------------------------------------------ builders

    private function complaint(string $id, int $status = self::WITH_ADMIN, array $replies = [], array $overrides = []): array
    {
        return array_merge([
            'complaintId'   => $id,
            'status'        => $status,
            'category'      => 0,
            'description'   => "Description of {$id}",
            'vehicleNumber' => 'WP CAB-1234',
            'make'          => 'Toyota',
            'model'         => 'Aqua',
            'dealerName'    => 'Autotest Dealer Name',
            'createdAt'     => '2026-10-20T10:00:00Z',
            'replies'       => $replies,
        ], $overrides);
    }

    private function reply(int $authorType, string $message = 'hello', string $author = 'Someone'): array
    {
        return ['message' => $message, 'authorType' => $authorType, 'authorName' => $author, 'createdAt' => '2026-10-20T11:00:00Z'];
    }

    private function makeDealerRecord(): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create([
            'full_name'     => "Autotest Complaints Dealer {$n}",
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+complaints-{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    /** @return array{0:Admin,1:Dealer} a DEALER login that is linked to a dealer record */
    private function dealerLogin(): array
    {
        $dealer = $this->makeDealerRecord();
        $login = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);

        return [$login, $dealer];
    }

    private ?Admin $admin = null;

    /** One admin login per test (a second login would sign the first one out). */
    private function asAdmin(): static
    {
        return $this->actingAs($this->admin ??= $this->makeAdmin('ADMIN'));
    }

    /** POSTs the portal made that are complaint actions (not the sidebar's GETs). */
    private function postsTo(string $action): array
    {
        return array_values(array_filter($this->posts, fn ($p) => str_ends_with($p['path'], '/' . $action)));
    }

    // ================================================================== ADMIN: list pages

    public function test_the_admin_list_shows_the_complaints_from_the_api(): void
    {
        $this->adminList = [
            $this->complaint('c-100', self::WITH_ADMIN, [$this->reply(self::DEALER, 'Dealer says hello', 'Kamal')]),
            $this->complaint('c-200', self::WITH_ADMIN, [], ['description' => 'Tracker will not switch on']),
        ];

        $this->asAdmin()->get(route('admin.complaints.index'))
            ->assertOk()
            ->assertSee('Description of c-100')
            ->assertSee('Tracker will not switch on')
            ->assertSee('Dealer says hello')
            ->assertSee('Autotest Dealer Name');
    }

    public function test_an_empty_list_opens_without_an_error(): void
    {
        $this->asAdmin()->get(route('admin.complaints.index'))->assertOk()->assertSessionHasNoErrors();
    }

    public function test_text_written_by_customers_is_shown_as_text_and_never_run(): void
    {
        $this->adminList = [$this->complaint('c-xss', self::WITH_ADMIN, [$this->reply(self::CUSTOMER, '<script>alert(2)</script>', '<img src=x onerror=alert(3)>')], [
            'description' => '<script>alert(1)</script>',
        ])];
        $this->adminResolved = [$this->complaint('c-xss2', self::RESOLVED, [], ['description' => '<script>alert(4)</script>'])];

        foreach (['admin.complaints.index', 'admin.complaints.resolved'] as $route) {
            $html = $this->asAdmin()->get(route($route))->assertOk()->getContent();

            foreach (['<script>alert(1)</script>', '<script>alert(2)</script>', '<script>alert(4)</script>', '<img src=x onerror=alert(3)>'] as $bad) {
                $this->assertStringNotContainsString($bad, $html, "{$route}: {$bad}");
            }
        }
    }

    public static function brokenApis(): array
    {
        return ['api is down' => ['down'], 'api answers 500' => ['error'], 'api answers a web page' => ['not_json']];
    }

    #[DataProvider('brokenApis')]
    public function test_when_the_api_is_broken_the_admin_list_pages_still_open_with_a_message_and_no_raw_error(string $mode): void
    {
        $this->apiMode = $mode;

        foreach (['admin.complaints.index', 'admin.complaints.resolved'] as $route) {
            $response = $this->asAdmin()->get(route($route))->assertOk();

            if ($mode !== 'not_json') {
                $response->assertSee('Complaints are unavailable right now', false);   // shown on the page itself
            }
            $this->assertStringNotContainsString('SECRET internal stack trace', $response->getContent());
        }
    }

    public function test_the_resolved_page_lists_what_the_api_says_is_resolved(): void
    {
        $this->adminResolved = [$this->complaint('c-9', self::RESOLVED, [], ['description' => 'Fixed battery problem'])];

        $this->asAdmin()->get(route('admin.complaints.resolved'))->assertOk()->assertSee('Fixed battery problem');
    }

    public function test_the_old_view_complaints_address_redirects_to_the_real_page(): void
    {
        $this->asAdmin()->get(route('admin.view-complains'))->assertStatus(301)->assertRedirect(route('admin.complaints.index'));
    }

    public function test_the_three_static_enquiry_pages_open_for_an_admin(): void
    {
        foreach (['admin.troubleshoot', 'admin.feedback', 'admin.device-replace-request'] as $route) {
            $this->asAdmin()->get(route($route))->assertOk();
        }
    }

    // ================================================================== ADMIN: reply / resolve / close

    public function test_an_admin_reply_goes_to_the_api_as_an_admin_reply(): void
    {
        $this->asAdmin()->post(route('admin.complaints.reply', 'c-100'), ['message' => '  We are on it.  '])
            ->assertRedirect()->assertSessionHas('success', 'Reply sent.');

        $sent = $this->postsTo('reply');
        $this->assertCount(1, $sent);
        $this->assertSame('/api/internal/complaints/c-100/reply', $sent[0]['path']);
        $this->assertSame('We are on it.', $sent[0]['body']['message'], 'spaces around the text are removed');
        $this->assertSame(self::ADMIN, $sent[0]['body']['authorType']);
        $this->assertNotSame('', trim((string) $sent[0]['body']['authorName']));
    }

    public function test_a_reply_cannot_pretend_to_come_from_someone_else(): void
    {
        $this->asAdmin()->post(route('admin.complaints.reply', 'c-100'), [
            'message' => 'hi', 'authorType' => 0, 'authorName' => 'The Customer', 'dealerId' => 5,
        ]);

        $body = $this->postsTo('reply')[0]['body'];
        $this->assertSame(self::ADMIN, $body['authorType']);
        $this->assertNotSame('The Customer', $body['authorName']);
        $this->assertArrayNotHasKey('dealerId', $body);
    }

    public static function badMessages(): array
    {
        return [
            'missing'        => [null],
            'empty'          => [''],
            'only spaces'    => ['     '],
            'a list'         => [['hello']],
            'over 2000'      => [str_repeat('a', 2001)],
        ];
    }

    #[DataProvider('badMessages')]
    public function test_a_bad_reply_message_is_refused_and_nothing_is_sent(mixed $message): void
    {
        $body = $message === null ? [] : ['message' => $message];

        $this->asAdmin()->post(route('admin.complaints.reply', 'c-100'), $body)->assertSessionHasErrors('message');

        $this->assertSame([], $this->posts);
    }

    public function test_a_reply_of_exactly_2000_characters_is_accepted(): void
    {
        $this->asAdmin()->post(route('admin.complaints.reply', 'c-100'), ['message' => str_repeat('a', 2000)])->assertSessionHas('success');
    }

    public function test_resolve_and_close_call_the_api_and_clear_the_cached_sidebar_count(): void
    {
        foreach (['resolve' => 'Complaint marked as resolved.', 'close' => 'Complaint closed.'] as $action => $message) {
            Cache::put('admin_complaints_count', 7, 60);

            $this->asAdmin()->post(route("admin.complaints.{$action}", 'c-300'))->assertRedirect()->assertSessionHas('success', $message);

            $this->assertSame('/api/internal/complaints/c-300/' . $action, $this->postsTo($action)[0]['path']);
            $this->assertFalse(Cache::has('admin_complaints_count'), "{$action} must clear the cached count");
        }
    }

    public static function adminActions(): array
    {
        return ['reply' => ['reply', 'admin.complaints.reply', ['message' => 'hi']], 'resolve' => ['resolve', 'admin.complaints.resolve', []], 'close' => ['close', 'admin.complaints.close', []]];
    }

    #[DataProvider('adminActions')]
    public function test_when_the_api_fails_the_admin_gets_a_clear_error_and_no_success(string $action, string $route, array $body): void
    {
        foreach (['error', 'down'] as $mode) {
            $this->apiMode = $mode;
            Cache::put('admin_complaints_count', 7, 60);

            $response = $this->asAdmin()->post(route($route, 'c-300'), $body)->assertRedirect();

            $response->assertSessionHasErrors($action);
            $response->assertSessionMissing('success');
            $this->assertTrue(Cache::has('admin_complaints_count'), 'a failed action must not clear the count');
        }
    }

    public static function badComplaintIds(): array
    {
        return [
            'has a space'        => ['abc%20def'],
            'has a question mark' => ['a%3Fb%3Dc'],
            'path climbing'      => ['..%2Fadmin'],
            'dots only'          => ['%2e%2e'],
            'semicolon'          => ['a;b'],
            'underscore'         => ['a_b'],
            'too long (65)'      => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('badComplaintIds')]
    public function test_an_odd_complaint_id_is_a_404_and_never_reaches_the_api(string $id): void
    {
        $this->asAdmin();

        foreach (['reply', 'resolve', 'close'] as $action) {
            $this->post("/admin/complaints/{$id}/{$action}", ['message' => 'hi'])->assertNotFound();
        }

        $this->assertSame([], $this->posts);
    }

    public function test_a_complaint_id_of_exactly_64_characters_is_accepted(): void
    {
        $this->asAdmin()->post(route('admin.complaints.close', str_repeat('a', 64)))->assertSessionHas('success');
    }

    // ================================================================== ADMIN: polls

    public function test_the_new_complaint_poll_seeds_first_then_reports_only_really_new_ones(): void
    {
        $this->asAdmin();
        $this->adminList = [$this->complaint('c-1'), $this->complaint('c-2')];

        $this->getJson(route('admin.complaints.check-new'))->assertOk()->assertExactJson(['has_new' => false]);   // first call only remembers
        $this->getJson(route('admin.complaints.check-new'))->assertExactJson(['has_new' => false]);             // nothing changed

        $this->adminList[] = $this->complaint('c-3');
        $this->getJson(route('admin.complaints.check-new'))->assertExactJson(['has_new' => true]);
        $this->getJson(route('admin.complaints.check-new'))->assertExactJson(['has_new' => false], 'told once, not again');

        array_shift($this->adminList);   // c-1 left the list (resolved elsewhere)
        $this->getJson(route('admin.complaints.check-new'))->assertExactJson(['has_new' => false], 'a complaint leaving is not "new"');

        // One leaves and a different one arrives in the same moment: the count is the same but it IS new.
        array_shift($this->adminList);
        $this->adminList[] = $this->complaint('c-4');
        $this->getJson(route('admin.complaints.check-new'))->assertExactJson(['has_new' => true]);
    }

    public function test_the_new_reply_poll_tells_about_replies_from_others_but_not_from_the_admin(): void
    {
        $this->asAdmin();
        $this->adminList = [$this->complaint('c-1', self::WITH_ADMIN, [$this->reply(self::CUSTOMER, 'old')])];

        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        // the admin's own reply arrives
        $this->adminList[0]['replies'][] = $this->reply(self::ADMIN, 'mine', 'Support');
        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        // a dealer replies
        $this->adminList[0]['replies'][] = $this->reply(self::DEALER, 'dealer here', 'Kamal');
        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => true, 'author_name' => 'Kamal']);

        // told once only
        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        // a customer replies on another complaint
        $this->adminList[] = $this->complaint('c-2', self::WITH_ADMIN, [$this->reply(self::CUSTOMER, 'first', 'Nimal')]);
        // (a brand-new complaint that already has a customer message counts as a new reply: that is how the poll works today)
        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => true, 'author_name' => 'Nimal']);
        $this->adminList[1]['replies'][] = $this->reply(self::CUSTOMER, 'second', 'Nimal2');
        $this->getJson(route('admin.complaints.check-new-replies'))->assertExactJson(['has_new_reply' => true, 'author_name' => 'Nimal2']);
    }

    public function test_the_admin_polls_stay_quiet_when_the_api_is_broken(): void
    {
        $this->asAdmin();

        foreach (['down', 'error'] as $mode) {
            $this->apiMode = $mode;
            $this->getJson(route('admin.complaints.check-new'))->assertOk()->assertExactJson(['has_new' => false]);
            $this->getJson(route('admin.complaints.check-new-replies'))->assertOk()->assertExactJson(['has_new_reply' => false, 'author_name' => null]);
        }
    }

    // ================================================================== ADMIN: who may use it

    public static function nonAdminRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_nobody_but_an_admin_can_open_or_act_on_the_admin_complaint_pages(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        foreach (['admin.complaints.index', 'admin.complaints.resolved', 'admin.complaints.check-new', 'admin.complaints.check-new-replies',
                  'admin.troubleshoot', 'admin.feedback', 'admin.device-replace-request', 'admin.view-complains'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('admin.complaints.reply', 'c-1'), ['message' => 'hi'])->assertForbidden();
        $this->post(route('admin.complaints.resolve', 'c-1'))->assertForbidden();
        $this->post(route('admin.complaints.close', 'c-1'))->assertForbidden();

        $this->assertSame([], $this->posts);
        $this->assertSame([], $this->gets);
    }

    public function test_a_logged_out_visitor_is_sent_to_login_everywhere(): void
    {
        $this->get(route('admin.complaints.index'))->assertRedirect('/login');
        $this->post(route('admin.complaints.reply', 'c-1'), ['message' => 'hi'])->assertRedirect('/login');
        $this->get(route('dealer.complaints'))->assertRedirect('/login');
        $this->post(route('dealer.complaints.escalate', 'c-1'))->assertRedirect('/login');
        $this->getJson(route('dealer.check-new-complaints'))->assertUnauthorized();
    }

    // ================================================================== DEALER: list and isolation

    public function test_a_dealer_sees_only_the_complaints_the_api_returns_for_their_own_id(): void
    {
        [$login, $mine] = $this->dealerLogin();
        $other = $this->makeDealerRecord();
        $this->dealerLists[$mine->id] = [$this->complaint('mine-1', self::WITH_DEALER, [], ['description' => 'My own customer problem'])];
        $this->dealerLists[$other->id] = [$this->complaint('other-1', self::WITH_DEALER, [], ['description' => 'SOMEBODY ELSES problem'])];

        $this->actingAs($login)->get(route('dealer.complaints'))
            ->assertOk()
            ->assertSee('My own customer problem')
            ->assertDontSee('SOMEBODY ELSES problem');

        $byDealer = array_values(array_filter($this->gets, fn ($p) => str_contains($p, '/by-dealer/')));
        $this->assertNotEmpty($byDealer);
        foreach ($byDealer as $path) {
            $this->assertSame("/api/internal/complaints/by-dealer/{$mine->id}", $path, 'only the logged-in dealer id is ever asked for');
        }
    }

    public function test_a_dealer_cannot_choose_another_dealer_by_adding_a_dealer_id_to_the_address(): void
    {
        [$login, $mine] = $this->dealerLogin();
        $other = $this->makeDealerRecord();
        $this->dealerLists[$other->id] = [$this->complaint('other-1', self::WITH_DEALER, [], ['description' => 'SOMEBODY ELSES problem'])];

        $this->actingAs($login)->get(route('dealer.complaints', ['dealerId' => $other->id, 'dealer_id' => $other->id, 'id' => $other->id]))
            ->assertOk()->assertDontSee('SOMEBODY ELSES problem');
        $this->actingAs($login)->getJson(route('dealer.check-new-complaints', ['dealer_id' => $other->id]))->assertOk();

        foreach ($this->gets as $path) {
            $this->assertStringNotContainsString("/by-dealer/{$other->id}", $path);
        }
    }

    public function test_customer_text_on_the_dealer_pages_is_shown_as_text_and_never_run(): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $this->dealerLists[$dealer->id] = [
            $this->complaint('c-x', self::WITH_DEALER, [$this->reply(self::CUSTOMER, '<script>alert(2)</script>', '<b onmouseover=alert(3)>x</b>')], ['description' => '<script>alert(1)</script>']),
            $this->complaint('c-y', self::RESOLVED, [], ['description' => '<script>alert(4)</script>']),
        ];

        foreach (['dealer.complaints', 'dealer.complaints.resolved'] as $route) {
            $html = $this->actingAs($login)->get(route($route))->assertOk()->getContent();
            foreach (['<script>alert(1)</script>', '<script>alert(2)</script>', '<script>alert(4)</script>', '<b onmouseover=alert(3)>'] as $bad) {
                $this->assertStringNotContainsString($bad, $html, "{$route}: {$bad}");
            }
        }
    }

    #[DataProvider('brokenApis')]
    public function test_when_the_api_is_broken_the_dealer_list_pages_still_open_with_a_message_and_no_raw_error(string $mode): void
    {
        [$login] = $this->dealerLogin();
        $this->apiMode = $mode;

        foreach (['dealer.complaints', 'dealer.complaints.resolved'] as $route) {
            $response = $this->actingAs($login)->get(route($route))->assertOk();

            if ($mode !== 'not_json') {
                $response->assertSee('Complaints are unavailable right now', false);   // shown on the page itself
            }
            $this->assertStringNotContainsString('SECRET internal stack trace', $response->getContent());
        }
    }

    public function test_the_dealer_resolved_page_shows_only_resolved_and_closed_newest_first(): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $this->dealerLists[$dealer->id] = [
            $this->complaint('old', self::RESOLVED, [], ['description' => 'OLDER-ONE', 'resolvedAt' => '2026-10-01T10:00:00Z']),
            $this->complaint('open', self::WITH_DEALER, [], ['description' => 'STILL-OPEN']),
            $this->complaint('new', self::CLOSED, [], ['description' => 'NEWER-ONE', 'resolvedAt' => '2026-10-15T10:00:00Z']),
            $this->complaint('admin', self::WITH_ADMIN, [], ['description' => 'WITH-ADMIN-NOW']),
        ];

        $this->actingAs($login)->get(route('dealer.complaints.resolved'))
            ->assertOk()
            ->assertSeeInOrder(['NEWER-ONE', 'OLDER-ONE'])
            ->assertDontSee('STILL-OPEN')
            ->assertDontSee('WITH-ADMIN-NOW');
    }

    // ================================================================== DEALER: actions

    public function test_a_dealer_reply_is_sent_as_a_dealer_reply_for_their_own_id(): void
    {
        [$login, $dealer] = $this->dealerLogin();

        $this->actingAs($login)->post(route('dealer.complaints.reply', 'c-7'), ['message' => 'Checked on site'])
            ->assertRedirect()->assertSessionHas('success', 'Reply sent.');

        $body = $this->postsTo('reply')[0]['body'];
        $this->assertSame('/api/internal/complaints/c-7/reply', $this->postsTo('reply')[0]['path']);
        $this->assertSame(self::DEALER, $body['authorType']);
        $this->assertSame($dealer->full_name, $body['authorName']);
        $this->assertSame($dealer->id, $body['dealerId']);
    }

    public function test_a_dealer_cannot_spoof_who_they_are_in_a_reply(): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $other = $this->makeDealerRecord();

        $this->actingAs($login)->post(route('dealer.complaints.reply', 'c-7'), [
            'message' => 'hi', 'dealerId' => $other->id, 'authorType' => 2, 'authorName' => 'ShaloTrack Support',
        ]);

        $body = $this->postsTo('reply')[0]['body'];
        $this->assertSame($dealer->id, $body['dealerId']);
        $this->assertSame(self::DEALER, $body['authorType'], 'a dealer can never post as an admin');
        $this->assertSame($dealer->full_name, $body['authorName']);
    }

    #[DataProvider('badMessages')]
    public function test_a_bad_dealer_reply_is_refused_and_nothing_is_sent(mixed $message): void
    {
        [$login] = $this->dealerLogin();
        $body = $message === null ? [] : ['message' => $message];

        $this->actingAs($login)->post(route('dealer.complaints.reply', 'c-7'), $body)->assertSessionHasErrors('message');

        $this->assertSame([], $this->posts);
    }

    public static function dealerOneClickActions(): array
    {
        return [
            'escalate' => ['escalate', 'Complaint transferred to ShaloTrack support.', 'dealer_complaints_count_'],
            'resolve'  => ['resolve', 'Complaint marked as resolved.', 'dealer_complaints_count_'],
            'close'    => ['close', 'Complaint closed.', 'dealer_complaints_count_'],
        ];
    }

    #[DataProvider('dealerOneClickActions')]
    public function test_escalate_resolve_and_close_use_the_logged_in_dealers_id_only(string $action, string $message, string $cachePrefix): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $other = $this->makeDealerRecord();
        Cache::put($cachePrefix . $dealer->id, 4, 60);

        // The browser tries to add a different dealer id, in the address and in the form.
        $this->actingAs($login)->post(route("dealer.complaints.{$action}", 'c-9') . '?dealerId=' . $other->id, ['dealerId' => $other->id, 'dealer_id' => $other->id])
            ->assertRedirect()->assertSessionHas('success', $message);

        $sent = $this->postsTo($action);
        $this->assertCount(1, $sent);
        $this->assertSame("/api/internal/complaints/c-9/{$action}", $sent[0]['path']);
        $this->assertSame("dealerId={$dealer->id}", $sent[0]['query'], 'the API is told the dealer from the login, nothing else');
        if ($action === 'escalate') {
            $this->assertFalse(Cache::has($cachePrefix . $dealer->id), 'escalating clears the dealer sidebar count');
        }
    }

    #[DataProvider('dealerOneClickActions')]
    public function test_when_the_api_fails_a_dealer_gets_a_clear_error_and_never_the_raw_api_text(string $action): void
    {
        [$login] = $this->dealerLogin();

        foreach (['error', 'down'] as $mode) {
            $this->apiMode = $mode;

            $response = $this->actingAs($login)->post(route("dealer.complaints.{$action}", 'c-9'))->assertRedirect();

            $response->assertSessionHasErrors($action);
            $response->assertSessionMissing('success');
            $this->assertStringNotContainsString('SECRET', json_encode(session('errors')?->getBag('default')->all()), 'raw API text must not reach the dealer');
        }
    }

    #[DataProvider('badComplaintIds')]
    public function test_an_odd_complaint_id_on_a_dealer_page_is_a_404_and_never_reaches_the_api(string $id): void
    {
        [$login] = $this->dealerLogin();
        $this->actingAs($login);

        foreach (['reply', 'escalate', 'resolve', 'close'] as $action) {
            $this->post("/dealer/complaints/{$id}/{$action}", ['message' => 'hi'])->assertNotFound();
        }

        $this->assertSame([], $this->posts);
    }

    public function test_a_login_marked_dealer_without_a_dealer_record_can_do_nothing_and_is_told_why(): void
    {
        $login = $this->makeAdmin('DEALER');   // dealer_id is empty

        $this->actingAs($login)->from('/dealer/dashboard')->get(route('dealer.complaints'))->assertRedirect()->assertSessionHas('error', 'Dealer profile not found.');
        $this->actingAs($login)->post(route('dealer.complaints.reply', 'c-1'), ['message' => 'hi'])->assertSessionHasErrors('dealer');
        foreach (['escalate', 'resolve', 'close'] as $action) {
            $this->actingAs($login)->post(route("dealer.complaints.{$action}", 'c-1'))->assertSessionHasErrors('dealer');
        }
        $this->actingAs($login)->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => false]);
        $this->actingAs($login)->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        $this->assertSame([], $this->posts, 'no complaint action may reach the API without a dealer');
    }

    // ================================================================== DEALER: polls

    public function test_the_dealer_new_complaint_poll_seeds_first_and_counts_only_open_complaints(): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $this->actingAs($login);
        $this->dealerLists[$dealer->id] = [$this->complaint('c-1', self::WITH_DEALER), $this->complaint('c-2', self::RESOLVED)];

        $this->getJson(route('dealer.check-new-complaints'))->assertOk()->assertExactJson(['has_new' => false]);
        $this->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => false]);

        // an already-finished complaint appearing is not news
        $this->dealerLists[$dealer->id][] = $this->complaint('c-3', self::CLOSED);
        $this->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => false]);

        // a new open one is
        $this->dealerLists[$dealer->id][] = $this->complaint('c-4', self::WITH_DEALER);
        $this->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => true]);
        $this->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => false]);

        // one finishes while another arrives in the same moment: still news
        $this->dealerLists[$dealer->id][3]['status'] = self::RESOLVED;
        $this->dealerLists[$dealer->id][] = $this->complaint('c-5', self::WITH_ADMIN);
        $this->getJson(route('dealer.check-new-complaints'))->assertExactJson(['has_new' => true]);
    }

    public function test_the_dealer_new_reply_poll_tells_only_about_admin_replies(): void
    {
        [$login, $dealer] = $this->dealerLogin();
        $this->actingAs($login);
        $this->dealerLists[$dealer->id] = [$this->complaint('c-1', self::WITH_DEALER, [$this->reply(self::CUSTOMER, 'old')])];

        $this->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        $this->dealerLists[$dealer->id][0]['replies'][] = $this->reply(self::DEALER, 'my own', 'Me');
        $this->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        $this->dealerLists[$dealer->id][0]['replies'][] = $this->reply(self::CUSTOMER, 'customer again', 'Nimal');
        $this->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);

        $this->dealerLists[$dealer->id][0]['replies'][] = $this->reply(self::ADMIN, 'from support', 'ShaloTrack Support');
        $this->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => true, 'author_name' => 'ShaloTrack Support']);

        $this->getJson(route('dealer.check-new-replies'))->assertExactJson(['has_new_reply' => false, 'author_name' => null]);
    }

    public function test_the_dealer_polls_stay_quiet_when_the_api_is_broken(): void
    {
        [$login] = $this->dealerLogin();
        $this->actingAs($login);

        foreach (['down', 'error'] as $mode) {
            $this->apiMode = $mode;
            $this->getJson(route('dealer.check-new-complaints'))->assertOk()->assertExactJson(['has_new' => false]);
            $this->getJson(route('dealer.check-new-replies'))->assertOk()->assertExactJson(['has_new_reply' => false, 'author_name' => null]);
        }
    }

    // ================================================================== DEALER: who may use it

    public static function nonDealerRoles(): array
    {
        return array_map(fn ($r) => [$r], ['ADMIN', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('nonDealerRoles')]
    public function test_nobody_but_a_dealer_can_open_or_act_on_the_dealer_complaint_pages(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        foreach (['dealer.complaints', 'dealer.complaints.resolved', 'dealer.check-new-complaints', 'dealer.check-new-replies'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('dealer.complaints.reply', 'c-1'), ['message' => 'hi'])->assertForbidden();
        foreach (['escalate', 'resolve', 'close'] as $action) {
            $this->post(route("dealer.complaints.{$action}", 'c-1'))->assertForbidden();
        }

        $this->assertSame([], $this->posts);
        $this->assertSame([], $this->gets);
    }
}