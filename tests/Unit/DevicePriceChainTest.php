<?php

namespace Tests\Unit;

use App\Services\DevicePriceChain;
use PHPUnit\Framework\TestCase;

class DevicePriceChainTest extends TestCase
{
    public function test_chain_matches_the_price_note(): void
    {
        $c = DevicePriceChain::build('1000', '3000', '500', '1300');

        $this->assertSame(400000, $c['steps'][0]['price']);   // distributor cost
        $this->assertSame(450000, $c['steps'][1]['price']);   // retailer price
        $this->assertSame(580000, $c['steps'][2]['price']);   // customer price
        $this->assertSame(580000, $c['customer_total']);
        $this->assertSame(480000, $c['total_margin']);
        $this->assertNull($c['tax_rate']);
        $this->assertSame(0, $c['steps'][0]['tax']);
    }

    public function test_margin_is_shown_on_cost_and_on_price(): void
    {
        $c = DevicePriceChain::build('1000', '3000', '500', '1300');

        // distributor: 500 on cost 4000 = 12.50%, on price 4500 = 11.11%
        $this->assertSame(1250, $c['steps'][1]['markup_bp']);
        $this->assertSame(1111, $c['steps'][1]['share_bp']);
        $this->assertSame('12.50%', DevicePriceChain::percent($c['steps'][1]['markup_bp']));
        // company: 3000 on cost 1000 = 300%, on price 4000 = 75%
        $this->assertSame(30000, $c['steps'][0]['markup_bp']);
        $this->assertSame(7500, $c['steps'][0]['share_bp']);
    }

    public function test_tax_is_added_on_each_sale_and_margins_stay_ex_tax(): void
    {
        $c = DevicePriceChain::build('1000', '3000', '500', '1300', '18.00');

        $this->assertSame('18.00', $c['tax_rate']);
        $this->assertSame(72000, $c['steps'][0]['tax']);
        $this->assertSame(472000, $c['steps'][0]['price_incl']);
        $this->assertSame(580000, $c['customer_total']);
        $this->assertSame(684400, $c['steps'][2]['price_incl']);
    }

    public function test_zero_or_blank_tax_rate_means_no_tax(): void
    {
        $this->assertNull(DevicePriceChain::build('100', '10', '10', '10', '0.00')['tax_rate']);
        $this->assertNull(DevicePriceChain::build('100', '10', '10', '10', '')['tax_rate']);
        $this->assertNull(DevicePriceChain::build('100', '10', '10', '10', 'abc')['tax_rate']);
    }

    public function test_zero_margins_are_allowed(): void
    {
        $c = DevicePriceChain::build('250.50', '0', '0', '0');
        $this->assertSame(25050, $c['customer_total']);
        $this->assertSame(0, $c['steps'][0]['markup_bp']);
    }

    public function test_landing_cost_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DevicePriceChain::build('0', '10', '10', '10');
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DevicePriceChain::build('100', '-5', '0', '0');
    }

    public function test_cents_never_drift(): void
    {
        $c = DevicePriceChain::build('0.10', '0.20', '0.00', '0.00');
        $this->assertSame(30, $c['customer_total']);
    }
}
