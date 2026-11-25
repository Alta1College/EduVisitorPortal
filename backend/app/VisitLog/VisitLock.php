<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use App\Enums\VisitStatusEnum;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * The visitor log is history: once anyone has signed in to a visit (any attendance row with
 * checked_in_at), the visit is view-only. Only these changes remain possible:
 *
 * - appending a note (VisitNotes::append; the new notes must start with the old notes),
 * - status "completed" (finishing a visit; the scheduled task does this in bulk),
 * - legal-hold fields used by the retention process.
 *
 * Live attendance (check-in/out, self sign-out, automatic sign-out) is stored on the
 * visit_visitor pivot and is not affected. Deleting is only possible through the retention
 * purge, which deletes in bulk without model events.
 */
final class VisitLock
{
    /** Attributes that may still change on a visit with attendance. */
    public const MUTABLE_WHEN_ATTENDED = [
        'updated_at',
        'retention_hold_until',
        'retention_hold_reason',
        'retention_hold_by_user_id',
    ];

    public static function hasAttendance(Visit|int $visit): bool
    {
        $id = $visit instanceof Visit ? $visit->getKey() : $visit;

        if ($id === null) {
            return false;
        }

        return DB::table('visit_visitor')
            ->where('visit_id', $id)
            ->whereNotNull('checked_in_at')
            ->exists();
    }

    public static function attendanceExistsFor(int $visitId, int $visitorId): bool
    {
        return DB::table('visit_visitor')
            ->where('visit_id', $visitId)
            ->where('visitor_id', $visitorId)
            ->whereNotNull('checked_in_at')
            ->exists();
    }

    public static function visitorHasAttendance(int $visitorId): bool
    {
        return DB::table('visit_visitor')
            ->where('visitor_id', $visitorId)
            ->whereNotNull('checked_in_at')
            ->exists();
    }

    /**
     * @throws AttendedVisitLocked when a protected attribute of an attended visit changes
     */
    public static function guardUpdate(Visit $visit): void
    {
        if (! $visit->exists || ! self::hasAttendance($visit)) {
            return;
        }

        $blocked = [];

        foreach (array_keys($visit->getDirty()) as $attribute) {
            if (in_array($attribute, self::MUTABLE_WHEN_ATTENDED, true)) {
                continue;
            }

            if ($attribute === 'status' && $visit->getAttribute('status') === VisitStatusEnum::Completed->value) {
                continue;
            }

            if ($attribute === 'notes' && self::isAppend((string) $visit->getOriginal('notes'), (string) $visit->getAttribute('notes'))) {
                continue;
            }

            $blocked[] = $attribute;
        }

        if ($blocked !== []) {
            throw new AttendedVisitLocked(sprintf(
                'Visit %s has attendance and is read-only; refused change to: %s.',
                $visit->getKey(),
                implode(', ', $blocked),
            ));
        }
    }

    /**
     * @throws AttendedVisitLocked
     */
    public static function guardDelete(Visit $visit): void
    {
        if (self::hasAttendance($visit)) {
            throw new AttendedVisitLocked(sprintf('Visit %s has attendance and cannot be deleted.', $visit->getKey()));
        }
    }

    private static function isAppend(string $old, string $new): bool
    {
        return $old === '' || ($new !== $old && str_starts_with($new, $old));
    }
}
