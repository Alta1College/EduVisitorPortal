<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardScope;
use App\Support\ForkPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who is on site now (docs/fork/admin-dashboard.md).
 */
class DashboardNowWidget extends StatsOverviewWidget
{
    use UsesDashboardScope;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return __('Jetzt');
    }

    protected function getStats(): array
    {
        $scope = $this->dashboardScope();
        $stats = $this->dashboardStats();
        $now = $stats->now($scope);
        $credentials = $stats->credentialsOnSite($scope);
        $user = $this->dashboardUser();
        $onSiteUrl = $user->can(ForkPermissions::RUN_ROLL_CALL) ? route('reception.on-site') : null;
        $credentialsUrl = $user->can(ForkPermissions::CHECK_CREDENTIALS) ? route('reception.credentials') : null;

        $breakdown = collect($now['by_type'])
            ->map(fn (int $count, string $type): string => ($type !== '' ? $type : __('Ohne Besuchertyp')).': '.$count)
            ->take(5)
            ->implode(' · ');

        return [
            Stat::make(__('Jetzt vor Ort'), $now['total'])
                ->description($breakdown !== '' ? $breakdown : __('Niemand angemeldet'))
                ->icon('heroicon-o-user-group')
                ->color('primary')
                ->url($onSiteUrl),
            Stat::make(__('Nachweise vor Ort nicht geklärt'), $credentials)
                ->description($credentials > 0
                    ? __('Nachweis fehlt, ist ungeprüft, abgelaufen oder ungültig')
                    : __('Alle erforderlichen Nachweise sind geklärt'))
                ->icon('heroicon-o-identification')
                ->color($credentials > 0 ? 'danger' : 'success')
                ->url($credentialsUrl),
            Stat::make(__('Seit früheren Tagen angemeldet'), $now['earlier_days'])
                ->description(__('Vor heute angemeldet und nicht abgemeldet'))
                ->icon('heroicon-o-clock')
                ->color($now['earlier_days'] > 0 ? 'warning' : 'gray')
                ->url($onSiteUrl),
        ];
    }
}
