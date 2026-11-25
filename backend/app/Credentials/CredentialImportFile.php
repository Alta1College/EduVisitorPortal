<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use Illuminate\Support\Str;

/**
 * Reads a credential import CSV (e.g. an HR export saved as CSV). Column names are matched
 * loosely so most exports work as they are: first name, last name / surname, email, mobile /
 * phone, number / card number / WWCC, expiry / expires.
 */
final class CredentialImportFile
{
    public const MAX_ROWS = 5000;

    private const COLUMNS = [
        'first_name' => ['first name', 'firstname', 'given name', 'given names', 'first_name', 'preferred name'],
        'last_name' => ['last name', 'lastname', 'surname', 'family name', 'last_name'],
        'email' => ['email', 'email address', 'e-mail', 'work email'],
        'phone' => ['mobile', 'mobile number', 'phone', 'phone number', 'mobile phone'],
        'number' => ['number', 'card number', 'card no', 'wwcc', 'wwcc number', 'wwc number', 'wwc card number', 'credential number', 'blue card number'],
        'expires_on' => ['expiry', 'expiry date', 'expires', 'expires on', 'expiration date', 'expires_on', 'wwcc expiry'],
    ];

    /**
     * Rows keyed by spreadsheet line number (header = line 1), or null when a required column
     * (first name, last name, number) is missing.
     *
     * @return array<int, array<string, ?string>>|null
     */
    public static function rows(string $path): ?array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                return null;
            }

            // Strip a UTF-8 byte order mark (Excel "CSV UTF-8").
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $map = self::map($header);

            foreach (['first_name', 'last_name', 'number'] as $required) {
                if (! isset($map[$required])) {
                    return null;
                }
            }

            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($handle, escape: '')) !== false && count($rows) < self::MAX_ROWS) {
                $line++;

                if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                    continue;
                }

                $row = [];

                foreach ($map as $field => $index) {
                    $value = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
                    $row[$field] = $value !== '' ? $value : null;
                }

                $rows[$line] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<?string>  $header
     * @return array<string, int> field => column index
     */
    private static function map(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $name = Str::lower(trim(preg_replace('/\s+/', ' ', (string) $name) ?? ''));

            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }
}
