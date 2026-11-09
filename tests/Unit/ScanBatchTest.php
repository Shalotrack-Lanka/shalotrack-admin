<?php

namespace Tests\Unit;

use App\Services\Delivery\ScanBatch;
use App\Support\Imei;
use PHPUnit\Framework\TestCase;

class ScanBatchTest extends TestCase
{
    private const IMEI_A = '490154203237518';   // valid check digit
    private const IMEI_B = '356938035643809';   // valid check digit
    private const ICCID = '8991101200003204510';

    public function test_imei_check_digit(): void
    {
        $this->assertTrue(Imei::isValid(self::IMEI_A));
        $this->assertTrue(Imei::isValid(self::IMEI_B));
        $this->assertFalse(Imei::isValid('490154203237519'));   // one digit off
        $this->assertFalse(Imei::isValid('49015420323751'));    // 14 digits
        $this->assertFalse(Imei::isValid('49015420323751a'));
    }

    public function test_iccid_length_rule(): void
    {
        $this->assertTrue(Imei::isValidIccid(self::ICCID));
        $this->assertTrue(Imei::isValidIccid(self::ICCID . '0'));
        $this->assertFalse(Imei::isValidIccid('899110120000320451'));        // 18
        $this->assertFalse(Imei::isValidIccid(self::ICCID . '00'));          // 21
    }

    public function test_good_scans_pass_and_stray_whitespace_is_removed(): void
    {
        $r = ScanBatch::classify('DEVICE', [" " . self::IMEI_A . "\n", self::IMEI_B . "\r"]);

        $this->assertSame([self::IMEI_A, self::IMEI_B], $r['valid']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_misread_and_double_scans_are_rejected_one_by_one(): void
    {
        $r = ScanBatch::classify('DEVICE', [self::IMEI_A, '490154203237519', self::IMEI_A, '', self::IMEI_B]);

        $this->assertSame([self::IMEI_A, self::IMEI_B], $r['valid']);
        $this->assertSame(2, count($r['rejected']));
        $this->assertSame('490154203237519', $r['rejected'][0]['identifier']);
        $this->assertSame(self::IMEI_A, $r['rejected'][1]['identifier']);
    }

    public function test_sim_batch(): void
    {
        $r = ScanBatch::classify('SIM', [self::ICCID, 'abc', self::ICCID]);

        $this->assertSame([self::ICCID], $r['valid']);
        $this->assertSame(2, count($r['rejected']));
    }

    public function test_an_imei_is_not_accepted_as_a_sim(): void
    {
        $this->assertSame([], ScanBatch::classify('SIM', [self::IMEI_A])['valid']);
        $this->assertSame([], ScanBatch::classify('DEVICE', [self::ICCID])['valid']);
    }

    public function test_junk_input_never_crashes(): void
    {
        $r = ScanBatch::classify('DEVICE', [[1], null, 1.5, new \stdClass()]);
        $this->assertSame([], $r['valid']);
        $this->assertSame(4, count($r['rejected']));
    }

    public function test_batch_size_is_capped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScanBatch::classify('DEVICE', array_fill(0, ScanBatch::MAX + 1, self::IMEI_A));
    }

    public function test_unknown_kind_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScanBatch::classify('PHONE', [self::IMEI_A]);
    }
    public function test_sim_pairs_need_a_valid_iccid_and_a_ten_digit_number(): void
    {
        $r = ScanBatch::classifySims([
            ['iccid' => ' ' . self::ICCID . ' ', 'sim_number' => '077 123 4567'],   // spaces from the scanner
            ['iccid' => '123', 'sim_number' => '0771234568'],
            ['iccid' => '8991101200003204511', 'sim_number' => '07712345'],
            'not a pair',
            ['iccid' => '', 'sim_number' => ''],                                    // empty row: ignored
        ]);

        $this->assertSame([['iccid' => self::ICCID, 'sim_number' => '0771234567']], $r['valid']);
        $this->assertCount(3, $r['rejected']);
    }

    public function test_a_sim_or_a_number_twice_in_one_delivery_is_refused(): void
    {
        $r = ScanBatch::classifySims([
            ['iccid' => self::ICCID, 'sim_number' => '0771234567'],
            ['iccid' => self::ICCID, 'sim_number' => '0771234568'],
            ['iccid' => '8991101200003204511', 'sim_number' => '0771234567'],
        ]);

        $this->assertCount(1, $r['valid']);
        $this->assertSame('Scanned twice in this delivery.', $r['rejected'][0]['reason']);
        $this->assertStringContainsString('another SIM in this delivery', $r['rejected'][1]['reason']);
    }

    public function test_sim_batch_size_is_capped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScanBatch::classifySims(array_fill(0, ScanBatch::MAX + 1, ['iccid' => self::ICCID, 'sim_number' => '0771234567']));
    }
}
