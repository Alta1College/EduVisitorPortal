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
    ) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    public static function failed(string $errorCode): self
    {
        return new self(false, mb_substr($errorCode, 0, 64));
    }
}
