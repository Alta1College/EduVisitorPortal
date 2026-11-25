{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font: 10pt/1.4 'Helvetica Neue', Arial, sans-serif; color: #1f2937; margin: 0; }
    h1 { font-size: 17pt; margin: 0 0 1mm; color: #214196; }
    h2 { font-size: 12pt; margin: 7mm 0 2mm; color: #214196; border-bottom: 1px solid #d1d5db; padding-bottom: 1mm; }
    p.meta { margin: 0 0 4mm; color: #4b5563; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 1.5mm 2mm; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; font-weight: 600; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    .tiles { display: flex; gap: 3mm; margin: 2mm 0 1mm; }
    .tile { flex: 1; border: 1px solid #e5e7eb; border-radius: 2mm; padding: 2.5mm 3mm; }
    .tile .value { font-size: 16pt; font-weight: 700; }
    .tile .label { color: #4b5563; font-size: 9pt; }
    .warn { color: #b45309; font-weight: 600; }
    .bad { color: #b91c1c; font-weight: 600; }
    .muted { color: #6b7280; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">
        {{ $period }}
        @if (! empty($sites)) · {{ implode(', ', $sites) }} @endif
        · {{ __('Erstellt :time', ['time' => now()->translatedFormat('j M Y, H:i')]) }}
    </p>
    @yield('content')
</body>
</html>
