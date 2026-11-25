<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Credentials\VisitorCredential;
use App\Emergency\AutoSignOut;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Models\Site;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\Policy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the emailed reports (docs/fork/admin-dashboard.md, "Scheduled reports"). The weekly
 * summary holds counts only; the monthly compliance list and the roll-call record name people,
 * because those records need names.
 */
class ReportData
{
    /** Longest attendance counted as visit length (longer ones were not signed out). */
    public const MAX_VISIT_SECONDS = 86400;

    /** Most names listed in the monthly credential list. */
    public const CREDENTIAL_LIST_LIMIT = 500;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Last full Monday-to-Sunday week before $now, in the timezone.
     *
     * @return array{0: Carbon, 1: Carbon} local start and end (end exclusive)
     */
    public static function lastWeek(string $timezone, ?Carbon $now = null): array
    {
        $end = ($now ?? now())->copy()->setTimezone($timezone)->startOfWeek(Carbon::MONDAY);

        return [$end->copy()->subWeek(), $end];
    }

    /**
     * Last full calendar month before $now, in the timezone.
     *
     * @return array{0: Carbon, 1: Carbon} local start and end (end exclusive)
     */
    public static function lastMonth(string $timezone, ?Carbon $now = null): array
    {
        $end = ($now ?? now())->copy()->setTimezone($timezone)->startOfMonth();

        return [$end->copy()->subMonthNoOverflow(), $end];
    }

