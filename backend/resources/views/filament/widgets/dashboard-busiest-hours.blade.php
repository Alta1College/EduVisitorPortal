{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-filament-widgets::widget class="fi-wi-dashboard-busiest-hours">
    <x-filament::section
        :heading="__('Stoßzeiten')"
        :description="__('Ankünfte nach Wochentag und Uhrzeit')"
    >
        <x-slot name="afterHeader">
            @include('filament.widgets.partials.dashboard-period-filter', ['filters' => $filters])
        </x-slot>

        @if ($total === 0)
            <p class="busiest-hours-empty">{{ __('Keine Ankünfte in diesem Zeitraum') }}</p>
        @else
            <div class="busiest-hours-scroll">
                <table class="busiest-hours-grid">
                    <caption class="fi-sr-only">{{ __('Ankünfte nach Wochentag und Uhrzeit') }}</caption>
                    <thead>
                        <tr>
                            <td></td>
                            @foreach ($hours as $hour)
                                <th scope="col">{{ $hour }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row" title="{{ $row['name'] }}">{{ $row['label'] }}</th>
                                @foreach ($row['cells'] as $cell)
                                    @php
                                        $tip = __(':day, :from–:until: :count Ankünfte', [
                                            'day' => $row['name'],
                                            'from' => sprintf('%02d:00', $cell['hour']),
                                            'until' => sprintf('%02d:00', ($cell['hour'] + 1) % 24),
                                            'count' => $cell['count'],
                                        ]);
                                    @endphp
                                    <td class="busiest-hours-step-{{ $cell['step'] }}" title="{{ $tip }}" data-count="{{ $cell['count'] }}">
                                        <span class="fi-sr-only">{{ $tip }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="busiest-hours-legend" aria-hidden="true">
                <span>{{ __('Weniger') }}</span>
                @foreach (range(0, \App\Filament\Widgets\DashboardBusiestHoursWidget::STEPS) as $step)
                    <span class="busiest-hours-swatch busiest-hours-step-{{ $step }}"></span>
                @endforeach
                <span>{{ __('Mehr') }}</span>
            </div>
        @endif
    </x-filament::section>

    <style>
        .fi-wi-dashboard-busiest-hours .busiest-hours-scroll { overflow-x: auto; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-grid { border-collapse: separate; border-spacing: 3px; width: 100%; min-width: 32rem; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-grid th { color: var(--gray-500); font-size: 0.75rem; font-weight: 500; text-align: center; padding: 0 0.25rem; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-grid tbody th { text-align: start; white-space: nowrap; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-grid td { height: 1.75rem; border-radius: 0.25rem; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-0 { background: color-mix(in oklab, var(--gray-500) 10%, transparent); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-1 { background: color-mix(in oklab, var(--primary-500) 20%, transparent); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-2 { background: color-mix(in oklab, var(--primary-500) 40%, transparent); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-3 { background: color-mix(in oklab, var(--primary-500) 60%, transparent); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-4 { background: color-mix(in oklab, var(--primary-500) 80%, transparent); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-step-5 { background: var(--primary-500); }
        .fi-wi-dashboard-busiest-hours .busiest-hours-legend { display: flex; align-items: center; gap: 0.25rem; justify-content: flex-end; margin-top: 0.75rem; color: var(--gray-500); font-size: 0.75rem; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-swatch { display: inline-block; width: 0.875rem; height: 0.875rem; border-radius: 0.2rem; }
        .fi-wi-dashboard-busiest-hours .busiest-hours-empty { color: var(--gray-500); font-size: 0.875rem; }
    </style>
</x-filament-widgets::widget>
