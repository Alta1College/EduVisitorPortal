<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

/**
 * Fork (Phase 11): text typed by visitors, made safe to place inside a Markdown e-mail.
 * Blade's {{ }} escapes HTML but not Markdown, so a name like "[Click here](https://evil)" would
 * become a link in an e-mail sent from the organisation's address. Markdown punctuation is
 * backslash-escaped and line breaks are flattened, so the text shows exactly as typed.
 */
final class MarkdownText
{
    public static function escape(?string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', (string) $text) ?? '';

        return (string) preg_replace('/([\\\\`*_{}\[\]()<>#+\-.!|~])/', '\\\\$1', trim($text));
    }
}