    /**
     * Weekly summary: arrivals by site, type and day, busiest hours, visit length, missed
     * sign-outs, credential issues among people who came, roll calls and top companies.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    public function weekly(Collection $sites, Carbon $from, Carbon $until, string $timezone): array
    {
        $siteIds = $sites->map(fn (Site $site): int => (int) $site->getKey())->all();
        $names = $sites->pluck('name', 'id');

        $rows = DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->leftJoin('visitor_types', 'visitor_types.id', '=', 'visit_visitor.visitor_type_id')
            ->leftJoin('visitors', 'visitors.id', '=', 'visit_visitor.visitor_id')
            ->whereIn('visits.site_id', $siteIds)
            ->where('visit_visitor.checked_in_at', '>=', $this->stored($from))
            ->where('visit_visitor.checked_in_at', '<', $this->stored($until))
            ->select([
                'visits.site_id', 'visit_visitor.visitor_id', 'visit_visitor.checked_in_at', 'visit_visitor.checked_out_at',
                'visit_visitor.checked_out_via', 'visitor_types.name as type_name', 'visitors.company',
            ])
            ->get();

        $bySite = [];
        $byType = [];
        $byDay = [];
        $hours = [];
        $companies = [];
        $csv = [];
        $lengths = [];
        $autoSignOuts = 0;

        foreach ($rows as $row) {
            $local = Carbon::parse((string) $row->checked_in_at, (string) config('app.timezone'))->setTimezone($timezone);
            $site = (string) ($names[$row->site_id] ?? '');
            $type = (string) ($row->type_name ?? '') !== '' ? (string) $row->type_name : __('Ohne Besuchertyp');
            $day = $local->toDateString();

            $bySite[$site] = ($bySite[$site] ?? 0) + 1;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
            $slot = $local->isoWeekday().'|'.$local->hour;
            $hours[$slot] = ($hours[$slot] ?? 0) + 1;
            $csv[$day.'|'.$site.'|'.$type] = ($csv[$day.'|'.$site.'|'.$type] ?? 0) + 1;

            $company = trim((string) ($row->company ?? ''));

            if ($company !== '') {
                $key = mb_strtolower($company);
                $companies[$key] ??= ['company' => $company, 'visits' => 0];
                $companies[$key]['visits']++;
            }

            if ($row->checked_out_via === AutoSignOut::CHANNEL) {
                $autoSignOuts++;
            } elseif ($row->checked_out_at !== null) {
                $seconds = Carbon::parse((string) $row->checked_out_at)->getTimestamp() - Carbon::parse((string) $row->checked_in_at)->getTimestamp();

                if ($seconds >= 0 && $seconds <= self::MAX_VISIT_SECONDS) {
                    $lengths[] = $seconds;
                }
            }
        }

        arsort($bySite);
        arsort($byType);
        arsort($hours);
        uasort($companies, fn (array $a, array $b): int => [$b['visits'], $a['company']] <=> [$a['visits'], $b['company']]);

        $days = [];

        for ($day = $from->copy(); $day->lt($until); $day->addDay()) {
            $days[$day->toDateString()] = $byDay[$day->toDateString()] ?? 0;
        }

        $busiest = [];

        foreach (array_slice($hours, 0, 3, true) as $slot => $total) {
            [$weekday, $hour] = array_map('intval', explode('|', (string) $slot));
            $busiest[] = ['weekday' => $weekday, 'hour' => $hour, 'arrivals' => $total];
        }

        $visitorIds = $rows->pluck('visitor_id')->unique()->values();

        ksort($csv);

        return [
            'arrivals' => $rows->count(),
            'visitors' => $visitorIds->count(),
            'by_site' => $bySite,
            'by_type' => $byType,
            'by_day' => $days,
            'busiest' => $busiest,
            'avg_visit_seconds' => $lengths === [] ? null : (int) round(array_sum($lengths) / count($lengths)),
            'auto_sign_outs' => $autoSignOuts,
            'still_signed_in' => $this->stillSignedIn($siteIds, $until),
            'credential_issues' => $this->credentialIssues($visitorIds->all()),
            'roll_calls' => $this->rollCalls($sites, $from, $until, $timezone),
            'top_companies' => array_values(array_slice($companies, 0, 5)),
            'csv' => array_map(function (string $key, int $total): array {
                [$day, $site, $type] = explode('|', $key, 3);

                return [$day, $site, $type, $total];
            }, array_keys($csv), array_values($csv)),
        ];
    }

    /**
     * Monthly compliance: credential states and the people whose credential needs action, roll
     * calls held, the last drill per site, policy versions and data-retention runs.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    public function monthly(Collection $sites, Carbon $from, Carbon $until, string $timezone): array
    {
        $siteIds = $sites->map(fn (Site $site): int => (int) $site->getKey())->all();
        $today = now($timezone)->startOfDay();

        $credentials = VisitorCredential::query()->with(['visitor', 'type'])
            ->whereHas('type', fn ($q) => $q->where('is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('visit_visitor')
                ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
                ->whereColumn('visit_visitor.visitor_id', 'visitor_credentials.visitor_id')
                ->whereIn('visits.site_id', $siteIds))
            ->get();

        $states = array_fill_keys([
            VisitorCredential::VALID, VisitorCredential::EXPIRING, VisitorCredential::RECHECK_DUE,
            VisitorCredential::UNCHECKED, VisitorCredential::EXPIRED, VisitorCredential::INVALID,
        ], 0);
        $order = [VisitorCredential::INVALID => 0, VisitorCredential::EXPIRED => 1, VisitorCredential::UNCHECKED => 2, VisitorCredential::RECHECK_DUE => 3, VisitorCredential::EXPIRING => 4];
        $list = [];
        $checked = 0;
        $start = $this->stored($from);
        $end = $this->stored($until);

        foreach ($credentials as $credential) {
            $state = $credential->state($today);
            $states[$state] = ($states[$state] ?? 0) + 1;

            if ($credential->checked_at !== null && $credential->checked_at->gte($start) && $credential->checked_at->lt($end)) {
                $checked++;
            }

            if ($state !== VisitorCredential::VALID) {
                $list[] = [
                    'name' => trim(($credential->visitor->first_name ?? '').' '.($credential->visitor->name ?? '')),
                    'company' => (string) ($credential->visitor->company ?? ''),
                    'type' => (string) ($credential->type->name ?? ''),
                    'state' => $state,
                    'expires_on' => $credential->expires_on?->toDateString(),
                    'checked_at' => $credential->checked_at?->copy()->setTimezone($timezone)->toDateString(),
                    'order' => $order[$state] ?? 9,
                ];
            }
        }

        usort($list, fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        $policies = Policy::query()->where('is_active', true)->with('currentVersion')->orderBy('name')->get()
            ->map(fn (Policy $policy): array => [
                'name' => (string) $policy->name,
                'version' => $policy->currentVersion?->version,
                'published_at' => $policy->currentVersion?->published_at?->copy()->setTimezone($timezone)->toDateString(),
                'new_this_month' => $policy->currentVersion?->published_at !== null
                    && $policy->currentVersion->published_at->gte($start) && $policy->currentVersion->published_at->lt($end),
            ])->all();

        $retention = DB::table('audit_events')
            ->where('action', 'like', 'retention.%')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->groupBy('action')
            ->selectRaw('action, count(*) as runs')
            ->pluck('runs', 'action')
            ->map(fn ($runs): int => (int) $runs)
            ->all();

        return [
            'credential_states' => $states,
            'credentials_checked' => $checked,
            'credential_list' => array_map(fn (array $row): array => array_diff_key($row, ['order' => true]), array_slice($list, 0, self::CREDENTIAL_LIST_LIMIT)),
            'credential_list_total' => count($list),
            'roll_calls' => $this->rollCalls($sites, $from, $until, $timezone),
            'drills' => $this->lastDrills($sites, $timezone),
            'policies' => $policies,
            'retention' => $retention,
        ];
    }

    /**
     * The record of one roll call (drill or emergency): who was on the list and what happened.
     * Safety notes (e.g. medical) stay on the roll-call screen and are not part of the record.
     *
     * @return array<string, mixed>
     */
    public function rollCall(RollCall $rollCall): array
    {
        $rollCall->loadMissing(['site', 'startedBy', 'endedBy']);
        $timezone = (string) ($rollCall->site->timezone ?: config('app.timezone'));

        $entries = $rollCall->entries()
            ->orderByRaw("CASE status WHEN 'unaccounted' THEN 0 WHEN 'accounted' THEN 1 ELSE 2 END")
            ->orderBy('first_name')
            ->get()
            ->map(fn (RollCallEntry $entry): array => [
                'name' => $entry->fullName(),
                'type' => (string) ($entry->visitor_type ?? ''),
                'company' => (string) ($entry->company ?? ''),
                'host' => (string) ($entry->host_name ?? ''),
                'source' => (string) $entry->source,
                'status' => (string) $entry->status,
                'status_changed_at' => $entry->status_changed_at?->copy()->setTimezone($timezone)->format('H:i'),
            ])->all();

        return [
            'site' => (string) $rollCall->site->name,
            'timezone' => $timezone,
            'kind' => (string) $rollCall->kind,
            'started_at' => $rollCall->started_at->copy()->setTimezone($timezone),
            'ended_at' => $rollCall->ended_at?->copy()->setTimezone($timezone),
            'started_by' => $rollCall->startedBy?->full_name,
            'ended_by' => $rollCall->endedBy?->full_name,
            'counts' => $rollCall->counts(),
            'messages' => $this->messageCounts([$rollCall->getKey()])[$rollCall->getKey()] ?? [],
            'entries' => $entries,
        ];
    }

