<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use App\Audit\AuditLogger;
use App\Emergency\Sms\PhoneNumber;
use App\Models\Visitor;

/**
 * Visitor details stay editable (typos, new company), but every change is audited with the
 * old and new value of each changed field and the actor (null for a visitor updating their
 * own details on the kiosk or QR page).
 *
 * Contact details are stored masked, the same way the emergency broadcast log does
 * (first letter and domain of an e-mail; last three digits of a phone number), so the audit
 * log, which is kept longer than visits, does not become a second copy of them. Free-text
 * notes are recorded as changed without their content.
 */
class VisitorChangeAudit
{
    private const PLAIN = ['first_name', 'name', 'title', 'salutation', 'company'];

    private const MASKED = ['email', 'phone'];

    private const CONTENT_OMITTED = ['notes'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function updated(Visitor $visitor): void
    {
        $changes = [];

        foreach (array_keys($visitor->getChanges()) as $field) {
            // phone_tail is a lookup key derived from phone; the phone change itself is logged masked.
            if (in_array($field, ['updated_at', 'created_at', 'phone_tail'], true)) {
                continue;
            }

            $old = $visitor->getRawOriginal($field);
            $new = $visitor->getAttributes()[$field] ?? null;

            $changes[$field] = match (true) {
                in_array($field, self::PLAIN, true) => ['old' => $old, 'new' => $new],
                in_array($field, self::MASKED, true) => ['old' => self::mask($field, $old), 'new' => self::mask($field, $new)],
                in_array($field, self::CONTENT_OMITTED, true) => ['changed' => true],
                default => ['old' => $old, 'new' => $new],
            };
        }

        if ($changes === []) {
            return;
        }

        $this->audit->record('visitor.updated', subject: $visitor, context: ['changes' => $changes]);
    }

    public function deleted(Visitor $visitor): void
    {
        $this->audit->record('visitor.deleted', subject: $visitor);
    }

    public static function mask(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if ($field === 'phone') {
            return PhoneNumber::mask($value);
        }

        $at = mb_strpos($value, '@');

        return $at === false
            ? mb_substr($value, 0, 1).'***'
            : mb_substr(mb_substr($value, 0, 1).'***'.mb_substr($value, $at), 0, 64);
    }
}
