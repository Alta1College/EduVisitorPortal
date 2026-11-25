<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Dashboard;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The sites one admin dashboard view covers: the site picked in the filter, limited to the sites
 * the user may see. Users who may see every visit (admins) may pick any site; everyone else is
 * limited to their assigned sites.
 */
final class DashboardScope
{
    /**
     * @param  list<int>|null  $siteIds  null = every site
     */
    private function __construct(
        public readonly ?array $siteIds,
        public readonly string $timezone,
    ) {}

    public static function for(User $user, mixed $requestedSiteId = null): self
    {
        $requested = is_numeric($requestedSiteId) ? (int) $requestedSiteId : null;
        $allowed = self::allowedSiteIds($user);

        if ($requested !== null && ($allowed === null || in_array($requested, $allowed, true))) {
            $siteIds = [$requested];
        } else {
            $siteIds = $allowed;
        }

        $timezone = (string) config('app.timezone');

        if ($siteIds !== null && count($siteIds) === 1) {
            $timezone = (string) (Site::query()->whereKey($siteIds[0])->value('timezone') ?: $timezone);
        }

        return new self($siteIds, $timezone);
    }

    /**
     * @return list<int>|null null = every site
     */
    public static function allowedSiteIds(User $user): ?array
    {
        if ($user->can('ViewAny:Visit')) {
            return null;
        }

        return $user->assignedSiteIds()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * Sites offered in the filter.
     *
     * @return array<int, string> id => name
     */
    public static function siteOptions(User $user): array
    {
        $allowed = self::allowedSiteIds($user);

        return Site::query()->where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Part of every cache key: the same sites and day give the same numbers, whoever asks.
     */
    public function key(): string
    {
        $sites = $this->siteIds === null ? 'all' : ($this->siteIds === [] ? 'none' : implode('-', $this->siteIds));

        return $sites.'|'.$this->timezone.'|'.$this->today()->toDateString();
    }

    /**
     * Active sites in scope.
     *
     * @return Collection<int, Site>
     */
    public function sites(): Collection
    {
        return Site::query()->where('is_active', true)
            ->when($this->siteIds !== null, fn ($q) => $q->whereIn('id', $this->siteIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * Start of today in the scope's timezone, as a time in the storage timezone (what the
     * database holds).
     */
    public function today(): Carbon
    {
        return now($this->timezone)->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    /**
     * Restrict a query to the sites in scope.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply($query, string $column)
    {
        return $this->siteIds === null ? $query : $query->whereIn($column, $this->siteIds);
    }
}
