{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@extends('reports.layout')

@section('content')
    <h2>{{ __('Nachweise') }}</h2>
    <p class="muted">{{ __('Nachweise von Personen, die einen dieser Standorte besucht haben (Stand heute). Im Monat geprüft: :count.', ['count' => $data['credentials_checked']]) }}</p>
    <table>
        <tr>@foreach ($data['credential_states'] as $state => $total)<th class="num">{{ $states[$state] ?? $state }}</th>@endforeach</tr>
        <tr>@foreach ($data['credential_states'] as $state => $total)<td class="num">{{ $total }}</td>@endforeach</tr>
    </table>

    @if ($data['credential_list'] !== [])
        <table>
            <tr>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Firma') }}</th>
                <th>{{ __('Nachweis') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Gültig bis') }}</th>
                <th>{{ __('Zuletzt geprüft') }}</th>
            </tr>
            @foreach ($data['credential_list'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['company'] ?: '—' }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td class="{{ in_array($row['state'], ['invalid', 'expired'], true) ? 'bad' : 'warn' }}">{{ $states[$row['state']] ?? $row['state'] }}</td>
                    <td>{{ $row['expires_on'] ? \Illuminate\Support\Carbon::parse($row['expires_on'])->translatedFormat('j M Y') : '—' }}</td>
                    <td>{{ $row['checked_at'] ? \Illuminate\Support\Carbon::parse($row['checked_at'])->translatedFormat('j M Y') : '—' }}</td>
                </tr>
            @endforeach
        </table>
        @if ($data['credential_list_total'] > count($data['credential_list']))
            <p class="muted">{{ __('Weitere :count Einträge auf der Seite „Nachweise“.', ['count' => $data['credential_list_total'] - count($data['credential_list'])]) }}</p>
        @endif
    @endif

    <h2>{{ __('Räumungsübungen und Notfälle im Monat') }}</h2>
    @include('reports.partials.roll-calls', ['rollCalls' => $data['roll_calls'], 'kinds' => $kinds])

    <h2>{{ __('Letzte Räumungsübung je Standort') }}</h2>
    <table>
        <tr><th>{{ __('Standort') }}</th><th>{{ __('Letzte Übung') }}</th><th class="num">{{ __('Tage her') }}</th><th class="num">{{ __('Warnen nach (Tage)') }}</th></tr>
        @foreach ($data['drills'] as $drill)
            <tr>
                <td>{{ $drill['site'] }}</td>
                <td>{{ $drill['last_drill_at'] ? $drill['last_drill_at']->translatedFormat('j M Y') : __('Noch keine') }}</td>
                <td class="num @if ($drill['overdue']) warn @endif">{{ $drill['days_since'] ?? '—' }}</td>
                <td class="num">{{ $drill['warning_days'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('Richtlinien') }}</h2>
    <table>
        <tr><th>{{ __('Richtlinie') }}</th><th class="num">{{ __('Version') }}</th><th>{{ __('Veröffentlicht') }}</th></tr>
        @forelse ($data['policies'] as $policy)
            <tr>
                <td>{{ $policy['name'] }} @if ($policy['new_this_month']) <span class="warn">{{ __('neu in diesem Monat') }}</span> @endif</td>
                <td class="num">{{ $policy['version'] ?? '—' }}</td>
                <td>{{ $policy['published_at'] ? \Illuminate\Support\Carbon::parse($policy['published_at'])->translatedFormat('j M Y') : '—' }}</td>
            </tr>
        @empty
            <tr><td class="muted">{{ __('Keine aktiven Richtlinien.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Automatische Löschung') }}</h2>
    @if ($data['retention'] === [])
        <p class="muted">{{ __('Keine Löschläufe im Monat protokolliert.') }}</p>
    @else
        <table>
            @foreach ($data['retention'] as $action => $runs)
                <tr><td>{{ $action }}</td><td class="num">{{ trans_choice(':count Lauf|:count Läufe', $runs, ['count' => $runs]) }}</td></tr>
            @endforeach
        </table>
    @endif
@endsection
