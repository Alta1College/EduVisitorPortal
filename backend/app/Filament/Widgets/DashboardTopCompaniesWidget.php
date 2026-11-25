<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Dashboard\DashboardTrends;
use App\Filament\Widgets\Concerns\HasTrendPeriod;
use App\Filament\Widgets\Concerns\UsesDashboardScope;
use Filament\Widgets\Widget;

/**
 * The ten visitor companies with the most visits in the period, counts only
 * (docs/fork/admin-dashboard.md).
 */
class DashboardTopCompaniesWidget extends Widget
{
    use HasTrendPeriod;
    use UsesDashboardScope;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-top-companies';

    public ?string $filter = '30';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $companies = app(DashboardTrends::class)->topCompanies($this->dashboardScope(), $this->trendDays());

        return [
            'filters' => $this->getFilters(),
            'companies' => $companies,
            'max' => max(1, (int) collect($companies)->max('visits')),
        ];
    }
}
