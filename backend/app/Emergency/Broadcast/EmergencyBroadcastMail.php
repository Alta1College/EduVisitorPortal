<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Broadcast;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Short e-mail to a visitor on site when a roll call starts (emergency, or a drill with
 * messages). Same wording as the SMS; no links.
 */
class EmergencyBroadcastMail extends Mailable
{
    public function __construct(
        public readonly string $siteName,
        public readonly bool $drill,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->drill
            ? __('Übung in :site', ['site' => $this->siteName])
            : __('Notfall in :site', ['site' => $this->siteName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.emergency.broadcast');
    }
}
