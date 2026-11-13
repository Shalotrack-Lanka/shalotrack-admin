<?php

namespace Tests\Pgsql;

use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use App\Services\CustomerLinkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\PgsqlTestCase;

/**
 * THE LINK BETWEEN A DEALER'S OWN CUSTOMER RECORD AND THE REAL APP ACCOUNT, run on PostgreSQL.
 *
 * The dealer types a phone number by hand ("077 123 4567", "077-123-4567", "+94 77 123 4567") while the app
 * stores E.164 ("+94771234567"). The link must find the same person whatever way the number was typed, in both
 * directions (customer page -> dealer, dealer page -> app account), and must never link two different people.
 */
class CustomerLinkServicePgTest extends PgsqlTestCase
{
    use DatabaseTransactions;
    use BuildsIntakeData;
    use BuildsReportData;

    /** A 9-digit subscriber number that is not in any other test row. */
    private function subscriber(): string
    {
        return '7' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function lead(string $contact, ?string $email = null): DealerCustomerAd
    {
        return DealerCustomerAd::create([
            'dealer_id' => $this->makeDealer()->id,
            'name' => 'Autotest Lead',
            'email' => $email,
            'contact' => $contact,
            'no_of_devices' => 0,
            'imei_numbers' => [],
        ]);
    }

    private function account(string $phone, ?string $email = null): CustomerAd
    {
        return CustomerAd::create([
            'customer_id' => (string) Str::uuid(),
            'full_name' => 'Autotest App Customer',
            'email' => $email ?? 'autotest+' . Str::random(8) . '@example.test',
            'phone_number' => $phone,
        ]);
    }

    public static function dealerTypedFormats(): array
    {
        return [
            'plain local'      => ['0{n}'],
            'spaces'           => ['0{a} {b} {c}'],
            'dashes'           => ['0{a}-{b}-{c}'],
            'brackets'         => ['(0{a}) {b}{c}'],
            'plus country'     => ['+94{n}'],
            'country spaced'   => ['+94 {a} {b} {c}'],
            'country dashes'   => ['+94-{a}-{b}-{c}'],
            'country no plus'  => ['94{n}'],
        ];
    }

    private function format(string $pattern, string $n): string
    {
        return strtr($pattern, ['{n}' => $n, '{a}' => substr($n, 0, 2), '{b}' => substr($n, 2, 3), '{c}' => substr($n, 5)]);
    }

    #[DataProvider('dealerTypedFormats')]
    public function test_customer_page_finds_the_dealer_whatever_way_the_dealer_typed_the_phone(string $pattern): void
    {
        $n = $this->subscriber();
        $lead = $this->lead($this->format($pattern, $n));
        $customer = $this->account('+94' . $n);

        $this->assertSame($lead->id, CustomerLinkService::findDealerLead($customer)?->id);

        $batch = CustomerLinkService::enrichCustomers(collect([$customer]));
        $this->assertSame($lead->id, $batch->first()->dealerLead?->id);
    }

    #[DataProvider('dealerTypedFormats')]
    public function test_dealer_page_finds_the_app_account_whatever_way_the_app_stored_the_phone(string $pattern): void
    {
        $n = $this->subscriber();
        $lead = $this->lead('0' . $n);
        $account = $this->account($this->format($pattern, $n));

        $this->assertSame($account->customer_id, CustomerLinkService::findAppAccount($lead)?->customer_id);

        $batch = CustomerLinkService::enrichLeads(collect([$lead]));
        $this->assertSame($account->customer_id, $batch->first()->appAccount?->customer_id);
    }

    public function test_different_numbers_are_never_linked(): void
    {
        $n = $this->subscriber();
        $other = (string) ((int) $n === 700000000 ? 700000001 : $n + 1);
        $lead = $this->lead('0' . $n);
        $customer = $this->account('+94' . $other);

        $this->assertNull(CustomerLinkService::findDealerLead($customer));
        $this->assertNull(CustomerLinkService::findAppAccount($lead));
        $this->assertNull(CustomerLinkService::enrichCustomers(collect([$customer]))->first()->dealerLead);
        $this->assertNull(CustomerLinkService::enrichLeads(collect([$lead]))->first()->appAccount);
    }

    public function test_an_email_match_still_links_when_the_phones_differ(): void
    {
        $n = $this->subscriber();
        $email = 'autotest+' . Str::random(8) . '@example.test';
        $lead = $this->lead('0' . $n, $email);
        $customer = $this->account('+94' . $this->subscriber(), $email);

        $this->assertSame($lead->id, CustomerLinkService::findDealerLead($customer)?->id);
        $this->assertSame($customer->customer_id, CustomerLinkService::findAppAccount($lead)?->customer_id);
    }

    public function test_a_wildcard_typed_into_the_phone_cannot_link_strangers(): void
    {
        $lead = $this->lead('0771234567');
        $customer = $this->account('%%%%%%%%%');

        $this->assertNull(CustomerLinkService::findDealerLead($customer));
        $this->assertNull(CustomerLinkService::enrichCustomers(collect([$customer]))->first()->dealerLead);
        $this->assertNotNull($lead->id);
    }
}