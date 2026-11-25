<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use App\Audit\AuditLogger;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitVisitor;
use Illuminate\Support\ServiceProvider;

/**
 * Immutable visitor log (docs/fork/phase-11-security-validation.md, "Fixed after phase 11").
 * Model guards are the server-side backstop behind VisitPolicy: they also stop code paths
 * that do not go through a policy. The retention purge deletes with the query builder (no
 * model events) and keeps working.
 */
class VisitLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Visit::updating(fn (Visit $visit) => VisitLock::guardUpdate($visit));
        Visit::deleting(fn (Visit $visit) => VisitLock::guardDelete($visit));
        Visit::deleted(fn (Visit $visit) => $this->app->make(AuditLogger::class)
            ->record('visit.deleted', subject: $visit, site: $visit->site_id));

        // Detaching a visitor (Visit::visitors()->sync()/detach() use this pivot class and fire
        // its events) would delete a recorded attendance.
        VisitVisitor::deleting(function (VisitVisitor $attendance): void {
            if (VisitLock::attendanceExistsFor((int) $attendance->getAttribute('visit_id'), (int) $attendance->getAttribute('visitor_id'))) {
                throw new AttendedVisitLocked('A recorded attendance cannot be removed from a visit.');
            }
        });

        // Deleting a visitor would cascade to their attendance rows.
        Visitor::deleting(function (Visitor $visitor): void {
            if (VisitLock::visitorHasAttendance((int) $visitor->getKey())) {
                throw new AttendedVisitLocked(sprintf('Visitor %s has recorded attendance and cannot be deleted.', $visitor->getKey()));
            }
        });

        Visitor::updated(fn (Visitor $visitor) => $this->app->make(VisitorChangeAudit::class)->updated($visitor));
        Visitor::deleted(fn (Visitor $visitor) => $this->app->make(VisitorChangeAudit::class)->deleted($visitor));
    }
}
