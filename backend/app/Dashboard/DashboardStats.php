<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Dashboard;

use App\Credentials\CredentialRegister;
use App\Credentials\VisitorCredential;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Enums\VisitStatusEnum;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\Policy;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the admin dashboard (docs/fork/admin-dashboard.md). Every method runs a few
 * aggregate queries for the sites in scope and caches the result briefly, so the page stays
 * cheap with many viewers and short polling intervals. Results hold numbers and dates only;
 * labels are translated when the tiles are drawn.
 */
class DashboardStats
{
    /** Seconds: what is happening now. */
    public const TTL_NOW = 60;

    /** Seconds: today, compliance and safety. */
    public const TTL_SLOW = 300;

    public const EXPIRY_WINDOW_DAYS = 30;

    public const POLICY_WINDOW_DAYS = 30;

    public function __construct(
        private readonly CredentialRegister $credentials,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return array{total: int, by_type: array<string, int>, earlier_days: int}
     */
    public function now(DashboardScope $scope): array
    {
        return $this->remember('now', $scope, self::TTL_NOW, function () use ($scope): array {
            $byType = $this->onSite($scope)
                ->leftJoin('visitor_types', 'visitor_types.id', '=', 'visit_visitor.visitor_type_id')
                ->groupBy('visitor_types.name')
                ->orderByDesc('total')
                ->selectRaw('visitor_types.name as type_name, count(*) as total')
                ->get();

            $earlier = $this->onSite($scope)->where('visit_visitor.checked_in_at', '<', $scope->today())->count();

            return [
                'total' => (int) $byType->sum('total'),
                'by_type' => $byType->mapWithKeys(fn (object $row): array => [(string) ($row->type_name ?? '') => (int) $row->total])->all(),
                'earlier_days' => $earlier,
            ];
        });
    }

    /**
     * People on site whose visitor type asks for a credential they have not got, or whose
     * credential needs attention (CredentialRegister::onSiteAttention, per site).
     */
    public function credentialsOnSite(DashboardScope $scope): int
    {
        return $this->remember('credentials-on-site', $scope, self::TTL_NOW, function () use ($scope): int {
            $people = [];

            foreach ($scope->sites() as $site) {
                foreach ($this->credentials->onSiteAttention($site) as $row) {
                    $people[$row['visitor']->getKey()] = true;
                }
            }

            return count($people);
        });
    }

    /**
     * @return array{arrivals: int, arrivals_last_week: int, expected: int, arrived: int, upcoming_visits: int, upcoming_visitors: int}
     */
    public function today(DashboardScope $scope): array
    {
        return $this->remember('today', $scope, self::TTL_SLOW, function () use ($scope): array {
            $start = $scope->today();
            $end = $start->copy()->addDay();
            $now = now()->setTimezone((string) config('app.timezone'));

            $arrivals = $this->attendances($scope)
                ->where('visit_visitor.checked_in_at', '>=', $start)
                ->where('visit_visitor.checked_in_at', '<', $end)
                ->count();

            // Same weekday last week, up to the same time of day, so the morning is not compared
            // with a whole day.
            $lastWeek = $this->attendances($scope)
                ->where('visit_visitor.checked_in_at', '>=', $start->copy()->subWeek())
                ->where('visit_visitor.checked_in_at', '<', $now->copy()->subWeek())
                ->count();

            $expected = $this->attendances($scope)
                ->where('visits.is_walk_in', false)
                ->whereIn('visits.status', [VisitStatusEnum::Planned->value, VisitStatusEnum::Completed->value])
                ->where('visits.scheduled_from', '>=', $start)
                ->where('visits.scheduled_from', '<', $end)
                ->selectRaw('count(*) as expected, sum(case when visit_visitor.checked_in_at is null then 0 else 1 end) as arrived')
                ->first();

            $upcoming = $scope->apply(DB::table('visits'), 'visits.site_id')
                ->leftJoin('visit_visitor', 'visit_visitor.visit_id', '=', 'visits.id')
                ->where('visits.is_walk_in', false)
                ->where('visits.status', VisitStatusEnum::Planned->value)
                ->where('visits.scheduled_from', '>=', $end)
                ->where('visits.scheduled_from', '<', $start->copy()->addDays(8))
                ->selectRaw('count(distinct visits.id) as visits, count(visit_visitor.visit_id) as visitors')
                ->first();

            return [
                'arrivals' => $arrivals,
                'arrivals_last_week' => $lastWeek,
                'expected' => (int) ($expected->expected ?? 0),
                'arrived' => (int) ($expected->arrived ?? 0),
                'upcoming_visits' => (int) ($upcoming->visits ?? 0),
                'upcoming_visitors' => (int) ($upcoming->visitors ?? 0),
            ];
        });
    }

    /**
     * Credential records of active types. With a site filter, only people who have visited
     * one of those sites count.
     *
     * @return array{unchecked: int, expiring: int, not_valid: int, policies_updated: int}
     */
    public function compliance(DashboardScope $scope): array
    {
        return $this->remember('compliance', $scope, self::TTL_SLOW, function () use ($scope): array {
            $today = now()->startOfDay()->toDateString();
            $soon = now()->startOfDay()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();

            $query = DB::table('visitor_credentials')
                ->join('credential_types', 'credential_types.id', '=', 'visitor_credentials.credential_type_id')
                ->where('credential_types.is_active', true);

            if ($scope->siteIds !== null) {
                $query->whereExists(fn (Builder $q) => $q->selectRaw('1')
                    ->from('visit_visitor')
                    ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
                    ->whereColumn('visit_visitor.visitor_id', 'visitor_credentials.visitor_id')
                    ->whereIn('visits.site_id', $scope->siteIds));
            }

            $counts = $query->selectRaw(
                'sum(case when visitor_credentials.status = ? and (visitor_credentials.expires_on is null or visitor_credentials.expires_on >= ?) then 1 else 0 end) as unchecked, '
                .'sum(case when visitor_credentials.status = ? and visitor_credentials.expires_on >= ? and visitor_credentials.expires_on <= ? then 1 else 0 end) as expiring, '
                .'sum(case when visitor_credentials.status = ? or visitor_credentials.expires_on < ? then 1 else 0 end) as not_valid',
                [VisitorCredential::UNCHECKED, $today, VisitorCredential::VALID, $today, $soon, VisitorCredential::INVALID, $today],
            )->first();

            $policies = Policy::query()->where('is_active', true)
                ->whereHas('currentVersion', fn ($q) => $q->where('published_at', '>=', now()->subDays(self::POLICY_WINDOW_DAYS)))
                ->count();

            return [
                'unchecked' => (int) ($counts->unchecked ?? 0),
                'expiring' => (int) ($counts->expiring ?? 0),
                'not_valid' => (int) ($counts->not_valid ?? 0),
                'policies_updated' => $policies,
            ];
        });
    }

    /**
     * Last roll call (drill or emergency) per site in scope, and whether the last drill is older
     * than the site's warning threshold.
     *
     * @return list<array{site_id: int, site_name: string, timezone: string, warning_days: int, last_drill_at: ?string, last: ?array{kind: string, started_at: string, open: bool, unaccounted: int, sms_delivered: int, sms_failed: int}}>
     */
    public function safety(DashboardScope $scope): array
    {
        return $this->remember('safety', $scope, self::TTL_SLOW, function () use ($scope): array {
            $sites = $scope->sites();

            if ($sites->isEmpty()) {
                return [];
            }

            $siteIds = $sites->modelKeys();

            $latestIds = RollCall::query()->whereIn('site_id', $siteIds)
                ->groupBy('site_id')
                ->selectRaw('max(id) as id')
                ->pluck('id');

            $latest = RollCall::query()->whereIn('id', $latestIds)->get()->keyBy('site_id');

            $lastDrills = RollCall::query()->whereIn('site_id', $siteIds)
                ->where('kind', RollCall::KIND_DRILL)
                ->groupBy('site_id')
                ->selectRaw('site_id, max(started_at) as last_started_at')
                ->pluck('last_started_at', 'site_id');

            $unaccounted = RollCallEntry::query()->whereIn('roll_call_id', $latestIds)
                ->where('status', RollCallEntry::UNACCOUNTED)
                ->groupBy('roll_call_id')
                ->selectRaw('roll_call_id, count(*) as total')
                ->pluck('total', 'roll_call_id');

            $sms = EmergencyNotification::query()->whereIn('roll_call_id', $latestIds)
                ->where('channel', EmergencyNotification::CHANNEL_SMS)
                ->whereIn('status', [EmergencyNotification::DELIVERED, EmergencyNotification::FAILED])
                ->groupBy('roll_call_id', 'status')
                ->selectRaw('roll_call_id, status, count(*) as total')
                ->get()
                ->groupBy('roll_call_id');

            $default = (int) config('organisation.emergency.drill_warning_days', 90);

            return $sites->map(function ($site) use ($latest, $lastDrills, $unaccounted, $sms, $default): array {
                /** @var RollCall|null $rollCall */
                $rollCall = $latest->get($site->getKey());
                $smsCounts = $rollCall !== null ? ($sms->get($rollCall->getKey()) ?? collect())->pluck('total', 'status') : collect();
                $drillAt = $lastDrills->get($site->getKey());

                return [
                    'site_id' => (int) $site->getKey(),
                    'site_name' => (string) $site->name,
                    'timezone' => (string) ($site->timezone ?: config('app.timezone')),
                    'warning_days' => max(1, (int) ($this->settings->forSite($site)->get('organisation.emergency.drill_warning_days') ?? $default)),
                    'last_drill_at' => $drillAt !== null ? Carbon::parse($drillAt)->toIso8601String() : null,
                    'last' => $rollCall === null ? null : [
                        'kind' => (string) $rollCall->kind,
                        'started_at' => $rollCall->started_at->toIso8601String(),
                        'open' => $rollCall->isOpen(),
                        'unaccounted' => (int) ($unaccounted[$rollCall->getKey()] ?? 0),
                        'sms_delivered' => (int) ($smsCounts[EmergencyNotification::DELIVERED] ?? 0),
                        'sms_failed' => (int) ($smsCounts[EmergencyNotification::FAILED] ?? 0),
                    ],
                ];
            })->values()->all();
        });
    }

    /**
     * Attendances at the sites in scope.
     */
    private function attendances(DashboardScope $scope): Builder
    {
        return $scope->apply(
            DB::table('visit_visitor')->join('visits', 'visits.id', '=', 'visit_visitor.visit_id'),
            'visits.site_id',
        );
    }

    /**
     * Signed in and not signed out, whatever the state of the visit (as the on-site register).
     */
    private function onSite(DashboardScope $scope): Builder
    {
        return $this->attendances($scope)
            ->whereNotNull('visit_visitor.checked_in_at')
            ->whereNull('visit_visitor.checked_out_at');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function remember(string $tile, DashboardScope $scope, int $seconds, Closure $callback): mixed
    {
        return Cache::remember('admin-dashboard:'.$tile.':'.sha1($scope->key()), $seconds, $callback);
    }
}
