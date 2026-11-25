<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

/**
 * Keeps an SMS to one segment where possible: 160 GSM-7 characters (extension characters count
 * twice), or 70 when the text needs Unicode. Typographic punctuation is replaced with its GSM
 * equivalent, and the site name is shortened if the text would otherwise not fit.
 */
final class SmsText
{
    public const GSM_SEGMENT = 160;

    public const UNICODE_SEGMENT = 70;

    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\[~]|€\f";

    /** Never shorten a site name below this many characters. */
    private const MIN_SITE_LENGTH = 12;

    public static function sanitize(string $text): string
    {
        $text = strtr($text, [
            '–' => '-', '—' => '-', '‘' => "'", '’' => "'", '‚' => "'", '“' => '"', '”' => '"', '„' => '"',
            '…' => '...', "\u{00A0}" => ' ', '«' => '"', '»' => '"',
        ]);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    /**
     * GSM-7 length, or null if the text needs Unicode (UCS-2).
     */
    public static function gsmLength(string $text): ?int
    {
        $length = 0;

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $length++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $length += 2;
            } else {
                return null;
            }
        }

        return $length;
    }

    public static function fitsOneSegment(string $text): bool
    {
        $gsm = self::gsmLength($text);

        return $gsm !== null ? $gsm <= self::GSM_SEGMENT : mb_strlen($text) <= self::UNICODE_SEGMENT;
    }

    /**
     * @param  callable(string): string  $build  Builds the message for a given site name
     */
    public static function withSite(callable $build, string $site): string
    {
        $site = self::sanitize($site);
        $text = self::sanitize($build($site));

        // If even an empty site name cannot fit (e.g. a long Unicode translation), it will be
        // sent as several parts anyway: keep the full name.
        if (self::fitsOneSegment($text) || ! self::fitsOneSegment(self::sanitize($build('')))) {
            return $text;
        }

        while (mb_strlen($site) > self::MIN_SITE_LENGTH && ! self::fitsOneSegment($text)) {
            $site = rtrim(mb_substr($site, 0, mb_strlen($site) - 1));
            $text = self::sanitize($build($site.'.'));
        }

        return $text;
    }
}
