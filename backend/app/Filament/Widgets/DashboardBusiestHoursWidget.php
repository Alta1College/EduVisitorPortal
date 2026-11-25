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
use Illuminate\Support\Carbon;

/**
 * Busiest hours: arrivals by weekday and hour as a heat grid in shades of the primary colour
 * (docs/fork/admin-dashboard.md).
 */
class DashboardBusiestHoursWidget extends Widget
{
    use HasTrendPeriod;
    use UsesDashboardScope;

    /** Hours always shown, so a quiet period still reads as a working day. */
    public const FIRST_HOUR = 7;

    public const LAST_HOUR = 18;

    /** Shades of the primary colour above zero. */
    public const STEPS = 5;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-busiest-hours';

    public ?string $filter = '30';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $grid = app(DashboardTrends::class)->busiestHours($this->dashboardScope(), $this->trendDays());
        $counts = collect($grid)->flatten();
        $hoursWithData = collect($grid)->flatMap(fn (array $hours): array => array_keys($hours));
        $first = min(self::FIRST_HOUR, $hoursWithData->min() ?? self::FIRST_HOUR);
        $last = max(self::LAST_HOUR, $hoursWithData->max() ?? self::LAST_HOUR);
        $max = max(1, (int) $counts->max());

        // Monday to Friday always; Saturday and Sunday only when someone came.
        $weekdays = array_values(array_filter(range(1, 7), fn (int $day): bool => $day <= 5 || ! empty($grid[$day])));
        $monday = Carbon::now()->locale(app()->getLocale())->startOfWeek(Carbon::MONDAY);

        $rows = array_map(function (int $day) use ($grid, $first, $last, $max, $monday): array {
            $date = $monday->copy()->addDays($day - 1);

            return [
                'label' => $date->isoFormat('dd'),
                'name' => $date->isoFormat('dddd'),
                'cells' => array_map(function (int $hour) use ($grid, $day, $max): array {
                    $count = (int) ($grid[$day][$hour] ?? 0);

                    return [
                        'hour' => $hour,
                        'count' => $count,
                        'step' => $count === 0 ? 0 : max(1, (int) ceil($count / $max * self::STEPS)),
                    ];
                }, range($first, $last)),
            ];
        }, $weekdays);

        return [
            'filters' => $this->getFilters(),
            'hours' => range($first, $last),
            'rows' => $rows,
            'total' => (int) $counts->sum(),
        ];
    }
}
