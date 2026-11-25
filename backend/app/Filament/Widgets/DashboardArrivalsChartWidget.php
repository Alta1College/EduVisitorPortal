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
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Arrivals per day, stacked by visitor type (docs/fork/admin-dashboard.md).
 */
class DashboardArrivalsChartWidget extends ChartWidget
{
    use HasTrendPeriod;
    use UsesDashboardScope;

    /** Theme colours for the visitor types, in order; shades repeat lighter after a full round. */
    private const PALETTE = ['primary', 'info', 'success', 'warning', 'danger', 'gray'];

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '18rem';

    public ?string $filter = '30';

    public function getHeading(): string
    {
        return __('Ankünfte pro Tag');
    }

    public function getDescription(): string
    {
        return __('Anmeldungen nach Besuchertyp');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $data = app(DashboardTrends::class)->arrivalsPerDay($this->dashboardScope(), $this->trendDays());
        $datasets = [];

        foreach ($data['series'] as $i => $series) {
            $palette = FilamentColor::getColor(self::PALETTE[$i % count(self::PALETTE)]) ?? [];
            $color = $palette[intdiv($i, count(self::PALETTE)) % 2 === 0 ? 500 : 300] ?? null;

            $datasets[] = array_filter([
                'label' => $series['type'] === null ? __('Ohne Besuchertyp') : $series['type'],
                'data' => $series['counts'],
                'backgroundColor' => $color,
                'stack' => 'arrivals',
            ], fn ($value): bool => $value !== null);
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (string $day): string => Carbon::parse($day)->locale(app()->getLocale())->isoFormat('D MMM'), $data['days']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
