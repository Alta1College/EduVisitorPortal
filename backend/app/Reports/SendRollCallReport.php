<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Emergency\RollCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Emails the record of a roll call (drill or emergency) once it has ended.
 */
class SendRollCallReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $rollCallId) {}

    public function handle(Reports $reports): void
    {
        $rollCall = RollCall::query()->find($this->rollCallId);

        if ($rollCall !== null) {
            $reports->sendRollCall($rollCall);
        }
    }
}
