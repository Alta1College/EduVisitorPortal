{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-filament-widgets::widget class="fi-wi-dashboard-top-companies">
    <x-filament::section
        :heading="__('Häufigste Firmen')"
        :description="__('Besuche nach Firma des Besuchers')"
    >
        <x-slot name="afterHeader">
            @include('filament.widgets.partials.dashboard-period-filter', ['filters' => $filters])
        </x-slot>

        @if ($companies === [])
            <p class="top-companies-empty">{{ __('Keine Firmen in diesem Zeitraum') }}</p>
        @else
            <ol class="top-companies-list">
                @foreach ($companies as $row)
                    <li>
                        <span class="top-companies-name">{{ $row['company'] }}</span>
                        <span class="top-companies-bar" aria-hidden="true"><span style="width: {{ round($row['visits'] / $max * 100) }}%"></span></span>
                        <span class="top-companies-count">{{ trans_choice(':count Besuch|:count Besuche', $row['visits'], ['count' => $row['visits']]) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>

    <style>
        .fi-wi-dashboard-top-companies .top-companies-list { display: grid; gap: 0.5rem; }
        .fi-wi-dashboard-top-companies .top-companies-list li { display: grid; grid-template-columns: minmax(8rem, 16rem) 1fr auto; align-items: center; gap: 0.75rem; font-size: 0.875rem; }
        .fi-wi-dashboard-top-companies .top-companies-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fi-wi-dashboard-top-companies .top-companies-bar { height: 0.5rem; border-radius: 9999px; background: color-mix(in oklab, var(--gray-500) 12%, transparent); overflow: hidden; }
        .fi-wi-dashboard-top-companies .top-companies-bar > span { display: block; height: 100%; border-radius: 9999px; background: var(--primary-500); }
        .fi-wi-dashboard-top-companies .top-companies-count { color: var(--gray-500); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fi-wi-dashboard-top-companies .top-companies-empty { color: var(--gray-500); font-size: 0.875rem; }
    </style>
</x-filament-widgets::widget>
