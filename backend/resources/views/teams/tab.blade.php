{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- Teams personal tab (fork): opens the portal in the browser; shows no visit data. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ config('branding.name') }}</title>
    @vite(['resources/css/app.css'])
    <x-brand-stylesheet />
</head>
<body class="min-h-dvh bg-base-200 text-base-content antialiased">
    <div class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center px-6 py-8">
        <main class="flex flex-col items-center gap-6 rounded-3xl border border-base-300 bg-base-100 p-8 text-center shadow-sm">
            @if ($logo)
                <img src="{{ asset($logo) }}" alt="{{ config('branding.name') }}" class="h-12 w-auto object-contain">
            @endif

            <p class="text-base leading-7 text-base-content/80">{{ __('Ihre Besuche verwalten Sie im Besucherportal.') }}</p>

            <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-primary rounded-2xl">
                {{ __('Besucherportal öffnen') }}
            </a>
        </main>
    </div>
</body>
</html>
