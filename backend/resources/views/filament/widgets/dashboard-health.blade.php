{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@php
    $colors = ['ok' => 'success', 'warning' => 'warning', 'problem' => 'danger', 'off' => 'gray'];
    $labels = ['ok' => __('In Ordnung'), 'warning' => __('Warnung'), 'problem' => __('Problem'), 'off' => __('Aus')];
@endphp
<x-filament-widgets::widget class="fi-wi-dashboard-health" wire:poll.60s>
    <x-filament::section
        icon="heroicon-o-heart"
        :heading="__('Systemzustand')"
        :description="__('Nur für Administratoren sichtbar. Aktualisiert jede Minute.')"
    >
        <ul class="dashboard-health-items">
            @foreach ($items as $item)
                <li class="dashboard-health-item" data-health="{{ $item['key'] }}" data-status="{{ $item['status'] }}">
                    <div class="dashboard-health-head">
                        <x-filament::icon :icon="$item['icon']" class="dashboard-health-icon" />
                        <span class="dashboard-health-label">{{ $item['label'] }}</span>
                        <x-filament::badge :color="$colors[$item['status']]" size="sm">{{ $labels[$item['status']] }}</x-filament::badge>
                    </div>
                    <div class="dashboard-health-value">{{ $item['value'] }}</div>
                    @if (filled($item['detail']))
                        <div class="dashboard-health-detail">{{ $item['detail'] }}</div>
                    @endif
                    @if ($item['list'] !== [])
                        <ul class="dashboard-health-list">
                            @foreach ($item['list'] as $entry)
                                <li>
                                    <span>{{ $entry['text'] }}</span>
                                    @if ($entry['alert'])
                                        <x-filament::badge color="danger" size="sm">{{ $entry['meta'] }}</x-filament::badge>
                                    @else
                                        <span class="dashboard-health-detail">{{ $entry['meta'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <style>
        .fi-wi-dashboard-health .dashboard-health-items { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .fi-wi-dashboard-health .dashboard-health-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .fi-wi-dashboard-health .dashboard-health-head { display: flex; align-items: center; gap: 0.5rem; }
        .fi-wi-dashboard-health .dashboard-health-icon { width: 1.25rem; height: 1.25rem; color: var(--gray-500); }
        .fi-wi-dashboard-health .dashboard-health-label { font-weight: 600; font-size: 0.875rem; flex: 1; }
        .fi-wi-dashboard-health .dashboard-health-value { font-size: 0.875rem; }
        .fi-wi-dashboard-health .dashboard-health-detail { color: var(--gray-500); font-size: 0.8125rem; }
        .fi-wi-dashboard-health .dashboard-health-list { display: grid; gap: 0.25rem; margin-top: 0.25rem; font-size: 0.8125rem; }
        .fi-wi-dashboard-health .dashboard-health-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem; }
    </style>
</x-filament-widgets::widget>
