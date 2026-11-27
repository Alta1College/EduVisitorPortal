<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

final class SmsResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $errorCode = null,
        public readonly bool $temporary = false,
    ) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    /**
     * The provider refused this message (bad number, opted out, bad credentials): trying again
     * would not help.
     */
    public static function failed(string $errorCode): self
    {
        return new self(false, mb_substr($errorCode, 0, 64));
    }

    /**
     * The provider could not be reached or had a temporary problem (timeout, 429, 5xx): the
     * message is tried again shortly. A timeout may mean the provider did take it, so a retry can
     * repeat a text; in an emergency a duplicate is better than nothing.
     */
    public static function temporaryFailure(string $errorCode): self
    {
        return new self(false, mb_substr($errorCode, 0, 64), true);
    }
}
