<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

final class SmsMessage
{
    /**
     * @param  string  $to  E.164, e.g. +61412345678
     * @param  string  $reference  Unique; echoed in delivery receipts
     * @param  array<string, scalar>  $metadata
     */
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly string $reference,
        public readonly array $metadata = [],
    ) {}
}
