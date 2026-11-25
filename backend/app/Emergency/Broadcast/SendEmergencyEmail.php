<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Broadcast;

use App\Emergency\RollCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One emergency e-mail. A job each, so a slow mail server delays nobody else's message and the
 * outcome is recorded per person. Sent once; a failure is recorded, not retried.
 */
class SendEmergencyEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $row = EmergencyNotification::query()->with(['visitor', 'rollCall.site'])->find($this->notificationId);

        if ($row === null || $row->status !== EmergencyNotification::PENDING) {
            return;
        }

        $email = (string) $row->visitor?->email;

        if ($row->rollCall === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $row->forceFill(['status' => EmergencyNotification::FAILED, 'error_code' => 'NO_EMAIL'])->save();

            return;
        }

        try {
            $mail = new EmergencyBroadcastMail((string) $row->rollCall->site->name, $row->rollCall->kind === RollCall::KIND_DRILL);
            Mail::to($email)->locale((string) config('app.locale'))->send($mail);
            $row->forceFill(['status' => EmergencyNotification::SENT])->save();
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('emergency_email_failed', ['notification_id' => $row->getKey(), 'error' => class_basename($exception)]);
            $row->forceFill(['status' => EmergencyNotification::FAILED, 'error_code' => 'MAIL_ERROR'])->save();
        }
    }
}
