{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@extends('reports.layout', ['sites' => []])

@section('content')
    <p>
        {{ __('Beginn: :time', ['time' => $data['started_at']->translatedFormat('j M Y, H:i')]) }}@if ($data['started_by']) ({{ $data['started_by'] }})@endif
        · {{ __('Ende: :time', ['time' => $data['ended_at']?->translatedFormat('H:i') ?? '—']) }}@if ($data['ended_by']) ({{ $data['ended_by'] }})@endif
    </p>

    <div class="tiles">
        <div class="tile"><div class="value">{{ array_sum($data['counts']) }}</div><div class="label">{{ __('Personen auf der Liste') }}</div></div>
        <div class="tile"><div class="value">{{ $data['counts']['accounted'] ?? 0 }}</div><div class="label">{{ $statuses['accounted'] }}</div></div>
        <div class="tile"><div class="value">{{ $data['counts']['left_site'] ?? 0 }}</div><div class="label">{{ $statuses['left_site'] }}</div></div>
        <div class="tile"><div class="value @if (($data['counts']['unaccounted'] ?? 0) > 0) bad @endif">{{ $data['counts']['unaccounted'] ?? 0 }}</div><div class="label">{{ $statuses['unaccounted'] }}</div></div>
    </div>

    @if ($data['messages'] !== [])
        <p>
            {{ __('Nachrichten: SMS zugestellt :delivered, fehlgeschlagen :failed, ausstehend :pending, ohne Mobilnummer :none; E-Mails versandt :email.', [
                'delivered' => $data['messages']['sms_delivered'], 'failed' => $data['messages']['sms_failed'],
                'pending' => $data['messages']['sms_pending'], 'none' => $data['messages']['no_mobile'], 'email' => $data['messages']['email_sent'],
            ]) }}
        </p>
    @endif

    <h2>{{ __('Personen') }}</h2>
    <table>
        <tr>
            <th>{{ __('Name') }}</th>
            <th>{{ __('Besuchertyp') }} / {{ __('Firma') }}</th>
            <th>{{ __('Gastgeber') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Geändert um') }}</th>
        </tr>
        @forelse ($data['entries'] as $entry)
            <tr>
                <td>{{ $entry['name'] }}@if ($entry['source'] !== 'snapshot') <span class="muted">({{ $entry['source'] === 'late' ? __('später angemeldet') : __('von Hand ergänzt') }})</span>@endif</td>
                <td>{{ collect([$entry['type'], $entry['company']])->filter()->implode(' / ') ?: '—' }}</td>
                <td>{{ $entry['host'] ?: '—' }}</td>
                <td class="{{ $entry['status'] === 'unaccounted' ? 'bad' : '' }}">{{ $statuses[$entry['status']] ?? $entry['status'] }}</td>
                <td>{{ $entry['status_changed_at'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td class="muted" colspan="5">{{ __('Niemand war angemeldet.') }}</td></tr>
        @endforelse
    </table>
@endsection
