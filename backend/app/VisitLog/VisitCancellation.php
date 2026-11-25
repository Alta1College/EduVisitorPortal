<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use App\Audit\AuditLogger;
use App\Enums\VisitStatusEnum;
use App\Models\User;
use App\Models\Visit;

/**
 * Cancelling replaces deleting in the admin panel: the visit stays in the log with status
 * "canceled", who cancelled it and when. Visits with attendance cannot be cancelled
 * (VisitPolicy::cancel and the VisitLock model guard).
 */
class VisitCancellation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function canBeCancelled(Visit $visit): bool
    {
        return ! in_array($visit->status, [VisitStatusEnum::Canceled->value, VisitStatusEnum::Completed->value], true)
            && ! VisitLock::hasAttendance($visit);
    }

    public function cancel(Visit $visit, User $user): void
    {
        $visit->forceFill([
            'status' => VisitStatusEnum::Canceled->value,
            'canceled_at' => now(),
            'canceled_by_user_id' => $user->getKey(),
            'recurrence_is_modified' => $visit->recurring_visit_series_id ? true : $visit->recurrence_is_modified,
        ])->save();

        $this->audit->record('visit.canceled', subject: $visit, site: $visit->site_id, actorUserId: $user->getKey());
    }

    public function restore(Visit $visit, User $user): void
    {
        $visit->forceFill([
            'status' => VisitStatusEnum::Planned->value,
            'canceled_at' => null,
            'canceled_by_user_id' => null,
        ])->save();

        $this->audit->record('visit.restored', subject: $visit, site: $visit->site_id, actorUserId: $user->getKey());
    }
}
