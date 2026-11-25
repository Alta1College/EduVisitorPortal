<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

/**
 * Turns what visitors type as their phone number into E.164 for SMS, or null when it is not
 * plausibly a mobile number. Australian formats (the MessagePort network) are recognised without
 * a country code: 04xx xxx xxx, 4xx xxx xxx, 61 4xx..., 0061 4xx...; Australian landlines are
 * rejected. Other countries need an explicit +country code.
 */
final class PhoneNumber
{
    public static function toMobileE164(?string $input): ?string
    {
        $raw = trim((string) $input);

        if ($raw === '' || preg_match('/^\+?[0-9\s().\/-]+$/', $raw) !== 1) {
            return null;
        }

        $plus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (! $plus && str_starts_with($digits, '00')) {
            $plus = true;
            $digits = substr($digits, 2);
        }

        if ($plus || (str_starts_with($digits, '61') && strlen($digits) === 11)) {
            // Strip a trunk zero written after the country code: +61 (0)4xx...
            if (str_starts_with($digits, '610')) {
                $digits = '61'.substr($digits, 3);
            }

            if (str_starts_with($digits, '61')) {
                return preg_match('/^614\d{8}$/', $digits) === 1 ? '+'.$digits : null;
            }

            return $plus && preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? '+'.$digits : null;
        }

        if (preg_match('/^04\d{8}$/', $digits) === 1) {
            return '+61'.substr($digits, 1);
        }

        if (preg_match('/^4\d{8}$/', $digits) === 1) {
            return '+61'.$digits;
        }

        return null;
    }

    /**
     * Last three digits only, for logs and the notifications table.
     */
    public static function mask(string $number): string
    {
        $digits = (string) preg_replace('/\D/', '', $number);

        return str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
    }
}
