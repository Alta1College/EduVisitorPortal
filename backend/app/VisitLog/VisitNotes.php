<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only notes on a visit. A note is added as a new, timestamped and attributed line at
 * the end of the visit's notes; existing text is never rewritten. This is the one change
 * still possible on a visit with attendance (VisitLock allows an append).
 */
class VisitNotes
{
    public const MAX_LENGTH = 1000;

    public function __construct(private readonly AuditLogger $audit) {}

    public function append(Visit $visit, string $text, User $author): Visit
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? '');

        if ($text === '') {
            throw new InvalidArgumentException('A note must not be empty.');
        }

        $text = Str::limit($text, self::MAX_LENGTH, '');
        $stamp = now()->timezone($visit->site?->timezone ?: (string) config('app.timezone'))->format('Y-m-d H:i');
        $line = sprintf('[%s, %s] %s', $stamp, trim((string) $author->fullName) ?: (string) $author->email, $text);

        $visit->refresh();
        $existing = (string) $visit->notes;
        $visit->notes = $existing === '' ? $line : $existing."\n\n".$line;
        $visit->save();

        $this->audit->record('visit.note_added', subject: $visit, site: $visit->site_id, context: [
            'note' => $text,
            'visit_has_attendance' => VisitLock::hasAttendance($visit),
        ], actorUserId: $author->getKey());

        return $visit;
    }
}
