<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Broadcast;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends the emergency messages for one roll call. Retried if it fails part-way: each run sends
 * only the messages still pending (EmergencyBroadcast::send), so nobody is texted twice for a
 * message already recorded, and nobody is skipped. Problems are stored per message and shown on
 * the roll call board.
 */
class SendEmergencyBroadcast implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before the second and third try */
    public array $backoff = [5, 20];

    public int $timeout = 90;

    public function __construct(public readonly int $rollCallId) {}

    public function handle(EmergencyBroadcast $broadcast): void
    {
        $broadcast->send($this->rollCallId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
        EmergencyNotification::query()
            ->where('roll_call_id', $this->rollCallId)
            ->where('channel', EmergencyNotification::CHANNEL_SMS)
            ->where('status', EmergencyNotification::PENDING)
            ->update(['status' => EmergencyNotification::FAILED, 'error_code' => 'ERROR']);
    }
}
