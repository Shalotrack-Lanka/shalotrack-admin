<?php

namespace Tests\Pgsql;

use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE "NOT ENOUGH STOCK" PHONE ALERT when a dealer adds a customer who needs more devices than the dealer holds
 * (POST dealer.customer-ad.store -> Firebase push to the topic dealer_stock_alerts).
 *
 * What the tests prove (simple words):
 *   - the customer is always saved, whatever happens to the alert (no key file, Google down, bad answer);
 *   - the alert is sent only when the need is GREATER than the stock (equal is fine, no devices needed is fine);
 *   - the alert carries the right text and numbers, to the right topic, with the access token;
 *   - the sign-in request to Google is a correctly formed token (URL-safe base64, signed with the private key).
 *
 * The real key file (storage/app/firebase-service-account.json) is NEVER touched: if a real one exists on the
 * machine, the tests that need to write one are skipped. A throw-away key is made for each test and removed after.
 */
class DealerStockAlertPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private string $keyFile;
    private bool $madeKeyFile = false;
    private $publicKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->keyFile = storage_path('app/firebase-service-account.json');
    }

    protected function tearDown(): void
    {
        if ($this->madeKeyFile && File::exists($this->keyFile)) {
            File::delete($this->keyFile);
        }
        parent::tearDown();
    }

    /** Writes a throw-away service-account file, unless a real one is there (then the test is skipped). */
    private function useThrowawayKey(array $overrides = []): void
    {
        if (File::exists($this->keyFile)) {
            $this->markTestSkipped('A real Firebase key file exists on this machine; not touching it.');
        }

        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key) {
            $this->markTestSkipped('This PHP cannot make an RSA key (OpenSSL is not configured).');
        }
        openssl_pkey_export($key, $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        File::put($this->keyFile, json_encode(array_merge([
            'client_email' => 'autotest@example.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'autotest-project',
        ], $overrides)));
        $this->madeKeyFile = true;
    }

    private function dealerLogin(): array
    {
        $dealer = $this->makeDealer();
        $login = $this->makeDealerLogin($dealer);
        $this->actingAs($login);

        return [$dealer, $login];
    }

    private function giveStock(Dealer $dealer, int $count): void
    {
        foreach (range(1, $count) as $i) {
            SetupShalotrackDevice::create(['device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(), 'sim_number' => '07' . str_pad((string) $this->nextIntakeNumber(), 9, '0', STR_PAD_LEFT),
                'status' => 'Activated', 'dealer_id' => $dealer->id]);
        }
    }

    private function addCustomer(array $overrides = [])
    {
        return $this->post(route('dealer.customer-ad.store'), array_merge([
            'name' => 'Autotest Alert Customer', 'contact' => '0771234567', 'has_device' => 1, 'no_of_devices' => 3,
        ], $overrides));
    }

    /** Google sign-in answers with a token, the push endpoint answers OK. */
    private function fakeGoogle(?string $token = 'tok-123'): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response($token ? ['access_token' => $token] : ['error' => 'invalid_grant'], $token ? 200 : 400),
            'fcm.googleapis.com/*'    => Http::response(['name' => 'projects/x/messages/1'], 200),
            '*'                       => Http::response(['data' => []], 200),
        ]);
    }

    private function pushes(): array
    {
        return array_values(array_filter(Http::recorded()->all() ?? [], fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com')));
    }

    private function recordedUrls(): array
    {
        return array_map(fn ($pair) => $pair[0]->url(), Http::recorded()->all());
    }

    // ================================================================== the customer is always saved

    public function test_the_customer_is_saved_with_the_form_details(): void
    {
        [$dealer] = $this->dealerLogin();
        $this->fakeGoogle();

        $this->addCustomer(['name' => '  Autotest Saved  ', 'email' => '  MiXed@Example.TEST ', 'nic_or_id' => ' 123456789V ', 'no_of_devices' => 0, 'has_device' => 0])
            ->assertRedirect()->assertSessionHas('success', 'New Customer Added Successfully!');

        $row = DealerCustomerAd::where('dealer_id', $dealer->id)->first();
        $this->assertSame('Autotest Saved', $row->name);
        $this->assertSame('mixed@example.test', $row->email);
        $this->assertSame('123456789V', $row->nic_or_id);
        $this->assertSame(0, (int) $row->no_of_devices, 'no device wanted means zero, whatever number was typed');
    }

    public function test_the_number_of_devices_is_ignored_when_the_customer_does_not_want_a_device(): void
    {
        [$dealer] = $this->dealerLogin();
        $this->fakeGoogle();

        $this->addCustomer(['has_device' => 0, 'no_of_devices' => 9])->assertSessionHas('success');

        $this->assertSame(0, (int) DealerCustomerAd::where('dealer_id', $dealer->id)->value('no_of_devices'));
        $this->assertSame([], $this->recordedUrls());
    }

    // ================================================================== when the alert is sent

    public function test_no_alert_when_the_dealer_has_enough_stock_or_exactly_enough(): void
    {
        $this->useThrowawayKey();   // with a working key, so a wrongly sent alert would really be sent
        [$dealer] = $this->dealerLogin();
        $this->giveStock($dealer, 3);
        $this->fakeGoogle();

        $this->addCustomer(['no_of_devices' => 2])->assertSessionHas('success');
        $this->addCustomer(['no_of_devices' => 3, 'contact' => '0771234568'])->assertSessionHas('success');

        $this->assertSame([], $this->recordedUrls());
    }

    public function test_an_alert_is_sent_when_the_need_is_one_more_than_the_stock(): void
    {
        $this->useThrowawayKey();
        [$dealer] = $this->dealerLogin();
        $this->giveStock($dealer, 2);
        $this->fakeGoogle();

        $this->addCustomer(['no_of_devices' => 3])->assertSessionHas('success');

        $this->assertCount(1, $this->pushes());
    }

    // ================================================================== what the alert says

    public function test_the_alert_goes_to_the_right_topic_with_the_right_words_and_numbers(): void
    {
        $this->useThrowawayKey();
        [$dealer] = $this->dealerLogin();
        $this->giveStock($dealer, 1);
        $this->fakeGoogle('tok-xyz');

        $this->addCustomer(['name' => 'Autotest Kamal', 'no_of_devices' => 4])->assertSessionHas('success');

        [[$request]] = $this->pushes();
        $this->assertSame('https://fcm.googleapis.com/v1/projects/autotest-project/messages:send', $request->url());
        $this->assertSame(['Bearer tok-xyz'], $request->header('Authorization'));
        $message = $request->data()['message'];
        $this->assertSame('dealer_stock_alerts', $message['topic']);
        $this->assertStringContainsString('Customer Autotest Kamal requested 4 devices, but you only have 1 in stock. Pending: 3 devices.', $message['notification']['body']);
        $this->assertSame(['customer_name' => 'Autotest Kamal', 'shortage' => '3'], $message['data'], 'all values are text, as the push service requires');
    }

    public function test_the_project_name_falls_back_to_the_company_project_when_the_file_has_none(): void
    {
        $this->useThrowawayKey(['project_id' => null]);
        $this->dealerLogin();
        $this->fakeGoogle();

        $this->addCustomer()->assertSessionHas('success');

        $this->assertStringContainsString('/projects/shalotracklanka/', $this->pushes()[0][0]->url());
    }

    // ================================================================== the sign-in token sent to Google

    private function base64UrlDecode(string $part): string
    {
        return base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4));
    }

    public function test_the_token_request_is_a_correctly_formed_signed_jwt(): void
    {
        $this->useThrowawayKey();
        $this->dealerLogin();
        $this->fakeGoogle();

        $this->addCustomer()->assertSessionHas('success');

        $tokenCall = collect(Http::recorded()->all())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com'));
        $this->assertNotNull($tokenCall);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $tokenCall['grant_type']);

        $parts = explode('.', $tokenCall['assertion']);
        $this->assertCount(3, $parts);
        foreach ($parts as $i => $part) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $part, "part {$i} must be URL-safe base64 with no padding (Google refuses '+', '/' and '=')");
        }

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($this->base64UrlDecode($parts[0]), true));
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertSame('autotest@example.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('autotest@example.iam.gserviceaccount.com', $claims['sub']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
        $this->assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $this->base64UrlDecode($parts[2]), $this->publicKey, OPENSSL_ALGO_SHA256), 'the signature must verify with the matching public key');
    }

    // ================================================================== when the alert cannot be sent

    public function test_without_the_key_file_nothing_is_sent_the_error_is_logged_and_the_customer_is_still_saved(): void
    {
        if (File::exists($this->keyFile)) {
            $this->markTestSkipped('A real Firebase key file exists on this machine.');
        }
        [$dealer] = $this->dealerLogin();
        $this->fakeGoogle();
        Log::spy();

        $this->addCustomer(['no_of_devices' => 2])->assertSessionHas('success', 'New Customer Added Successfully!');

        Log::shouldHaveReceived('error')->with('Firebase Service Account JSON File Not Found!')->once();
        $this->assertSame([], $this->recordedUrls());
        $this->assertSame(1, DealerCustomerAd::where('dealer_id', $dealer->id)->count());
    }

    public function test_when_google_refuses_the_sign_in_no_alert_is_sent_and_the_customer_is_still_saved(): void
    {
        $this->useThrowawayKey();
        [$dealer] = $this->dealerLogin();
        $this->fakeGoogle(null);
        Log::spy();

        $this->addCustomer()->assertSessionHas('success');

        Log::shouldHaveReceived('error')->with('Firebase OAuth Token Generation Failed')->once();
        $this->assertSame([], $this->pushes());
        $this->assertSame(1, DealerCustomerAd::where('dealer_id', $dealer->id)->count());
    }

    public function test_a_network_error_while_sending_is_logged_and_the_customer_is_still_saved(): void
    {
        $this->useThrowawayKey();
        [$dealer] = $this->dealerLogin();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'fcm.googleapis.com')) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['access_token' => 'tok'], 200);
        });
        Log::spy();

        $this->addCustomer()->assertSessionHas('success');

        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_starts_with($m, 'Firebase Push Notification Error: '))->once();
        $this->assertSame(1, DealerCustomerAd::where('dealer_id', $dealer->id)->count());
    }

    public function test_a_damaged_key_file_is_logged_and_does_not_break_adding_a_customer(): void
    {
        $this->useThrowawayKey(['private_key' => 'not a key']);
        [$dealer] = $this->dealerLogin();
        $this->fakeGoogle();
        Log::spy();

        $this->addCustomer()->assertSessionHas('success');

        $this->assertSame(1, DealerCustomerAd::where('dealer_id', $dealer->id)->count());
        $this->assertSame([], $this->pushes(), 'nothing is sent with a key that cannot sign');
    }
}