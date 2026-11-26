<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;

/** Which senders the app believes when they name the real visitor (X-Forwarded-For). */
class TrustedProxiesTest extends TestCase
{
    public function test_nothing_set_gives_the_private_default_list(): void
    {
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv(null));
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv(''));
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv("   \t "));
    }

    public function test_default_is_never_open_to_everyone(): void
    {
        $this->assertNotContains('*', TrustedProxies::DEFAULT);
        $this->assertNotContains('0.0.0.0/0', TrustedProxies::DEFAULT);
        $this->assertNotContains('::/0', TrustedProxies::DEFAULT);
    }

    public function test_default_covers_the_load_balancer_and_container_network_but_not_the_internet(): void
    {
        foreach (['10.0.1.25', '10.255.255.254', '172.16.0.9', '172.31.255.1', '192.168.1.1', '127.0.0.1', '::1'] as $private) {
            $this->assertTrue(IpUtils::checkIp($private, TrustedProxies::DEFAULT), "$private should be believed");
        }
        foreach (['8.8.8.8', '203.0.113.7', '172.32.0.1', '172.15.255.255', '11.0.0.1', '2001:db8::1'] as $public) {
            $this->assertFalse(IpUtils::checkIp($public, TrustedProxies::DEFAULT), "$public must NOT be believed");
        }
    }

    public function test_a_lone_star_written_on_purpose_is_the_roll_back_switch(): void
    {
        $this->assertSame('*', TrustedProxies::fromEnv('*'));
        $this->assertSame('*', TrustedProxies::fromEnv('  *  '));
    }

    public function test_a_list_is_split_on_commas_and_trimmed(): void
    {
        $this->assertSame(['10.0.0.0/16', '192.168.5.4'], TrustedProxies::fromEnv(' 10.0.0.0/16 , 192.168.5.4 '));
        $this->assertSame(['10.0.0.0/16'], TrustedProxies::fromEnv('10.0.0.0/16'));
    }

    public function test_empty_entries_are_ignored(): void
    {
        $this->assertSame(['10.0.0.0/16', '10.1.0.0/16'], TrustedProxies::fromEnv(',10.0.0.0/16,, 10.1.0.0/16,'));
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv(',,  ,'));
    }

    public function test_a_star_mixed_into_a_list_never_opens_everything_up(): void
    {
        $this->assertSame(['10.0.0.0/16'], TrustedProxies::fromEnv('10.0.0.0/16,*'));
        $this->assertSame(['10.0.0.0/16'], TrustedProxies::fromEnv('*,10.0.0.0/16'));
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv('**'));
        $this->assertSame(TrustedProxies::DEFAULT, TrustedProxies::fromEnv('*,**'));
    }

    public function test_the_result_is_always_a_list_or_the_star_and_nothing_else(): void
    {
        foreach ([null, '', '*', '10.0.0.0/8', '1.2.3.4,5.6.7.8', '*,1.2.3.4', 'garbage'] as $input) {
            $result = TrustedProxies::fromEnv($input);
            $this->assertTrue($result === '*' || (is_array($result) && $result !== []), 'bad result for ' . var_export($input, true));
            if (is_array($result)) {
                $this->assertSame(array_values($result), $result);
            }
        }
    }
}
