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
}
