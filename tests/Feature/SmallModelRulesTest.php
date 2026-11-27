<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Two small rules that had no test:
 *   - where each role goes to edit its own details (the link in the account menu),
 *   - how a Sri Lankan phone number is cleaned so "077 123 4567", "+94 77 123 4567" and "94771234567" match.
 * No database is needed.
 */
class SmallModelRulesTest extends TestCase
{
    private function admin(string $role): Admin
    {
        $a = new Admin();
        $a->role = $role;

        return $a;
    }

    public function test_each_role_with_its_own_profile_page_gets_a_route_that_really_exists(): void
    {
        $expected = [
            'ADMIN'       => 'admin.profile',
            'DISTRIBUTOR' => 'distributor.profile',
            'FINANCE'     => 'finance.profile',
            'TECHNICIAN'  => 'technician.profile',
        ];

        foreach ($expected as $role => $name) {
            $this->assertSame($name, $this->admin($role)->profileRouteName(), $role);
            $this->assertTrue(Route::has($name), "{$name} must be a real route, or the account menu link breaks");
        }
    }

    public function test_roles_that_edit_their_details_somewhere_else_get_no_link_here(): void
    {
        foreach (['DEALER', 'SUPPLIER', 'SOMETHING-ELSE', ''] as $role) {
            $this->assertNull($this->admin($role)->profileRouteName(), "'{$role}'");
        }
    }

    public static function phones(): array
    {
        return [
            'local with a leading 0'        => ['0771234567', '771234567'],
            'local with spaces'             => ['077 123 4567', '771234567'],
            'local with dashes'             => ['077-123-4567', '771234567'],
            'international with a plus'     => ['+94 77 123 4567', '771234567'],
            'international without a plus'  => ['94771234567', '771234567'],
            'already without the prefix'    => ['771234567', '771234567'],
            'brackets and dots'             => ['(077) 123.4567', '771234567'],
            'empty'                         => ['', ''],
            'letters only'                  => ['n/a', ''],
            'too short stays as it is'      => ['0771', '0771'],
            'foreign number stays as it is' => ['+44 7911 123456', '447911123456'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phones')]
    public function test_the_app_customer_phone_is_cleaned_the_same_way(string $raw, string $clean): void
    {
        $this->assertSame($clean, (new CustomerAd(['phone_number' => $raw]))->normalised_phone);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phones')]
    public function test_the_dealers_customer_record_phone_is_cleaned_the_same_way(string $raw, string $clean): void
    {
        $this->assertSame($clean, (new DealerCustomerAd(['contact' => $raw]))->normalised_contact);
    }

    public function test_a_missing_phone_gives_an_empty_string_not_an_error(): void
    {
        $this->assertSame('', (new CustomerAd())->normalised_phone);
        $this->assertSame('', (new DealerCustomerAd())->normalised_contact);
    }
}
