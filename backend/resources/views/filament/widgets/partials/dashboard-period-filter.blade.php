{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later

    Period select for dashboard trend tiles, styled like a chart widget's filter.
--}}
<x-filament::input.wrapper inline-prefix wire:target="filter" class="fi-wi-chart-filter">
    <x-filament::input.select :aria-label="__('Zeitraum')" inline-prefix wire:model.live="filter">
        @foreach ($filters as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </x-filament::input.select>
</x-filament::input.wrapper>
