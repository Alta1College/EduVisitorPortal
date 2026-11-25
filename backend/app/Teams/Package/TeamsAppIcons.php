<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams\Package;

use App\Settings\BrandStylesheet;
use Illuminate\Support\Str;

/**
 * The Teams app icons: the organisation's initials on its primary colour (color.png, 192x192)
 * and in white on transparent (outline.png, 32x32).
 *
 * Drawn with a small built-in pixel font and written as PNG with zlib only, because the
 * application images do not include the GD extension.
 */
final class TeamsAppIcons
{
    public const FALLBACK_COLOUR = '#2563eb';

    /** 5x7 pixel glyphs, one string of five bits per row. */
    private const GLYPHS = [
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01110', '10001', '10000', '10000', '10000', '10001', '01110'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01110', '10001', '10000', '10111', '10001', '10001', '01111'],
        'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'I' => ['01110', '00100', '00100', '00100', '00100', '00100', '01110'],
        'J' => ['00111', '00010', '00010', '00010', '00010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '10001', '11001', '10101', '10011', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
        'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'],
        'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
        'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
        '0' => ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
        '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
        '3' => ['11111', '00010', '00100', '00010', '00001', '10001', '01110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
        '6' => ['00110', '01000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00010', '01100'],
    ];

    public static function primaryColour(): string
    {
        return BrandStylesheet::tokens()['primary'] ?? self::FALLBACK_COLOUR;
    }

    /**
     * Up to two initials, e.g. "Edu Visitor Portal" -> "EV".
     */
    public static function initials(string $name): string
    {
        $initials = '';

        foreach (preg_split('/\s+/', strtoupper(Str::ascii($name))) ?: [] as $word) {
            if (preg_match('/[A-Z0-9]/', $word, $match) === 1) {
                $initials .= $match[0];
            }

            if (strlen($initials) === 2) {
                break;
            }
        }

        return $initials;
    }

    public static function color(string $name, string $background): string
    {
        $foreground = BrandStylesheet::readableTextOn($background);

        return self::render(192, self::initials($name), [...self::rgb($background), 255], [...self::rgb($foreground), 255], 0.6, 0.5);
    }

    public static function outline(string $name): string
    {
        return self::render(32, self::initials($name), [0, 0, 0, 0], [255, 255, 255, 255], 0.75, 0.65);
    }

    /**
     * @param  array{int, int, int, int}  $background  RGBA
     * @param  array{int, int, int, int}  $foreground  RGBA
     */
    private static function render(int $size, string $text, array $background, array $foreground, float $maxWidth, float $maxHeight): string
    {
        $pixels = array_fill(0, $size, array_fill(0, $size, $background));
        $glyphs = array_values(array_filter(array_map(fn (string $c): ?array => self::GLYPHS[$c] ?? null, str_split($text)), fn ($g) => $g !== null));

        if ($glyphs !== []) {
            $columns = count($glyphs) * 5 + count($glyphs) - 1;
            $scale = max(1, (int) floor(min($size * $maxWidth / $columns, $size * $maxHeight / 7)));
            $left = intdiv($size - $columns * $scale, 2);
            $top = intdiv($size - 7 * $scale, 2);

            foreach ($glyphs as $index => $rows) {
                $x0 = $left + $index * 6 * $scale;

                foreach ($rows as $row => $bits) {
                    foreach (str_split($bits) as $column => $bit) {
                        if ($bit !== '1') {
                            continue;
                        }

                        for ($dy = 0; $dy < $scale; $dy++) {
                            for ($dx = 0; $dx < $scale; $dx++) {
                                $pixels[$top + $row * $scale + $dy][$x0 + $column * $scale + $dx] = $foreground;
                            }
                        }
                    }
                }
            }
        }

        return self::png($size, $size, $pixels);
    }

    /**
     * @param  list<list<array{int, int, int, int}>>  $pixels  rows of RGBA
     */
    private static function png(int $width, int $height, array $pixels): string
    {
        $raw = '';

        foreach ($pixels as $row) {
            $raw .= "\0"; // filter: none

            foreach ($row as [$r, $g, $b, $a]) {
                $raw .= chr($r).chr($g).chr($b).chr($a);
            }
        }

        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw, 9))
            .$chunk('IEND', '');
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
