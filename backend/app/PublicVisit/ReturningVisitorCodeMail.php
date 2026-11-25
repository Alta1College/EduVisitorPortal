<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time code a returning visitor enters to change their saved details.
 */
class ReturningVisitorCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $siteName,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Ihr Bestätigungscode: :code', ['code' => $this->code]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.visitor.returning-code');
    }
}
