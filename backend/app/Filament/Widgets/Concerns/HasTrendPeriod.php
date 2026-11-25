<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets\Concerns;

use App\Dashboard\DashboardTrends;

/**
 * Trend tiles offer the last 30 or 90 days. The class declares `public ?string $filter = '30'`
 * (ChartWidget's own filter property, or the same name in Blade tiles).
 */
trait HasTrendPeriod
{
    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return collect(DashboardTrends::PERIODS)
            ->mapWithKeys(fn (int $days): array => [(string) $days => __('Letzte :days Tage', ['days' => $days])])
            ->all();
    }

    protected function trendDays(): int
    {
        return DashboardTrends::period($this->filter);
    }
}
