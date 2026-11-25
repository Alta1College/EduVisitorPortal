<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

/**
 * Sends a batch of SMS. Implementations never throw for provider problems: every message gets a
 * result (accepted for delivery, or failed with an error code).
 */
interface SmsGateway
{
    /**
     * @param  list<SmsMessage>  $messages
     * @return array<string, SmsResult> reference => result
     */
    public function send(array $messages): array;
}
