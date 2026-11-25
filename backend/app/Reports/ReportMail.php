<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An emailed report: a short summary in the message, the full report as PDF and the figures as
 * CSV attachments.
 */
class ReportMail extends Mailable
{
    /**
     * @param  list<string>  $lines  summary lines (plain text)
     * @param  array<string, array{0: string, 1: string}>  $files  file name => [content, MIME type]
     */
    public function __construct(
        public readonly string $title,
        public readonly string $period,
        public readonly array $lines,
        public readonly array $files,
        public readonly bool $pdfMissing = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' – '.$this->period);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reports.report');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->files as $name => [$content, $mime]) {
            $attachments[] = Attachment::fromData(fn (): string => $content, $name)->withMime($mime);
        }

        return $attachments;
    }
}
