<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use Illuminate\Support\Str;

/**
 * A host notification as a Teams chat message from the app's bot: an Adaptive Card (1.5) with a
 * heading, facts, optional lines (the daily reminder's visits), an optional photo and an
 * "Open in portal" button. All strings are already in the host's language.
 *
 * Adaptive Card text renders Markdown, and most of the values are typed by visitors, so every
 * value is escaped (escape()) and kept on one line: a name like "[Click](https://evil)" shows as
 * typed instead of becoming a link in a message from the organisation's app.
 */
final class TeamsChatMessage
{
    public const CARD_VERSION = '1.5';

    /**
     * @param  list<array{string, string}>  $facts  [label, value]; empty values are left out
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $heading,
        public readonly string $summary,
        public readonly array $facts,
        public readonly string $url,
        public readonly string $buttonLabel,
        public readonly array $lines = [],
        public readonly ?string $photoUrl = null,
        public readonly ?string $photoAlt = null,
    ) {}

    /**
     * The Bot Connector message activity carrying the card.
     *
     * @return array<string, mixed>
     */
    public function activity(): array
    {
        return [
            'type' => 'message',
            // Shown in notifications and the chat list.
            'summary' => Str::limit($this->summary, 150),
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => $this->card(),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(): array
    {
        $body = [[
            'type' => 'TextBlock',
            'text' => self::escape($this->heading),
            'size' => 'Large',
            'weight' => 'Bolder',
            'wrap' => true,
        ]];

        if ($this->photoUrl !== null) {
            $body[] = [
                'type' => 'Image',
                'url' => $this->photoUrl,
                'altText' => self::escape((string) $this->photoAlt),
                'size' => 'Large',
            ];
        }

        $facts = [];

        foreach ($this->facts as [$label, $value]) {
            if (trim($value) !== '') {
                $facts[] = ['title' => self::escape($label), 'value' => self::escape($value)];
            }
        }

        if ($facts !== []) {
            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }

        foreach ($this->lines as $line) {
            $body[] = ['type' => 'TextBlock', 'text' => self::escape($line), 'wrap' => true, 'spacing' => 'Small'];
        }

        return [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => self::CARD_VERSION,
            'body' => $body,
            'actions' => [[
                'type' => 'Action.OpenUrl',
                'title' => $this->buttonLabel,
                'url' => $this->url,
            ]],
        ];
    }

    /**
     * Plain text for an Adaptive Card TextBlock / Fact: one line, at most 300 characters, Markdown
     * punctuation backslash-escaped (same rule as App\Support\MarkdownText for e-mails). Dots and
     * hyphens are only escaped where they could start a list ("1. ", "- "), so "Pty. Ltd." and
     * "Smith-Jones" read normally.
     */
    public static function escape(?string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        $text = Str::limit($text, 300);
        $text = (string) preg_replace('/([\\\\`*_{}\[\]()<>#!|~])/', '\\\\$1', $text);

        // Line-start list markers ("#" and ">" are already escaped above).
        $text = (string) preg_replace('/^(\d+)\.(?=\s|$)/', '$1\\\\.', $text);

        return (string) preg_replace('/^([+\-])(?=\s|$)/', '\\\\$1', $text);
    }
}
