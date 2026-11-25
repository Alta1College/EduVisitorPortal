{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@if ($rollCalls === [])
    <p class="muted">{{ __('Keine im Zeitraum.') }}</p>
@else
    <table>
        <tr>
            <th>{{ __('Standort') }}</th>
            <th>{{ __('Art') }}</th>
            <th>{{ __('Beginn') }}</th>
            <th>{{ __('Dauer') }}</th>
            <th class="num">{{ __('Personen') }}</th>
            <th class="num">{{ __('Nicht bestätigt') }}</th>
            <th class="num">{{ __('SMS zugestellt / fehlgeschlagen') }}</th>
        </tr>
        @foreach ($rollCalls as $rollCall)
            <tr>
                <td>{{ $rollCall['site'] }}</td>
                <td>{{ $kinds[$rollCall['kind']] ?? $rollCall['kind'] }}</td>
                <td>{{ $rollCall['started_at']->translatedFormat('D j M, H:i') }}</td>
                <td>{{ $rollCall['ended_at'] ? \App\Reports\Reports::duration($rollCall['started_at'], $rollCall['ended_at']) : __('läuft noch') }}</td>
                <td class="num">{{ $rollCall['people'] }}</td>
                <td class="num @if ($rollCall['unaccounted'] > 0) bad @endif">{{ $rollCall['unaccounted'] }}</td>
                <td class="num">
                    @if ($rollCall['messages'] === []) —
                    @else {{ $rollCall['messages']['sms_delivered'] }} / {{ $rollCall['messages']['sms_failed'] }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif
