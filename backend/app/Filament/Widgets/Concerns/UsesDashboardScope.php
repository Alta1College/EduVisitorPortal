<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets\Concerns;

use App\Dashboard\DashboardScope;
use App\Dashboard\DashboardStats;
use App\Models\User;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Dashboard tiles read the page's site filter, limited to the sites the user may see.
 */
trait UsesDashboardScope
{
    use InteractsWithPageFilters;

    protected function dashboardScope(): DashboardScope
    {
        return DashboardScope::for($this->dashboardUser(), $this->pageFilters['site_id'] ?? null);
    }

    protected function dashboardStats(): DashboardStats
    {
        return app(DashboardStats::class);
    }

    protected function dashboardUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
