{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-filament-widgets::widget class="fi-wi-dashboard-welcome">
    <x-filament::section
        icon="heroicon-o-sparkles"
        icon-color="primary"
        :heading="__('Willkommen')"
        :description="__('So richten Sie :name in fünf Schritten ein.', ['name' => $brandName])"
    >
        <x-slot name="afterHeader">
            <x-filament::button color="gray" size="sm" wire:click="dismiss">
                {{ __('Nicht mehr anzeigen') }}
            </x-filament::button>
        </x-slot>

        <ol class="dashboard-welcome-steps">
            @foreach ($steps as $step)
                <li>
                    <strong>{{ $loop->iteration }}. {{ $step['title'] }}</strong>
                    <span class="dashboard-welcome-text">{{ $step['text'] }}</span>
                    @if ($step['links'] !== [])
                        <span class="dashboard-welcome-links">
                            @foreach ($step['links'] as $link)
                                <x-filament::link :href="$link['url']" color="primary" size="sm">{{ $link['label'] }}</x-filament::link>
                            @endforeach
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>

    <style>
        .fi-wi-dashboard-welcome .dashboard-welcome-steps { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .fi-wi-dashboard-welcome .dashboard-welcome-steps li { display: flex; flex-direction: column; gap: 0.25rem; }
        .fi-wi-dashboard-welcome .dashboard-welcome-text { color: var(--gray-500); font-size: 0.875rem; }
        .fi-wi-dashboard-welcome .dashboard-welcome-links { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.25rem; }
    </style>
</x-filament-widgets::widget>
