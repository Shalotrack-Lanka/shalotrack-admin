<?php

namespace Tests\Unit;

use App\Services\Delivery\DeliveryPolicy as P;
use PHPUnit\Framework\TestCase;

class DeliveryPolicyTest extends TestCase
{
    private const TODAY = '2026-11-09';

    private function decide(bool $enabled, ?string $from, bool $exists, bool $registered = false, ?int $unitType = 5, ?int $chosen = 5): string
    {
        return P::decide($enabled, $from, self::TODAY, $exists, $registered, $unitType, $chosen);
    }

    public function test_switched_off_checks_nothing(): void
    {
        $this->assertSame(P::OFF, $this->decide(false, '2026-01-01', false));
        $this->assertSame(P::OFF, $this->decide(false, null, true, true));
    }

    public function test_a_received_unit_of_the_same_type_is_ok(): void
    {
        $this->assertSame(P::OK, $this->decide(true, null, true));
        $this->assertSame(P::OK, $this->decide(true, '2026-01-01', true));
    }

    public function test_a_different_device_type_is_refused_even_in_the_warning_phase(): void
    {
        $this->assertSame(P::MISMATCH, $this->decide(true, null, true, false, 5, 6));
    }

    public function test_a_sim_has_no_device_type_to_mismatch(): void
    {
        $this->assertSame(P::OK, $this->decide(true, null, true, false, null, null));
        $this->assertSame(P::OK, $this->decide(true, null, true, false, null, 6));
    }

    public function test_an_already_registered_unit_is_refused(): void
    {
        $this->assertSame(P::REGISTERED, $this->decide(true, null, true, true));
    }

    public function test_no_delivery_is_a_warning_until_the_enforcement_date(): void
    {
        $this->assertSame(P::UNVERIFIED, $this->decide(true, null, false));
        $this->assertSame(P::UNVERIFIED, $this->decide(true, '', false));
        $this->assertSame(P::UNVERIFIED, $this->decide(true, '2026-11-10', false));
    }

    public function test_no_delivery_is_refused_from_the_enforcement_date(): void
    {
        $this->assertSame(P::BLOCKED, $this->decide(true, '2026-11-09', false));
        $this->assertSame(P::BLOCKED, $this->decide(true, '2026-01-01', false));
    }

    public function test_a_bad_date_never_locks_registration(): void
    {
        $this->assertSame(P::UNVERIFIED, $this->decide(true, 'tomorrow', false));
        $this->assertSame(P::UNVERIFIED, $this->decide(true, '2026-13-45', false));
        $this->assertSame(P::UNVERIFIED, $this->decide(true, '09/11/2026', false));
        $this->assertFalse(P::enforcing(null, self::TODAY));
        $this->assertFalse(P::enforcing('2026-02-30', self::TODAY));
    }

    public function test_enforcing_boundary(): void
    {
        $this->assertTrue(P::enforcing('2026-11-09', '2026-11-09'));
        $this->assertFalse(P::enforcing('2026-11-10', '2026-11-09'));
        $this->assertTrue(P::enforcing(' 2026-11-09 ', '2026-11-09'));
    }
}
