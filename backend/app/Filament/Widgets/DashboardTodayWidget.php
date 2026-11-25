<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Arrivals and pre-booked visits (docs/fork/admin-dashboard.md).
 */
class DashboardTodayWidget extends StatsOverviewWidget
{
    use UsesDashboardScope;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '300s';

    protected function getHeading(): ?string
    {
        return __('Heute');
    }

    protected function getStats(): array
    {
        $today = $this->dashboardStats()->today($this->dashboardScope());
        $difference = $today['arrivals'] - $today['arrivals_last_week'];
        $user = $this->dashboardUser();
        $allVisitsUrl = $user->can('ViewAny:Visit') ? route('reception.all-visits') : null;

        return [
            Stat::make(__('Ankünfte heute'), $today['arrivals'])
                ->description(__('Letzte Woche bis zu dieser Uhrzeit: :count', ['count' => $today['arrivals_last_week']]))
                ->descriptionIcon(match (true) {
                    $difference > 0 => 'heroicon-m-arrow-trending-up',
                    $difference < 0 => 'heroicon-m-arrow-trending-down',
                    default => 'heroicon-m-minus',
                })
                ->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->color('primary'),
            Stat::make(__('Erwartet und angekommen'), $today['arrived'].' / '.$today['expected'])
                ->description(__(':arrived von :expected vorangemeldeten Besuchern angekommen', ['arrived' => $today['arrived'], 'expected' => $today['expected']]))
                ->icon('heroicon-o-calendar-days')
                ->color($today['expected'] > 0 && $today['arrived'] < $today['expected'] ? 'warning' : 'success')
                ->url($allVisitsUrl),
            Stat::make(__('Nächste 7 Tage'), $today['upcoming_visits'])
                ->description(__(':count vorangemeldete Besucher', ['count' => $today['upcoming_visitors']]))
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->url($allVisitsUrl),
        ];
    }
}
