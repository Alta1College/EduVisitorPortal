<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Dashboard;

use App\Audit\AuditEvent;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\Sms\MessagePortGateway;
use App\Kiosk\Kiosk;
use App\Models\Site;
use App\Settings\SettingsRepository;
use App\Support\OperationalHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System health for administrators (docs/fork/admin-dashboard.md): kiosks, badge printing,
 * failed notifications, Entra host sync, queue worker and scheduler, emergency SMS. Every item
 * has a status (ok, warning, problem, off) and only counts, times and names of devices: never
 * secrets, tokens, phone numbers, e-mail addresses or error messages. Cached for 60 seconds.
 */
class SystemHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const PROBLEM = 'problem';

    public const OFF = 'off';

    /** Seconds. */
    public const TTL = 60;

    /** A kiosk not seen for this long during its opening hours counts as offline. */
    public const KIOSK_OFFLINE_MINUTES = 15;

    public const FAILURE_WINDOW_DAYS = 7;

    /** Entra sync older than this is flagged (it normally runs every hour). */
    public const ENTRA_STALE_HOURS = 24;

    public const DEFAULT_KIOSK_HOURS = '07:00-18:00';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OperationalHeartbeat $heartbeat,
    ) {}

    /**
     * @return array{kiosks: array<string, mixed>, printing: array<string, mixed>, notifications: array<string, mixed>, entra: array<string, mixed>, queue: array<string, mixed>, sms: array<string, mixed>}
     */
    public function report(DashboardScope $scope): array
    {
        return Cache::remember('admin-dashboard:health:'.sha1($scope->key()), self::TTL, fn (): array => [
            'kiosks' => $this->kiosks($scope),
            'printing' => $this->printing($scope),
            'notifications' => $this->notifications(),
            'entra' => $this->entra(),
            'queue' => $this->queue(),
            'sms' => $this->sms(),
        ]);
    }

    /**
     * Enrolled, active kiosks at the sites in scope with their last contact. The kiosk screen
     * reloads every five minutes and KioskDevices::fromRequest() stores last_seen_at at most every
     * five minutes, so a working kiosk is never more than about ten minutes behind.
     *
     * @return array{status: string, total: int, offline: int, devices: list<array{name: string, site: string, last_seen_at: ?string, offline: bool, in_hours: bool}>}
     */
    public function kiosks(DashboardScope $scope): array
    {
        $kiosks = Kiosk::query()->active()
            ->whereNotNull('enrolled_at')
            ->when($scope->siteIds !== null, fn ($q) => $q->whereIn('site_id', $scope->siteIds))
            ->with('site')
            ->orderBy('name')
            ->get();

        $cutoff = now()->subMinutes(self::KIOSK_OFFLINE_MINUTES);
        $inHours = [];
        $devices = [];
        $offline = 0;

        foreach ($kiosks as $kiosk) {
            $site = $kiosk->site;
            $open = $inHours[$site->getKey()] ??= $this->withinKioskHours($site);
            $isOffline = $kiosk->last_seen_at === null || $kiosk->last_seen_at->lt($cutoff);

            if ($isOffline && $open) {
                $offline++;
            }

            $devices[] = [
                'name' => (string) $kiosk->name,
                'site' => (string) $site->name,
                'last_seen_at' => $kiosk->last_seen_at?->toIso8601String(),
                'offline' => $isOffline,
                'in_hours' => $open,
            ];
        }

        return [
            'status' => match (true) {
                $kiosks->isEmpty() => self::OFF,
                $offline > 0 => self::PROBLEM,
                default => self::OK,
            },
            'total' => $kiosks->count(),
            'offline' => $offline,
            'devices' => $devices,
        ];
    }

    /**
     * Whether it is now within the site's kiosk hours (setting
     * organisation.visitor_experience.kiosk_hours, "HH:MM-HH:MM" Monday to Friday in the site's
     * timezone, or "off").
     */
    public function withinKioskHours(Site $site): bool
    {
        $value = trim((string) ($this->settings->forSite($site)->get('organisation.visitor_experience.kiosk_hours') ?: self::DEFAULT_KIOSK_HOURS));

        if (preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        $now = now((string) ($site->timezone ?: config('app.timezone')));

        if ($now->isWeekend()) {
            return false;
        }

        $minutes = $now->hour * 60 + $now->minute;

        return $minutes >= (int) $m[1] * 60 + (int) $m[2] && $minutes < (int) $m[3] * 60 + (int) $m[4];
    }

    /**
     * Failed badge prints (audit "badge.print_failed", written by BadgePrinting::recordFailure)
     * in the last seven days, and enabled printers whose last attempt failed.
     *
     * @return array{status: string, failed: int, failing_printers: int, printers: int}
     */
    public function printing(DashboardScope $scope): array
    {
        $failed = $scope->apply(AuditEvent::query(), 'site_id')
            ->where('action', 'badge.print_failed')
            ->where('occurred_at', '>=', now()->subDays(self::FAILURE_WINDOW_DAYS))
            ->count();

        $printers = $scope->apply(DB::table('badge_printers'), 'site_id')
            ->where('is_enabled', true)
            ->selectRaw('count(*) as total, sum(case when last_failed_at is not null and (last_printed_at is null or last_failed_at > last_printed_at) then 1 else 0 end) as failing')
            ->first();

        $failing = (int) ($printers->failing ?? 0);

        return [
            'status' => match (true) {
                $failing > 0 => self::PROBLEM,
                $failed > 0 => self::WARNING,
                (int) ($printers->total ?? 0) === 0 => self::OFF,
                default => self::OK,
            },
            'failed' => $failed,
            'failing_printers' => $failing,
            'printers' => (int) ($printers->total ?? 0),
        ];
    }

    /**
     * Queued e-mail and Teams notifications (and emergency e-mail/SMS jobs) that ended in
     * failed_jobs in the last seven days. Teams messages Microsoft refuses outright are only
     * logged, so they are not counted here.
     *
     * @return array{status: string, failed: int, other_failed: int}
     */
    public function notifications(): array
    {
        $rows = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(self::FAILURE_WINDOW_DAYS))
            ->selectRaw("json_unquote(json_extract(payload, '$.displayName')) as job, count(*) as total")
            ->groupBy('job')
            ->get();

        $failed = 0;
        $other = 0;

        foreach ($rows as $row) {
            if (self::isNotificationJob((string) $row->job)) {
                $failed += (int) $row->total;
            } else {
                $other += (int) $row->total;
            }
        }

        return [
            'status' => $failed > 0 ? self::WARNING : self::OK,
            'failed' => $failed,
            'other_failed' => $other,
        ];
    }

    public static function isNotificationJob(string $displayName): bool
    {
        return Str::startsWith($displayName, [
            'App\\Notifications\\',
            'App\\Mail\\',
            'App\\Emergency\\Broadcast\\',
            'App\\Teams\\',
            'Illuminate\\Notifications\\',
            'Illuminate\\Mail\\',
        ]);
    }

    /**
     * Last Entra host sync from the audit trail (host_directory.synced / sync_failed, written by
     * EntraHostSync::run). Only the result and counts are shown, never the error text.
     *
     * @return array{status: string, enabled: bool, last_at: ?string, succeeded: ?bool, counts: array<string, int>}
     */
    public function entra(): array
    {
        $enabled = (bool) config('host_directory.entra.enabled');

        $last = AuditEvent::query()
            ->whereIn('action', ['host_directory.synced', 'host_directory.sync_failed'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $succeeded = $last === null ? null : $last->action === 'host_directory.synced';
        $counts = $succeeded === true
            ? array_map('intval', array_merge(['created' => 0, 'linked' => 0, 'updated' => 0, 'deactivated' => 0], array_intersect_key((array) $last->context, array_flip(['created', 'linked', 'updated', 'deactivated']))))
            : [];

        return [
            'status' => match (true) {
                ! $enabled => self::OFF,
                $last === null => self::WARNING,
                ! $succeeded => self::PROBLEM,
                $last->occurred_at->lt(now()->subHours(self::ENTRA_STALE_HOURS)) => self::WARNING,
                default => self::OK,
            },
            'enabled' => $enabled,
            'last_at' => $last?->occurred_at->toIso8601String(),
            'succeeded' => $succeeded,
            'counts' => $counts,
        ];
    }

    /**
     * Queue worker and scheduler heartbeats (OperationalHeartbeat, as visitorportal:health).
     *
     * @return array{status: string, queue: bool, scheduler: bool}
     */
    public function queue(): array
    {
        $queue = $this->heartbeat->queueIsFresh();
        $scheduler = $this->heartbeat->schedulerIsFresh();

        return [
            'status' => $queue && $scheduler ? self::OK : self::PROBLEM,
            'queue' => $queue,
            'scheduler' => $scheduler,
        ];
    }

    /**
     * Emergency SMS through MessagePort: switched on, configured (without showing any value),
     * webhook secret present, and when the last delivery receipt arrived (an SMS row the
     * MessagePort webhook moved to sent or delivered).
     *
     * @return array{status: string, enabled: bool, configured: bool, webhook: bool, last_receipt_at: ?string}
     */
    public function sms(): array
    {
        $enabled = (bool) config('emergency.sms_enabled');
        $configured = MessagePortGateway::configured();
        $webhook = filled(config('messageport.webhook_secret'));

        $lastReceipt = EmergencyNotification::query()
            ->where('channel', EmergencyNotification::CHANNEL_SMS)
            ->whereIn('status', [EmergencyNotification::SENT, EmergencyNotification::DELIVERED])
            ->max('updated_at');

        return [
            'status' => match (true) {
                ! $enabled => self::OFF,
                ! $configured => self::PROBLEM,
                ! $webhook => self::WARNING,
                default => self::OK,
            },
            'enabled' => $enabled,
            'configured' => $configured,
            'webhook' => $webhook,
            'last_receipt_at' => $lastReceipt !== null ? Carbon::parse($lastReceipt)->toIso8601String() : null,
        ];
    }
}
