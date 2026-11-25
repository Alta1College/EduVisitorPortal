<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Dashboard\DashboardStats;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Widgets\Concerns\UsesDashboardScope;
use App\Support\ForkPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Credential records and recently changed policies (docs/fork/admin-dashboard.md).
 */
class DashboardComplianceWidget extends StatsOverviewWidget
{
    use UsesDashboardScope;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = '300s';

    protected function getHeading(): ?string
    {
        return __('Nachweise und Richtlinien');
    }

    protected function getStats(): array
    {
        $compliance = $this->dashboardStats()->compliance($this->dashboardScope());
        $credentialsUrl = $this->dashboardUser()->can(ForkPermissions::CHECK_CREDENTIALS) ? route('reception.credentials') : null;
        $policiesUrl = PolicyResource::canViewAny() ? PolicyResource::getUrl() : null;

        return [
            Stat::make(__('Nachweise ungeprüft'), $compliance['unchecked'])
                ->description(__('Noch nicht bei der ausstellenden Stelle geprüft'))
                ->icon('heroicon-o-question-mark-circle')
                ->color($compliance['unchecked'] > 0 ? 'warning' : 'success')
                ->url($credentialsUrl),
            Stat::make(__('Nachweise laufen bald ab'), $compliance['expiring'])
                ->description(__('Gültig, laufen innerhalb von :days Tagen ab', ['days' => DashboardStats::EXPIRY_WINDOW_DAYS]))
                ->icon('heroicon-o-clock')
                ->color($compliance['expiring'] > 0 ? 'warning' : 'success')
                ->url($credentialsUrl),
            Stat::make(__('Nachweise nicht gültig'), $compliance['not_valid'])
                ->description(__('Als ungültig geprüft oder abgelaufen'))
                ->icon('heroicon-o-x-circle')
                ->color($compliance['not_valid'] > 0 ? 'danger' : 'success')
                ->url($credentialsUrl),
            Stat::make(__('Richtlinien kürzlich geändert'), $compliance['policies_updated'])
                ->description(__('Aktive Richtlinien mit einer neuen Fassung in den letzten :days Tagen. Besucher bestätigen die aktuelle Fassung bei jeder Anmeldung.', ['days' => DashboardStats::POLICY_WINDOW_DAYS]))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url($policiesUrl),
        ];
    }
}
