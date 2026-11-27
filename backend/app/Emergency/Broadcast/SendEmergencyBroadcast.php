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
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
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

    /** About two minutes in all: long enough for a short provider outage, short for an emergency. */
    public int $tries = 5;

    /** @var list<int> seconds before each further try */
    public array $backoff = [5, 15, 30, 60];

    public int $timeout = 90;

    public function __construct(public readonly int $rollCallId) {}

    public function handle(EmergencyBroadcast $broadcast): void
    {
        if (! $broadcast->send($this->rollCallId)) {
            return;
        }

        // Some texts hit a temporary problem and are still pending: try again shortly, or give up
        // on them (shown as failed on the board) after the last try.
        $attempt = $this->attempts();

        // The sync queue (no worker) cannot come back later, so it gives up at once.
        if ($this->job !== null && ! $this->job instanceof SyncJob && $attempt < $this->tries) {
            $this->release($this->backoff[$attempt - 1] ?? 60);

            return;
        }

        $this->failPendingSms();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
        $this->failPendingSms();
    }

    private function failPendingSms(): void
    {
        EmergencyNotification::query()
            ->where('roll_call_id', $this->rollCallId)
            ->where('channel', EmergencyNotification::CHANNEL_SMS)
            ->where('status', EmergencyNotification::PENDING)
            ->update(['status' => EmergencyNotification::FAILED, 'error_code' => DB::raw("COALESCE(error_code, 'ERROR')")]);
    }
}
