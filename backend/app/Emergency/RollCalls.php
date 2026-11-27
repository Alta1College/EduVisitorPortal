<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Audit\AuditLogger;
use App\Emergency\Broadcast\EmergencyBroadcast;
use App\Models\Site;
use App\Models\User;
use App\Reports\SendRollCallReport;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starting, running and ending roll calls. Every step is audited with who did it.
 */
class RollCalls
{
    public function __construct(
        private readonly OnSiteRegister $register,
        private readonly AuditLogger $audit,
        private readonly EmergencyBroadcast $broadcast,
    ) {}

    public function openFor(int $siteId): ?RollCall
    {
        return RollCall::query()->where('site_id', $siteId)->whereNull('ended_at')->latest('started_at')->first();
    }

    /**
     * Snapshot everyone on site now. If a roll call is already open at the site, that one is
     * returned instead (two wardens pressing at once must not split the list), except that a
     * real emergency started while a drill is open replaces the drill (see escalate()).
     *
     * A real emergency always messages everyone on the list (when emergency messages are
     * switched on); a drill only with $sendDrillMessages. Messages are queued after commit and
     * can never stop the roll call from starting.
     */
    public function start(Site $site, User $user, string $kind, bool $sendDrillMessages = false): RollCall
    {
        if (! array_key_exists($kind, RollCall::kinds())) {
            throw new InvalidArgumentException('Unknown roll call kind.');
        }

        return DB::transaction(function () use ($site, $user, $kind, $sendDrillMessages): RollCall {
            // Serialise starts per site.
            Site::query()->whereKey($site->getKey())->lockForUpdate()->first();

            if ($open = $this->openFor($site->getKey())) {
                return $kind === RollCall::KIND_EMERGENCY && $open->kind === RollCall::KIND_DRILL
                    ? $this->escalateLocked($open, $site, $user)
                    : $open;
            }

            return $this->create($site, $user, $kind, $sendDrillMessages);
        });
    }

    /**
     * A real emergency while a drill is still open: the drill ends and an emergency roll call
     * starts in its place, with a fresh list of everyone on site, the marks wardens already made
     * in the drill, and the emergency messages (exactly once). If the open roll call is already
     * an emergency (another warden was quicker), that one is returned.
     */
    public function escalate(RollCall $drill, User $user): RollCall
    {
        return DB::transaction(function () use ($drill, $user): RollCall {
            $site = Site::query()->whereKey($drill->site_id)->lockForUpdate()->firstOrFail();
            $open = $this->openFor($site->getKey());

            if ($open === null) {
                return $this->create($site, $user, RollCall::KIND_EMERGENCY, false);
            }

            return $open->kind === RollCall::KIND_DRILL ? $this->escalateLocked($open, $site, $user) : $open;
        });
    }

    /**
     * Call inside a transaction holding the site lock.
     */
    private function escalateLocked(RollCall $drill, Site $site, User $user): RollCall
    {
        $drill->forceFill(['ended_at' => now(), 'ended_by_user_id' => $user->getKey()])->save();
        $this->audit->record('roll_call.ended', subject: $drill, site: $site, context: $drill->counts(), actorUserId: $user->getKey());

        $rollCall = $this->create($site, $user, RollCall::KIND_EMERGENCY, false, $drill);
        $kept = 0;

        $entries = $rollCall->entries()->whereNotNull('visit_id')->get()
            ->keyBy(fn (RollCallEntry $entry): string => $entry->visit_id.'|'.$entry->visitor_id);

        foreach ($drill->entries()->orderBy('id')->get() as $old) {
            if ($old->source === RollCallEntry::SOURCE_MANUAL) {
                $copy = $rollCall->entries()->create($old->only(['source', 'first_name', 'last_name', 'company', 'status']));
                $copy->forceFill($old->only(['status_changed_at', 'status_changed_by_user_id']))->save();
                $kept++;

                continue;
            }

            $new = $entries->get($old->visit_id.'|'.$old->visitor_id);

            if ($new !== null && $old->status !== RollCallEntry::UNACCOUNTED) {
                $new->forceFill($old->only(['status', 'status_changed_at', 'status_changed_by_user_id']))->save();
                $kept++;
            }
        }

        // No drill report is sent: the emergency's report covers it, and the audit log keeps the drill.
        $this->audit->record('roll_call.escalated', subject: $drill, site: $site, context: [
            'emergency_roll_call_id' => $rollCall->getKey(),
            'marks_kept' => $kept,
        ], actorUserId: $user->getKey());

        return $rollCall;
    }

