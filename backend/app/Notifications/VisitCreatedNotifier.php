<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Notifications;

use App\Models\User;
use App\Models\Visit;
use App\Notifications\Host\VisitCreated;
use Throwable;

/**
 * Tells the host that someone else booked a visit for them (upstream's VisitCreated
 * notification, which upstream never sends). A failure is reported, never shown to the booker.
 */
final class VisitCreatedNotifier
{
    public static function notify(Visit $visit, User $creator): void
    {
        try {
            $host = $visit->loadMissing('host')->host;

            if (! $host instanceof User || ! $host->is_active || (int) $host->id === (int) $creator->id) {
                return;
            }

            $host->notify((new VisitCreated($visit, $visit->loadMissing('visitors')->visitors))->afterCommit());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
