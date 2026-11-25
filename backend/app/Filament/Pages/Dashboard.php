<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Pages;

use App\Dashboard\DashboardScope;
use App\Filament\Widgets\DashboardArrivalsChartWidget;
use App\Filament\Widgets\DashboardBusiestHoursWidget;
use App\Filament\Widgets\DashboardComplianceWidget;
use App\Filament\Widgets\DashboardHealthWidget;
use App\Filament\Widgets\DashboardNowWidget;
use App\Filament\Widgets\DashboardSafetyWidget;
use App\Filament\Widgets\DashboardTodayWidget;
use App\Filament\Widgets\DashboardTopCompaniesWidget;
use App\Filament\Widgets\DashboardVisitorTrendsWidget;
use App\Filament\Widgets\DashboardWelcomeWidget;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends \Filament\Pages\Dashboard
{
    // Fork: site filter and admin dashboard tiles (docs/fork/admin-dashboard.md).
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Admin';

    protected static ?string $title = 'Admin';

    public function getWidgets(): array
    {
        return [
            DashboardWelcomeWidget::class,
            DashboardNowWidget::class,
            DashboardTodayWidget::class,
            DashboardComplianceWidget::class,
            DashboardSafetyWidget::class,
            DashboardArrivalsChartWidget::class,
            DashboardBusiestHoursWidget::class,
            DashboardVisitorTrendsWidget::class,
            DashboardTopCompaniesWidget::class,
            DashboardHealthWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function filtersForm(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();

        return $schema->components([
            Select::make('site_id')
                ->label(__('Standort'))
                ->placeholder(DashboardScope::allowedSiteIds($user) === null ? __('Alle Standorte') : __('Alle meine Standorte'))
                ->options(fn (): array => DashboardScope::siteOptions($user))
                ->native(false),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showWelcome')
                ->label(__('Einstieg anzeigen'))
                ->icon('heroicon-o-sparkles')
                ->link()
                ->visible(fn (): bool => auth()->user()?->getAttribute('dashboard_welcome_dismissed_at') !== null)
                ->action(function (): void {
                    auth()->user()?->forceFill(['dashboard_welcome_dismissed_at' => null])->save();
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