    /**
     * Call inside a transaction holding the site lock.
     */
    private function create(Site $site, User $user, string $kind, bool $sendDrillMessages, ?RollCall $escalatedFrom = null): RollCall
    {
        $rollCall = RollCall::query()->create([
            'site_id' => $site->getKey(),
            'kind' => $kind,
            'started_at' => now(),
            'started_by_user_id' => $user->getKey(),
        ]);

        $people = $this->register->people($site);

        foreach ($people as $person) {
            $this->addPerson($rollCall, $person, RollCallEntry::SOURCE_SNAPSHOT);
        }

        $messages = ($kind === RollCall::KIND_EMERGENCY || $sendDrillMessages) && $this->broadcast->request($rollCall);

        $context = ['kind' => $kind, 'people' => count($people), 'messages' => $messages];

        if ($escalatedFrom !== null) {
            $context['escalated_from'] = $escalatedFrom->getKey();
        }

        $this->audit->record('roll_call.started', subject: $rollCall, site: $site, context: $context, actorUserId: $user->getKey());

        return $rollCall;
    }

    /**
     * People signed in at the site since the roll call started (not on the list yet).
     *
     * @return list<array<string, mixed>>
     */
    public function lateArrivals(RollCall $rollCall): array
    {
        $listed = $rollCall->entries()->whereNotNull('visit_id')->get(['visit_id', 'visitor_id'])
            ->map(fn (RollCallEntry $e): string => $e->visit_id.'|'.$e->visitor_id)->all();

        return array_values(array_filter(
            $this->register->people($rollCall->site),
            fn (array $person): bool => ! in_array($person['visit_id'].'|'.$person['visitor_id'], $listed, true),
        ));
    }

    public function addLate(RollCall $rollCall, int $visitId, int $visitorId, User $user): ?RollCallEntry
    {
        $this->assertOpen($rollCall);
        $person = collect($this->lateArrivals($rollCall))->first(fn (array $p): bool => $p['visit_id'] === $visitId && $p['visitor_id'] === $visitorId);

        if ($person === null) {
            return null;
        }

        $entry = $this->addPerson($rollCall, $person, RollCallEntry::SOURCE_LATE);
        $this->audit->record('roll_call.person_added', subject: $rollCall, site: $rollCall->site_id, context: ['entry_id' => $entry->getKey(), 'source' => 'late', 'visitor_id' => $visitorId], actorUserId: $user->getKey());

        return $entry;
    }

    /**
     * Someone found on site who never signed in.
     */
    public function addManual(RollCall $rollCall, string $name, ?string $note, User $user): RollCallEntry
    {
        $this->assertOpen($rollCall);
        $name = trim($name);

        $entry = $rollCall->entries()->create([
            'source' => RollCallEntry::SOURCE_MANUAL,
            'first_name' => mb_substr($name, 0, 191),
            'company' => filled($note) ? mb_substr(trim((string) $note), 0, 191) : null,
            'status' => RollCallEntry::ACCOUNTED,
        ]);
        $entry->forceFill(['status_changed_at' => now(), 'status_changed_by_user_id' => $user->getKey()])->save();

        $this->audit->record('roll_call.person_added', subject: $rollCall, site: $rollCall->site_id, context: ['entry_id' => $entry->getKey(), 'source' => 'manual'], actorUserId: $user->getKey());

        return $entry;
    }

    public function mark(RollCallEntry $entry, string $status, User $user): void
    {
        $rollCall = $entry->rollCall;
        $this->assertOpen($rollCall);

        if (! in_array($status, RollCallEntry::STATUSES, true) || $entry->status === $status) {
            return;
        }

        $from = $entry->status;
        $entry->forceFill(['status' => $status, 'status_changed_at' => now(), 'status_changed_by_user_id' => $user->getKey()])->save();

        $this->audit->record('roll_call.marked', subject: $rollCall, site: $rollCall->site_id, context: [
            'entry_id' => $entry->getKey(),
            'visitor_id' => $entry->visitor_id,
            'from' => $from,
            'to' => $status,
        ], actorUserId: $user->getKey());
    }

    public function end(RollCall $rollCall, User $user): void
    {
        if (! $rollCall->isOpen()) {
            return;
        }

        $rollCall->forceFill(['ended_at' => now(), 'ended_by_user_id' => $user->getKey()])->save();
        $this->audit->record('roll_call.ended', subject: $rollCall, site: $rollCall->site_id, context: $rollCall->counts(), actorUserId: $user->getKey());

        // The record of the drill or emergency goes to the site's roll-call report recipients.
        SendRollCallReport::dispatch((int) $rollCall->getKey())->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function addPerson(RollCall $rollCall, array $person, string $source): RollCallEntry
    {
        return $rollCall->entries()->create([
            'visit_id' => $person['visit_id'],
            'visitor_id' => $person['visitor_id'],
            'source' => $source,
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'company' => $person['company'],
            'visitor_type' => $person['visitor_type'],
            'host_name' => $person['host_name'],
            'checked_in_at' => $person['checked_in_at'],
            'safety_notes' => $person['safety'] !== [] ? $person['safety'] : null,
        ]);
    }

    private function assertOpen(RollCall $rollCall): void
    {
        if (! $rollCall->isOpen()) {
            throw new InvalidArgumentException('This roll call has ended.');
        }
    }
}