    /**
     * Roll calls started in the period at these sites.
     *
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function rollCalls(Collection $sites, Carbon $from, Carbon $until, string $timezone): array
    {
        $rollCalls = RollCall::query()->with('site')
            ->whereIn('site_id', $sites->map(fn (Site $site): int => (int) $site->getKey())->all())
            ->where('started_at', '>=', $this->stored($from))
            ->where('started_at', '<', $this->stored($until))
            ->orderBy('started_at')
            ->get();

        if ($rollCalls->isEmpty()) {
            return [];
        }

        $ids = $rollCalls->modelKeys();
        $counts = RollCallEntry::query()->whereIn('roll_call_id', $ids)
            ->groupBy('roll_call_id', 'status')
            ->selectRaw('roll_call_id, status, count(*) as total')
            ->get()
            ->groupBy('roll_call_id');
        $messages = $this->messageCounts($ids);

        return $rollCalls->map(function (RollCall $rollCall) use ($counts, $messages, $timezone): array {
            $status = ($counts->get($rollCall->getKey()) ?? collect())->pluck('total', 'status');

            return [
                'site' => (string) $rollCall->site->name,
                'kind' => (string) $rollCall->kind,
                'started_at' => $rollCall->started_at->copy()->setTimezone($timezone),
                'ended_at' => $rollCall->ended_at?->copy()->setTimezone($timezone),
                'people' => (int) $status->sum(),
                'accounted' => (int) ($status[RollCallEntry::ACCOUNTED] ?? 0),
                'left_site' => (int) ($status[RollCallEntry::LEFT_SITE] ?? 0),
                'unaccounted' => (int) ($status[RollCallEntry::UNACCOUNTED] ?? 0),
                'messages' => $messages[$rollCall->getKey()] ?? [],
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $rollCallIds
     * @return array<int, array{sms_delivered: int, sms_failed: int, sms_pending: int, no_mobile: int, email_sent: int, email_failed: int}>
     */
    private function messageCounts(array $rollCallIds): array
    {
        $result = [];

        $rows = EmergencyNotification::query()->whereIn('roll_call_id', $rollCallIds)
            ->groupBy('roll_call_id', 'channel', 'status')
            ->selectRaw('roll_call_id, channel, status, count(*) as total')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->roll_call_id;
            $result[$id] ??= ['sms_delivered' => 0, 'sms_failed' => 0, 'sms_pending' => 0, 'no_mobile' => 0, 'email_sent' => 0, 'email_failed' => 0];
            $total = (int) $row->total;

            if ($row->status === EmergencyNotification::NO_MOBILE) {
                $result[$id]['no_mobile'] += $total;
            } elseif ($row->channel === EmergencyNotification::CHANNEL_SMS) {
                $key = match ($row->status) {
                    EmergencyNotification::DELIVERED => 'sms_delivered',
                    EmergencyNotification::FAILED => 'sms_failed',
                    default => 'sms_pending',
                };
                $result[$id][$key] += $total;
            } else {
                $result[$id][$row->status === EmergencyNotification::FAILED ? 'email_failed' : 'email_sent'] += $total;
            }
        }

