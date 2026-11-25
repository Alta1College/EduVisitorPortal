<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\PublicVisit\ReturningVisitors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps one credential record per person and type, filled from sign-in answers, staff entry and
 * imports, and records staff checks against the issuing body. Card numbers are never written to
 * the audit log.
 */
class CredentialRegister
{
    public const MIN_SEARCH_LENGTH = 3;

    public const MAX_RESULTS = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReturningVisitors $returning,
    ) {}

    /**
     * Card numbers compare without spaces, dashes or case ("0123 456A" = "0123456a").
     */
    public static function normalise(string $number): string
    {
        return Str::upper((string) preg_replace('/[\s\-\/.]+/', '', trim($number)));
    }

    public static function hash(string $number): string
    {
        return hash_hmac('sha256', self::normalise($number), 'credential-number|'.(string) config('app.key'));
    }

    /**
     * Store a number for a person. The same number keeps its check history; a different number
     * starts again as unchecked.
     */
    public function record(Visitor $visitor, CredentialType $type, string $number, string $source, ?Carbon $expiresOn = null, ?User $actor = null): VisitorCredential
    {
        $number = trim($number);
        $hash = self::hash($number);
        $credential = VisitorCredential::query()->firstOrNew(['visitor_id' => $visitor->getKey(), 'credential_type_id' => $type->getKey()]);
        $changed = ! $credential->exists || $credential->number_hash !== $hash;

        if ($changed) {
            $credential->fill([
                'number' => $number,
                'number_hash' => $hash,
                'status' => VisitorCredential::UNCHECKED,
                'checked_at' => null,
                'checked_by_user_id' => null,
                'check_note' => null,
                'expires_on' => $expiresOn,
                'source' => $source,
            ]);
        } elseif ($expiresOn !== null) {
            $credential->expires_on = $expiresOn;
        }

        $credential->save();

        if ($changed) {
            $this->audit->record('credential.recorded', subject: $credential, context: [
                'visitor_id' => $visitor->getKey(),
                'credential_type_id' => $type->getKey(),
                'source' => $source,
            ], actorUserId: $actor?->getKey());
        }

        return $credential;
    }

    /**
     * Staff checked the credential with the issuing body (e.g. its online validation page).
     */
    public function markChecked(VisitorCredential $credential, User $user, bool $valid, ?Carbon $expiresOn = null, ?string $note = null): VisitorCredential
    {
        $credential->forceFill([
            'status' => $valid ? VisitorCredential::VALID : VisitorCredential::INVALID,
            'checked_at' => now(),
            'checked_by_user_id' => $user->getKey(),
            'check_note' => filled($note) ? Str::limit(trim((string) $note), 250, '') : null,
            'expires_on' => $expiresOn ?? $credential->expires_on,
        ])->save();

        $this->audit->record('credential.checked', subject: $credential, context: [
            'visitor_id' => $credential->visitor_id,
            'credential_type_id' => $credential->credential_type_id,
            'result' => $credential->status,
        ], actorUserId: $user->getKey());

        return $credential;
    }

    /**
     * Search by card number (exact, ignoring spaces), or by name or email. The query itself is
     * not logged, only what kind of search it was.
     *
     * @return Collection<int, VisitorCredential>
     */
    public function search(string $query, User $user, ?int $typeId = null): Collection
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return collect();
        }

        $base = VisitorCredential::query()->with(['visitor', 'type', 'checkedBy'])
            ->when($typeId !== null, fn ($q) => $q->where('credential_type_id', $typeId));
        $kind = 'name';

        if (preg_match('/\d/', $query) === 1 && ! str_contains($query, '@')) {
            $kind = 'number';
            $results = (clone $base)->where('number_hash', self::hash($query))->limit(self::MAX_RESULTS)->get();
        } else {
            $like = '%'.addcslashes($query, '%_\\').'%';
            $kind = str_contains($query, '@') ? 'email' : 'name';
            $results = (clone $base)->whereHas('visitor', fn ($q) => $q
                ->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', name) LIKE ?", [$like]))
                ->limit(self::MAX_RESULTS)->get();
        }

        $this->audit->record('credential.searched', context: ['kind' => $kind, 'results' => $results->count()], actorUserId: $user->getKey());

        return $results;
    }

    /**
     * Records that need staff action (unchecked, invalid, expired, re-check due), most urgent
     * types first.
     *
     * @return Collection<int, VisitorCredential>
     */
    public function needingAttention(?int $typeId = null, int $limit = 100): Collection
    {
        $today = today()->toDateString();

        // The same rules as VisitorCredential::needsAttention, in SQL, so no record is missed
        // however many there are; the PHP check stays as a safeguard.
        return VisitorCredential::query()->with(['visitor', 'type', 'checkedBy'])
            ->when($typeId !== null, fn ($q) => $q->where('credential_type_id', $typeId))
            ->whereHas('type', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q
                ->where('status', '<>', VisitorCredential::VALID)
                ->orWhereNull('checked_at')
                ->orWhere('expires_on', '<', $today)
                ->orWhereHas('type', fn ($type) => $type->whereNotNull('recheck_days')
                    ->whereRaw('date_add(visitor_credentials.checked_at, interval credential_types.recheck_days day) < ?', [$today])))
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->filter(fn (VisitorCredential $c): bool => $c->needsAttention())
            ->values();
    }

    /**
     * People signed in at the site now whose visitor type asks for a credential they have not
     * got, or whose credential needs attention.
     *
     * @return list<array{visitor: Visitor, type: CredentialType, credential: ?VisitorCredential, state: string, checked_in_at: Carbon}>
     */
    public function onSiteAttention(Site $site): array
    {
        $rows = DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->join('form_field_assignments', 'form_field_assignments.visitor_type_id', '=', 'visit_visitor.visitor_type_id')
            ->join('form_fields', 'form_fields.id', '=', 'form_field_assignments.form_field_id')
            ->join('credential_types', 'credential_types.id', '=', 'form_fields.credential_type_id')
            ->where('visits.site_id', $site->getKey())
            ->whereNotNull('visit_visitor.checked_in_at')
            ->whereNull('visit_visitor.checked_out_at')
            ->where('credential_types.is_active', true)
            ->distinct()
            ->get(['visit_visitor.visitor_id', 'form_fields.credential_type_id', 'visit_visitor.checked_in_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $visitors = Visitor::query()->whereIn('id', $rows->pluck('visitor_id')->unique())->get()->keyBy('id');
        $types = CredentialType::query()->whereIn('id', $rows->pluck('credential_type_id')->unique())->get()->keyBy('id');
        $credentials = VisitorCredential::query()->with('type')
            ->whereIn('visitor_id', $visitors->keys())
            ->get()
            ->keyBy(fn (VisitorCredential $c): string => $c->visitor_id.'|'.$c->credential_type_id);
        $people = [];

        foreach ($rows as $row) {
            $key = $row->visitor_id.'|'.$row->credential_type_id;

            if (isset($people[$key]) || ! $visitors->has($row->visitor_id)) {
                continue;
            }

            $credential = $credentials->get($key);

            // Conditional questions (e.g. only if working with children) leave no record when
            // not asked; those visitors are listed so staff can confirm.
            if ($credential !== null && ! $credential->needsAttention()) {
                continue;
            }

            $people[$key] = [
                'visitor' => $visitors->get($row->visitor_id),
                'type' => $types->get($row->credential_type_id),
                'credential' => $credential,
                'state' => $credential?->state() ?? 'missing',
                'checked_in_at' => Carbon::parse($row->checked_in_at),
            ];
        }

        return array_values($people);
    }

    /**
     * Import records (e.g. an HR export). Each row: first_name, last_name, email, phone,
     * number, expires_on. People are matched by email, then mobile; new people are added.
     *
     * @param  iterable<int, array<string, ?string>>  $rows
     * @return array{imported: int, created_people: int, skipped: list<string>}
     */
    public function import(CredentialType $type, iterable $rows, User $user): array
    {
        $imported = 0;
        $created = 0;
        $skipped = [];

        foreach ($rows as $line => $row) {
            $number = trim((string) ($row['number'] ?? ''));
            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $phone = trim((string) ($row['phone'] ?? ''));

            if ($number === '' || $first === '' || $last === '') {
                $skipped[] = __('Zeile :line: Name oder Nummer fehlt.', ['line' => $line]);

                continue;
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = __('Zeile :line: ungültige E-Mail-Adresse.', ['line' => $line]);

                continue;
            }

            $expires = null;

            if (filled($row['expires_on'] ?? null)) {
                $expires = self::parseDate((string) $row['expires_on']);

                if ($expires === null) {
                    $skipped[] = __('Zeile :line: Ablaufdatum nicht erkannt.', ['line' => $line]);

                    continue;
                }
            }

            $visitor = ($email !== '' ? $this->returning->find($email) : null)
                ?? ($phone !== '' ? $this->returning->find($phone) : null);

            if ($visitor === null) {
                $visitor = Visitor::query()->create([
                    'first_name' => Str::limit($first, 250, ''),
                    'name' => Str::limit($last, 250, ''),
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                ]);
                $created++;
            }

            $this->record($visitor, $type, $number, VisitorCredential::SOURCE_IMPORT, $expires, $user);
            $imported++;
        }

        $this->audit->record('credential.imported', context: [
            'credential_type_id' => $type->getKey(),
            'imported' => $imported,
            'created_people' => $created,
            'skipped' => count($skipped),
        ], actorUserId: $user->getKey());

        return ['imported' => $imported, 'created_people' => $created, 'skipped' => $skipped];
    }

    /**
     * Australian day-first dates (31/12/2027, 31-12-2027) or ISO (2027-12-31).
     */
    public static function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($date !== false && $date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}
