{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@extends('reports.layout')

@section('content')
    <div class="tiles">
        <div class="tile"><div class="value">{{ $data['arrivals'] }}</div><div class="label">{{ __('Anmeldungen') }}</div></div>
        <div class="tile"><div class="value">{{ $data['visitors'] }}</div><div class="label">{{ __('Personen') }}</div></div>
        <div class="tile">
            <div class="value">{{ $data['avg_visit_seconds'] === null ? '—' : \Carbon\CarbonInterval::seconds($data['avg_visit_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}</div>
            <div class="label">{{ __('Durchschnittliche Besuchsdauer') }}</div>
        </div>
        <div class="tile"><div class="value @if ($data['auto_sign_outs'] > 0) warn @endif">{{ $data['auto_sign_outs'] }}</div><div class="label">{{ __('Automatisch abgemeldet') }}</div></div>
        <div class="tile"><div class="value @if ($data['credential_issues'] > 0) bad @endif">{{ $data['credential_issues'] }}</div><div class="label">{{ __('Nachweise mit Handlungsbedarf') }}</div></div>
    </div>
    <p class="muted">{{ __('Jetzt noch angemeldet aus dieser oder früheren Wochen: :count', ['count' => $data['still_signed_in']]) }}</p>

    <h2>{{ __('Anmeldungen pro Tag') }}</h2>
    <table>
        <tr>@foreach ($data['by_day'] as $day => $total)<th class="num">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('D j M') }}</th>@endforeach</tr>
        <tr>@foreach ($data['by_day'] as $total)<td class="num">{{ $total }}</td>@endforeach</tr>
    </table>

    <div class="tiles">
        <div style="flex: 1">
            <h2>{{ __('Nach Standort') }}</h2>
            <table>
                @forelse ($data['by_site'] as $site => $total)
                    <tr><td>{{ $site }}</td><td class="num">{{ $total }}</td></tr>
                @empty
                    <tr><td class="muted">{{ __('Keine Anmeldungen.') }}</td></tr>
                @endforelse
            </table>
        </div>
        <div style="flex: 1">
            <h2>{{ __('Nach Besuchertyp') }}</h2>
            <table>
                @forelse ($data['by_type'] as $type => $total)
                    <tr><td>{{ $type }}</td><td class="num">{{ $total }}</td></tr>
                @empty
                    <tr><td class="muted">{{ __('Keine Anmeldungen.') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="tiles">
        <div style="flex: 1">
            <h2>{{ __('Stoßzeiten') }}</h2>
            <table>
                @forelse ($data['busiest'] as $slot)
                    <tr><td>{{ $weekdays[$slot['weekday']] }}, {{ sprintf('%02d:00–%02d:00', $slot['hour'], ($slot['hour'] + 1) % 24) }}</td><td class="num">{{ $slot['arrivals'] }}</td></tr>
                @empty
                    <tr><td class="muted">—</td></tr>
                @endforelse
            </table>
        </div>
        <div style="flex: 1">
            <h2>{{ __('Häufigste Firmen') }}</h2>
            <table>
                @forelse ($data['top_companies'] as $row)
                    <tr><td>{{ $row['company'] }}</td><td class="num">{{ $row['visits'] }}</td></tr>
                @empty
                    <tr><td class="muted">—</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <h2>{{ __('Räumungsübungen und Notfälle') }}</h2>
    @include('reports.partials.roll-calls', ['rollCalls' => $data['roll_calls'], 'kinds' => $kinds])
@endsection
