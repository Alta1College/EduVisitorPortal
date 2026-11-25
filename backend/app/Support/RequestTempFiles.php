<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

/**
 * Fork (Phase 11): temporary files that live only for the current request (or queued job) and
 * are deleted when it ends, so anonymous form submissions cannot slowly fill the server's disk.
 */
final class RequestTempFiles
{
    /** @var list<string> */
    private static array $paths = [];

    private static bool $cleanupRegistered = false;

    public static function write(string $prefix, string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        self::$paths[] = $path;

        if (! self::$cleanupRegistered) {
            self::$cleanupRegistered = true;
            app()->terminating(static fn () => self::cleanUp());
        }

        return $path;
    }

    public static function cleanUp(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        self::$paths = [];
    }
}
