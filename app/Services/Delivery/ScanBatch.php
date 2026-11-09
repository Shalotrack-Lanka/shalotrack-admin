<?php

namespace App\Services\Delivery;

use App\Support\Imei;

/**
 * Cleans and checks a batch of scanned identifiers BEFORE anything touches the database. Pure (no database,
 * no request), so it is fully unit tested. Scanners add stray whitespace and sometimes read a code twice;
 * a wrong digit is caught by the IMEI check digit.
 */
final class ScanBatch
{
    public const MAX = 500;
    public const DEVICE = 'DEVICE';   // same values as self::DEVICE / KIND_SIM
    public const SIM = 'SIM';

    /**
     * @param  array<int,mixed> $raw
     * @return array{valid: list<string>, rejected: list<array{identifier: string, reason: string}>}
     */
    public static function classify(string $kind, array $raw): array
    {
        if ($kind !== self::DEVICE && $kind !== self::SIM) {
            throw new \InvalidArgumentException('Unknown delivery kind.');
        }
        if (count($raw) > self::MAX) {
            throw new \InvalidArgumentException('A delivery can hold at most ' . self::MAX . ' scans at a time.');
        }

        $valid = [];
        $rejected = [];
        $seen = [];

        foreach ($raw as $item) {
            if (! is_string($item) && ! is_int($item)) {
                $rejected[] = ['identifier' => '(unreadable)', 'reason' => 'Not a valid scan.'];
                continue;
            }

            $id = preg_replace('/\s+/', '', (string) $item);
            if ($id === '') {
                continue;
            }
            $shown = mb_substr($id, 0, 24);

            if ($kind === self::DEVICE) {
                if (! Imei::isValid($id)) {
                    $rejected[] = ['identifier' => $shown, 'reason' => 'Not a valid IMEI (15 digits with a correct check digit): rescan it.'];
                    continue;
                }
            } elseif (! Imei::isValidIccid($id)) {
                $rejected[] = ['identifier' => $shown, 'reason' => 'Not a valid SIM ICCID (19 or 20 digits): rescan it.'];
                continue;
            }

            if (isset($seen[$id])) {
                $rejected[] = ['identifier' => $id, 'reason' => 'Scanned twice in this delivery.'];
                continue;
            }
            $seen[$id] = true;
            $valid[] = $id;
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }
    /**
     * A SIM delivery: every ICCID with the 10-digit SIM number printed on its pack (scanned or typed).
     *
     * @param  array<int,mixed> $raw  [{iccid, sim_number}, ...]
     * @return array{valid: list<array{iccid: string, sim_number: string}>, rejected: list<array{identifier: string, reason: string}>}
     */
    public static function classifySims(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new \InvalidArgumentException('A delivery can hold at most ' . self::MAX . ' scans at a time.');
        }

        $valid = [];
        $rejected = [];
        $seenIccid = [];
        $seenNumber = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                $rejected[] = ['identifier' => '(unreadable)', 'reason' => 'Not a valid scan.'];
                continue;
            }
            $iccid = preg_replace('/\s+/', '', (string) ($row['iccid'] ?? ''));
            $number = preg_replace('/\s+/', '', (string) ($row['sim_number'] ?? ''));
            if ($iccid === '' && $number === '') {
                continue;
            }
            $shown = mb_substr($iccid !== '' ? $iccid : $number, 0, 24);

            if (! Imei::isValidIccid($iccid)) {
                $rejected[] = ['identifier' => $shown, 'reason' => 'Not a valid SIM ICCID (19 or 20 digits): rescan it.'];
                continue;
            }
            if (! preg_match('/^\d{10}$/', $number)) {
                $rejected[] = ['identifier' => $iccid, 'reason' => 'The SIM number must be exactly 10 digits.'];
                continue;
            }
            if (isset($seenIccid[$iccid])) {
                $rejected[] = ['identifier' => $iccid, 'reason' => 'Scanned twice in this delivery.'];
                continue;
            }
            if (isset($seenNumber[$number])) {
                $rejected[] = ['identifier' => $iccid, 'reason' => "SIM number {$number} is on another SIM in this delivery."];
                continue;
            }
            $seenIccid[$iccid] = true;
            $seenNumber[$number] = true;
            $valid[] = ['iccid' => $iccid, 'sim_number' => $number];
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }
}
