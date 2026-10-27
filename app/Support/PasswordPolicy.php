<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * One password rule for every place a password is chosen (new admin, change password, temporary password).
 * At least 10 characters with upper case, lower case and a number. Not checked against leaked-password
 * lists on purpose: that is a call to an outside service on every save.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /** bcrypt only reads the first 72 bytes; longer input is silently cut, so refuse it instead. */
    public const MAX_LENGTH = 72;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
    }

    /** Short sentence for forms. */
    public static function hint(): string
    {
        return 'At least ' . self::MIN_LENGTH . ' characters, with upper case, lower case and a number.';
    }

    /**
     * A random temporary password that satisfies the rule. Leaves out look-alike characters (0 O 1 l I)
     * because it is read out or typed by a person.
     */
    public static function generate(int $length = 14): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digit = '23456789';
        $all = $upper . $lower . $digit;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
        ];
        while (count($chars) < max($length, self::MIN_LENGTH)) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with a cryptographic random source.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}