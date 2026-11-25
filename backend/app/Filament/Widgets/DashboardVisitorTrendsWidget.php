<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Dashboard\DashboardTrends;
use App\Filament\Widgets\Concerns\UsesDashboardScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Returning visitors and average visit length over the last 30 days, compared with the 30 days
 * before (docs/fork/admin-dashboard.md).
 */
class DashboardVisitorTrendsWidget extends StatsOverviewWidget
{
    use UsesDashboardScope;

    public const DAYS = 30;

    protected static ?int $sort = 8;

    protected function getHeading(): ?string
    {
        return __('Besucher in den letzten :days Tagen', ['days' => self::DAYS]);
    }

    protected function getStats(): array
    {
        $figures = app(DashboardTrends::class)->visitors($this->dashboardScope(), self::DAYS);
        $current = $figures['current'];
        $previous = $figures['previous'];

        $share = self::percentage($current['returning'], $current['visitors']);
        $previousShare = self::percentage($previous['returning'], $previous['visitors']);

        return [
            Stat::make(__('Wiederkehrende Besucher'), $share === null ? '—' : $share.' %')
                ->description(__(':returning von :visitors Besuchern waren schon früher da · Vorher: :previous', [
                    'returning' => $current['returning'],
                    'visitors' => $current['visitors'],
                    'previous' => $previousShare === null ? '—' : $previousShare.' %',
                ]))
                ->descriptionIcon(self::trendIcon($share, $previousShare))
                ->icon('heroicon-o-arrow-path')
                ->color('primary'),
            Stat::make(__('Durchschnittliche Besuchsdauer'), self::duration($current['avg_seconds']))
                ->description(__(':count abgeschlossene Besuche, ohne automatische Abmeldungen und Besuche über 24 Stunden · Vorher: :previous', [
                    'count' => $current['visits'],
                    'previous' => self::duration($previous['avg_seconds']),
                ]))
                ->descriptionIcon(self::trendIcon($current['avg_seconds'], $previous['avg_seconds']))
                ->icon('heroicon-o-clock')
                ->color('primary'),
        ];
    }

    public static function percentage(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part / $whole * 100);
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = (int) round($seconds / 60);

        return $minutes < 60
            ? __(':minutes min', ['minutes' => $minutes])
            : __(':hours h :minutes min', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60]);
    }

    private static function trendIcon(?int $current, ?int $previous): string
    {
        return match (true) {
            $current === null || $previous === null || $current === $previous => 'heroicon-m-minus',
            $current > $previous => 'heroicon-m-arrow-trending-up',
            default => 'heroicon-m-arrow-trending-down',
        };
    }
}
