<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

/**
 * CSV for spreadsheets: UTF-8 with a byte-order mark (so Excel shows accents), and cells that
 * a spreadsheet would run as a formula (=, +, -, @, tab, carriage return) are prefixed with an
 * apostrophe so visitor-entered text cannot become a formula. Plain phone numbers and numbers
 * (digits, spaces, brackets, hyphens, an optional leading +) are left alone: they cannot call a
 * function, and "+61 412 345 678" must stay a phone number.
 */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(self::cell(...), $header), escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), array_values($row)), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        if (preg_match('/^\+?[0-9 ()-]+$/', $value) === 1) {
            return $value;
        }

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