        return $result;
    }

    /**
     * Last drill per site and whether it is older than the site's warning threshold.
     *
     * @param  Collection<int, Site>  $sites
     * @return list<array{site: string, last_drill_at: ?Carbon, days_since: ?int, warning_days: int, overdue: bool}>
     */
    private function lastDrills(Collection $sites, string $timezone): array
    {
        $last = RollCall::query()->whereIn('site_id', $sites->map(fn (Site $site): int => (int) $site->getKey())->all())
            ->where('kind', RollCall::KIND_DRILL)
            ->groupBy('site_id')
            ->selectRaw('site_id, max(started_at) as last_started_at')
            ->pluck('last_started_at', 'site_id');
        $default = (int) config('organisation.emergency.drill_warning_days', 90);

        return $sites->map(function (Site $site) use ($last, $default, $timezone): array {
            $at = $last->get($site->getKey());
            $at = $at !== null ? Carbon::parse((string) $at, (string) config('app.timezone'))->setTimezone($timezone) : null;
            $warning = max(1, (int) ($this->settings->forSite($site)->get('organisation.emergency.drill_warning_days') ?? $default));
            $days = $at !== null ? (int) $at->copy()->startOfDay()->diffInDays(now($timezone)->startOfDay()) : null;

            return [
                'site' => (string) $site->name,
                'last_drill_at' => $at,
                'days_since' => $days,
                'warning_days' => $warning,
                'overdue' => $days === null || $days > $warning,
            ];
        })->values()->all();
    }

    /**
     * People signed in at these sites before the end of the period and still not signed out.
     *
     * @param  list<int>  $siteIds
     */
    private function stillSignedIn(array $siteIds, Carbon $until): int
    {
        return DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->whereIn('visits.site_id', $siteIds)
            ->whereNotNull('visit_visitor.checked_in_at')
            ->where('visit_visitor.checked_in_at', '<', $this->stored($until))
            ->whereNull('visit_visitor.checked_out_at')
            ->count();
    }

    /**
     * Credentials needing staff action among the people who came.
     *
     * @param  list<int>  $visitorIds
     */
    private function credentialIssues(array $visitorIds): int
    {
        if ($visitorIds === []) {
            return 0;
        }

        return VisitorCredential::query()->with('type')
            ->whereIn('visitor_id', $visitorIds)
            ->whereHas('type', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (VisitorCredential $credential): bool => $credential->needsAttention())
            ->count();
    }

    /**
     * A local time as stored in the database (application timezone).
     */
    private function stored(Carbon $local): Carbon
    {
        return $local->copy()->setTimezone((string) config('app.timezone'));
    }
}
