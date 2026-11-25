<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Dashboard;

use App\Emergency\AutoSignOut;
use Closure;
use DateTime;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Trends for the admin dashboard (docs/fork/admin-dashboard.md): arrivals per day by visitor
 * type, busiest hours, returning visitors, average visit length and top companies over the last
 * 30 or 90 days. Aggregate queries only, cached for ten minutes per scope and period. Days and
 * hours are the scope's local time (the site's timezone with one site, else the installation's).
 */
class DashboardTrends
{
    /** Seconds. */
    public const TTL = 600;

    public const PERIODS = [30, 90];

    /** Visits longer than this are treated as forgotten sign-outs, not visit length. */
    public const MAX_VISIT_SECONDS = 86400;

    public const TOP_COMPANIES = 10;

    public static function period(mixed $days): int
    {
        return in_array((int) $days, self::PERIODS, true) ? (int) $days : self::PERIODS[0];
    }

    /**
     * Arrivals (sign-ins) per local day, one series per visitor type (most arrivals first).
     *
     * @return array{days: list<string>, series: list<array{type: ?string, counts: list<int>}>}
     */
    public function arrivalsPerDay(DashboardScope $scope, int $days): array
    {
        $days = self::period($days);

        return $this->remember('arrivals-per-day:'.$days, $scope, function () use ($scope, $days): array {
            [$from, $until] = $this->window($scope, $days);
            $totals = [];

            foreach ($this->segments($from, $until, $scope->timezone) as [$segmentFrom, $segmentUntil, $offset]) {
                $rows = $this->arrivals($scope, $segmentFrom, $segmentUntil)
                    ->selectRaw("date(date_add(visit_visitor.checked_in_at, interval {$offset} second)) as day, visit_visitor.visitor_type_id as type_id, count(*) as total")
                    ->groupBy('day', 'type_id')
                    ->get();

                foreach ($rows as $row) {
                    $type = $row->type_id === null ? 0 : (int) $row->type_id;
                    $day = Carbon::parse((string) $row->day)->toDateString();
                    $totals[$type][$day] = ($totals[$type][$day] ?? 0) + (int) $row->total;
                }
            }

            $firstDay = Carbon::parse($from)->setTimezone($scope->timezone)->startOfDay();
            $dayList = [];

            for ($i = 0; $i < $days; $i++) {
                $dayList[] = $firstDay->copy()->addDays($i)->toDateString();
            }

            $names = DB::table('visitor_types')->whereIn('id', array_filter(array_keys($totals)))->pluck('name', 'id');

            uasort($totals, fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

            $series = [];

            foreach ($totals as $type => $counts) {
                $series[] = [
                    'type' => $type === 0 ? null : (string) ($names[$type] ?? ''),
                    'counts' => array_map(fn (string $day): int => $counts[$day] ?? 0, $dayList),
                ];
            }

            return ['days' => $dayList, 'series' => $series];
        });
    }

    /**
     * Arrivals by local weekday (1 = Monday … 7 = Sunday) and hour (0–23) over the period.
     *
     * @return array<int, array<int, int>> weekday => hour => arrivals
     */
    public function busiestHours(DashboardScope $scope, int $days): array
    {
        $days = self::period($days);

        return $this->remember('busiest-hours:'.$days, $scope, function () use ($scope, $days): array {
            [$from, $until] = $this->window($scope, $days);
            $grid = [];

            foreach ($this->segments($from, $until, $scope->timezone) as [$segmentFrom, $segmentUntil, $offset]) {
                $local = "date_add(visit_visitor.checked_in_at, interval {$offset} second)";

                $rows = $this->arrivals($scope, $segmentFrom, $segmentUntil)
                    ->selectRaw("weekday({$local}) as weekday, hour({$local}) as hour, count(*) as total")
                    ->groupBy('weekday', 'hour')
                    ->get();

                foreach ($rows as $row) {
                    // MariaDB weekday(): 0 = Monday.
                    $weekday = (int) $row->weekday + 1;
                    $grid[$weekday][(int) $row->hour] = ($grid[$weekday][(int) $row->hour] ?? 0) + (int) $row->total;
                }
            }

            ksort($grid);

            return array_map(function (array $hours): array {
                ksort($hours);

                return $hours;
            }, $grid);
        });
    }

    /**
     * Returning visitors and average visit length for the period and the period before it.
     *
     * @return array{current: array{visitors: int, returning: int, visits: int, avg_seconds: ?int}, previous: array{visitors: int, returning: int, visits: int, avg_seconds: ?int}}
     */
    public function visitors(DashboardScope $scope, int $days): array
    {
        $days = self::period($days);

        return $this->remember('visitors:'.$days, $scope, function () use ($scope, $days): array {
            [$from, $until] = $this->window($scope, $days);
            $previousFrom = $from->copy()->subDays($days);

            return [
                'current' => $this->visitorFigures($scope, $from, $until),
                'previous' => $this->visitorFigures($scope, $previousFrom, $from),
            ];
        });
    }

    /**
     * Companies with the most visits in the period. Names are compared trimmed and ignoring case;
     * the spelling shown is the first in sort order.
     *
     * @return list<array{company: string, visits: int}>
     */
    public function topCompanies(DashboardScope $scope, int $days): array
    {
        $days = self::period($days);

        return $this->remember('top-companies:'.$days, $scope, function () use ($scope, $days): array {
            [$from, $until] = $this->window($scope, $days);

            return $this->arrivals($scope, $from, $until)
                ->join('visitors', 'visitors.id', '=', 'visit_visitor.visitor_id')
                ->whereNotNull('visitors.company')
                ->whereRaw("trim(visitors.company) <> ''")
                ->selectRaw('lower(trim(visitors.company)) as normalised, min(trim(visitors.company)) as company, count(distinct visit_visitor.visit_id) as visits')
                ->groupBy('normalised')
                ->orderByDesc('visits')
                ->orderBy('normalised')
                ->limit(self::TOP_COMPANIES)
                ->get()
                ->map(fn (object $row): array => ['company' => (string) $row->company, 'visits' => (int) $row->visits])
                ->all();
        });
    }

    /**
     * @return array{visitors: int, returning: int, visits: int, avg_seconds: ?int}
     */
    private function visitorFigures(DashboardScope $scope, Carbon $from, Carbon $until): array
    {
        // Each visitor seen in the period, with their first attendance at the sites in scope.
        $perVisitor = $this->attendances($scope)
            ->whereNotNull('visit_visitor.checked_in_at')
            ->where('visit_visitor.checked_in_at', '<', $until)
            ->groupBy('visit_visitor.visitor_id')
            ->havingRaw('max(visit_visitor.checked_in_at) >= ?', [$from])
            ->selectRaw('visit_visitor.visitor_id, min(visit_visitor.checked_in_at) as first_at');

        $returning = DB::query()->fromSub($perVisitor, 'per_visitor')
            ->selectRaw('count(*) as visitors, sum(case when first_at < ? then 1 else 0 end) as returning_visitors', [$from])
            ->first();

        // Completed visits; automatic sign-outs and anything over 24 hours are not visit length.
        $length = $this->arrivals($scope, $from, $until)
            ->whereNotNull('visit_visitor.checked_out_at')
            ->whereColumn('visit_visitor.checked_out_at', '>=', 'visit_visitor.checked_in_at')
            ->whereRaw('timestampdiff(second, visit_visitor.checked_in_at, visit_visitor.checked_out_at) <= ?', [self::MAX_VISIT_SECONDS])
            ->where(fn (Builder $q) => $q->whereNull('visit_visitor.checked_out_via')->orWhere('visit_visitor.checked_out_via', '<>', AutoSignOut::CHANNEL))
            ->selectRaw('count(*) as visits, avg(timestampdiff(second, visit_visitor.checked_in_at, visit_visitor.checked_out_at)) as avg_seconds')
            ->first();

        return [
            'visitors' => (int) ($returning->visitors ?? 0),
            'returning' => (int) ($returning->returning_visitors ?? 0),
            'visits' => (int) ($length->visits ?? 0),
            'avg_seconds' => isset($length->avg_seconds) ? (int) round((float) $length->avg_seconds) : null,
        ];
    }

    /**
     * Start of the first day of the period and now, both in the storage timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(DashboardScope $scope, int $days): array
    {
        $storage = (string) config('app.timezone');
        $from = now($scope->timezone)->startOfDay()->subDays($days - 1)->setTimezone($storage);

        return [$from, now()->setTimezone($storage)];
    }

    /**
     * Splits [from, until) where either timezone changes its UTC offset (daylight saving), so each
     * part converts stored times to local times with one fixed number of seconds.
     *
     * @return list<array{0: Carbon, 1: Carbon, 2: int}>
     */
    private function segments(Carbon $from, Carbon $until, string $timezone): array
    {
        $storageZone = new DateTimeZone((string) config('app.timezone'));
        $localZone = new DateTimeZone($timezone);
        $bounds = [$from->getTimestamp(), $until->getTimestamp()];

        foreach ([$storageZone, $localZone] as $zone) {
            foreach (array_slice($zone->getTransitions($from->getTimestamp(), $until->getTimestamp()) ?: [], 1) as $transition) {
                $bounds[] = (int) $transition['ts'];
            }
        }

        $bounds = array_values(array_unique($bounds));
        sort($bounds);
        $segments = [];

        for ($i = 0; $i < count($bounds) - 1; $i++) {
            $at = new DateTime('@'.$bounds[$i]);
            $offset = $localZone->getOffset($at) - $storageZone->getOffset($at);

            $segments[] = [
                Carbon::createFromTimestamp($bounds[$i], $storageZone),
                Carbon::createFromTimestamp($bounds[$i + 1], $storageZone),
                $offset,
            ];
        }

        return $segments;
    }

    /**
     * Sign-ins at the sites in scope in [from, until).
     */
    private function arrivals(DashboardScope $scope, Carbon $from, Carbon $until): Builder
    {
        return $this->attendances($scope)
            ->where('visit_visitor.checked_in_at', '>=', $from)
            ->where('visit_visitor.checked_in_at', '<', $until);
    }

    private function attendances(DashboardScope $scope): Builder
    {
        return $scope->apply(
            DB::table('visit_visitor')->join('visits', 'visits.id', '=', 'visit_visitor.visit_id'),
            'visits.site_id',
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function remember(string $tile, DashboardScope $scope, Closure $callback): mixed
    {
        return Cache::remember('admin-dashboard:trends:'.$tile.':'.sha1($scope->key()), self::TTL, $callback);
    }
}
