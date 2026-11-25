<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Broadcast;

use App\Audit\AuditLogger;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Emergency\Sms\MessagePortGateway;
use App\Emergency\Sms\PhoneNumber;
use App\Emergency\Sms\SmsGateway;
use App\Emergency\Sms\SmsMessage;
use App\Emergency\Sms\SmsResult;
use App\Emergency\Sms\SmsText;
use App\Models\Visitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emergency SMS and e-mail to everyone on a roll call's snapshot (docs/fork/emergency-broadcast.md).
 * Starting a roll call only records the request and queues SendEmergencyBroadcast; nothing here
 * may slow down or break the start. Outcomes are stored per person and shown on the board.
 */
class EmergencyBroadcast
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly AuditLogger $audit,
    ) {}

    public static function smsEnabled(): bool
    {
        return (bool) config('emergency.sms_enabled');
    }

    public static function emailEnabled(): bool
    {
        return (bool) config('emergency.email_enabled');
    }

    public static function available(): bool
    {
        return self::smsEnabled() || self::emailEnabled();
    }

    /**
     * Called while the roll call is being created. Queues the messages once the roll call is
     * committed. Never throws.
     */
    public function request(RollCall $rollCall): bool
    {
        if (! self::available()) {
            return false;
        }

        try {
            $rollCall->forceFill(['broadcast_at' => now()])->save();
            SendEmergencyBroadcast::dispatch($rollCall->getKey())->afterCommit();

            return true;
        } catch (Throwable $exception) {
            report($exception);
            Log::error('emergency_broadcast_not_queued', ['roll_call_id' => $rollCall->getKey()]);

            return false;
        }
    }

    /**
     * Runs in the queue: one row per person and channel, one SMS request, one e-mail job each.
     *
     * Safe to run again (the job retries): rows already created are reused, and only messages
     * still pending are sent, so a crash part-way never leaves the rest of the list without a
     * message. A crash between the provider accepting the SMS and the row being updated can
     * repeat that text on the retry: in an emergency a duplicate is better than nothing.
     */
    public function send(int $rollCallId): void
    {
        $lock = Cache::lock('emergency-broadcast:'.$rollCallId, 120);

        if (! $lock->get()) {
            return; // another worker is sending this roll call's messages right now
        }

        try {
            $this->sendLocked($rollCallId);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(int $rollCallId): void
    {
        $rollCall = RollCall::query()->with('site')->find($rollCallId);

        if ($rollCall === null || $rollCall->broadcast_at === null) {
            return; // gone, or no messages requested
        }

        $drill = $rollCall->kind === RollCall::KIND_DRILL;
        $locale = (string) config('app.locale');
        $siteName = (string) $rollCall->site->name;

        $entries = $rollCall->entries()
            ->where('source', RollCallEntry::SOURCE_SNAPSHOT)
            ->whereNotNull('visitor_id')
            ->orderBy('id')
            ->get(['id', 'visitor_id']);
        $visitors = Visitor::query()->whereIn('id', $entries->pluck('visitor_id')->unique())->get(['id', 'email', 'phone'])->keyBy('id');

        $smsRows = self::smsEnabled() ? $this->createSmsRows($rollCall, $entries, $visitors) : collect();
        $emailRows = self::emailEnabled() ? $this->createEmailRows($rollCall, $entries, $visitors) : collect();

        $sms = $this->sendSms($rollCall, $smsRows, $visitors, $this->smsText($drill, $siteName, $locale));
        $pendingEmails = $emailRows->where('status', EmergencyNotification::PENDING);

        foreach ($pendingEmails as $row) {
            SendEmergencyEmail::dispatch($row->getKey());
        }

        if ($sms['accepted'] + $sms['failed'] === 0 && $pendingEmails->isEmpty()
            && $smsRows->every(fn (EmergencyNotification $row): bool => ! $row->wasRecentlyCreated)) {
            return; // a repeat run with nothing left to send
        }

        $this->audit->record('roll_call.broadcast', subject: $rollCall, site: $rollCall->site_id, context: [
            'kind' => $rollCall->kind,
            'sms_accepted' => $sms['accepted'],
            'sms_failed' => $sms['failed'],
            'sms_no_mobile' => $smsRows->where('status', EmergencyNotification::NO_MOBILE)->count(),
            'emails' => $pendingEmails->count(),
            'dry_run' => (bool) config('messageport.dry_run'),
        ], actorUserId: $rollCall->started_by_user_id);

        Log::info('emergency_broadcast_sent', ['roll_call_id' => $rollCallId, 'drill' => $drill, ...$sms, 'emails' => $pendingEmails->count()]);
    }

    public function smsText(bool $drill, string $siteName, string $locale): string
    {
        return SmsText::withSite(fn (string $site): string => $drill
            ? __('ÜBUNG – kein echter Notfall. :site: Bitte das Gelände jetzt verlassen und der Notfallbeschilderung zum Sammelplatz folgen.', ['site' => $site], $locale)
            : __('NOTFALL in :site: Bitte das Gelände jetzt verlassen und der Notfallbeschilderung zum Sammelplatz folgen. Erst nach Entwarnung zurückkehren.', ['site' => $site], $locale), $siteName);
    }

    /**
     * Configuration summary for the admin settings (no secrets).
     *
     * @return list<string>
     */
    public static function statusLines(): array
    {
        $missing = MessagePortGateway::missingSettings();
        $lines = [];

        if (! self::smsEnabled()) {
            $lines[] = __('SMS: ausgeschaltet (EMERGENCY_SMS_ENABLED).');
        } elseif ($missing !== []) {
            $lines[] = __('SMS: eingeschaltet, aber nicht vollständig eingerichtet (es fehlt: :missing).', ['missing' => implode(', ', $missing)]);
        } else {
            $lines[] = __('SMS: eingeschaltet über MessagePort, Absender :sender.', ['sender' => (string) config('messageport.sender_id')]);

            if (blank(config('messageport.webhook_secret'))) {
                $lines[] = __('Zustellberichte: nicht eingerichtet (MESSAGEPORT_WEBHOOK_SECRET).');
            }

            if (config('messageport.dry_run')) {
                $lines[] = __('Testmodus: SMS werden geprüft, aber nicht gesendet (MESSAGEPORT_DRY_RUN).');
            }
        }

        $lines[] = self::emailEnabled()
            ? __('E-Mail: eingeschaltet.')
            : __('E-Mail: ausgeschaltet (EMERGENCY_EMAIL_ENABLED).');

        return $lines;
    }

    /**
     * @param  Collection<int, RollCallEntry>  $entries
     * @param  Collection<int, Visitor>  $visitors
     * @return Collection<int, EmergencyNotification>
     */
    private function createSmsRows(RollCall $rollCall, Collection $entries, Collection $visitors): Collection
    {
        return $entries->map(function (RollCallEntry $entry) use ($rollCall, $visitors): EmergencyNotification {
            $number = PhoneNumber::toMobileE164($visitors[$entry->visitor_id]->phone ?? null);

            // Reused on a retry (unique per entry and channel).
            return EmergencyNotification::query()->firstOrCreate([
                'roll_call_entry_id' => $entry->getKey(),
                'channel' => EmergencyNotification::CHANNEL_SMS,
            ], [
                'roll_call_id' => $rollCall->getKey(),
                'visitor_id' => $entry->visitor_id,
                'to_masked' => $number !== null ? PhoneNumber::mask($number) : null,
                'reference' => $number !== null ? EmergencyNotification::smsReference($rollCall->getKey(), $entry->getKey()) : null,
                'status' => $number !== null ? EmergencyNotification::PENDING : EmergencyNotification::NO_MOBILE,
            ]);
        });
    }

    /**
     * @param  Collection<int, RollCallEntry>  $entries
     * @param  Collection<int, Visitor>  $visitors
     * @return Collection<int, EmergencyNotification>
     */
    private function createEmailRows(RollCall $rollCall, Collection $entries, Collection $visitors): Collection
    {
        return $entries
            ->filter(fn (RollCallEntry $entry): bool => filter_var((string) ($visitors[$entry->visitor_id]->email ?? ''), FILTER_VALIDATE_EMAIL) !== false)
            ->map(function (RollCallEntry $entry) use ($rollCall, $visitors): EmergencyNotification {
                $email = (string) $visitors[$entry->visitor_id]->email;

                return EmergencyNotification::query()->firstOrCreate([
                    'roll_call_entry_id' => $entry->getKey(),
                    'channel' => EmergencyNotification::CHANNEL_EMAIL,
                ], [
                    'roll_call_id' => $rollCall->getKey(),
                    'visitor_id' => $entry->visitor_id,
                    'to_masked' => mb_substr(mb_substr($email, 0, 1).'***'.mb_strstr($email, '@'), 0, 64),
                    'status' => EmergencyNotification::PENDING,
                ]);
            })->values();
    }

    /**
     * @param  Collection<int, EmergencyNotification>  $rows
     * @param  Collection<int, Visitor>  $visitors
     * @return array{accepted: int, failed: int}
     */
    private function sendSms(RollCall $rollCall, Collection $rows, Collection $visitors, string $body): array
    {
        $pending = $rows->where('status', EmergencyNotification::PENDING)->keyBy('reference');

        if ($pending->isEmpty()) {
            return ['accepted' => 0, 'failed' => 0];
        }

        $messages = $pending->map(fn (EmergencyNotification $row): SmsMessage => new SmsMessage(
            to: (string) PhoneNumber::toMobileE164($visitors[$row->visitor_id]->phone ?? null),
            body: $body,
            reference: (string) $row->reference,
            metadata: ['roll_call_id' => $rollCall->getKey(), 'kind' => $rollCall->kind],
        ))->values()->all();

        try {
            $results = $this->sms->send($messages);
        } catch (Throwable $exception) {
            report($exception);
            $results = [];
        }

        $counts = ['accepted' => 0, 'failed' => 0];

        foreach ($pending as $reference => $row) {
            $result = $results[$reference] ?? SmsResult::failed('ERROR');
            $row->forceFill($result->accepted
                ? ['status' => EmergencyNotification::ACCEPTED]
                : ['status' => EmergencyNotification::FAILED, 'error_code' => $result->errorCode])->save();
            $counts[$result->accepted ? 'accepted' : 'failed']++;
        }

        return $counts;
    }
}
