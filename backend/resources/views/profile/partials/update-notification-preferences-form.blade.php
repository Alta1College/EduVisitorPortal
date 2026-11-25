{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- How the host hears about arrivals, new bookings and the daily reminder (fork). --}}
@php
    $notifyByEmail = \App\Notifications\HostNotificationChannels::emailEnabled($user);
    $teamsAvailable = \App\Notifications\HostNotificationChannels::teamsAvailable();
    $notifyByTeams = $teamsAvailable && \App\Notifications\HostNotificationChannels::teamsEnabled($user);
@endphp
<section>
    <header>
        <h2 class="text-xl font-semibold tracking-tight text-base-content">
            {{ __('Benachrichtigungen') }}
        </h2>
        <p class="mt-2 text-sm leading-7 text-base-content/70">
            {{ __('So erfahren Sie, wenn Ihr Besuch angekommen ist, ein Besuch für Sie gebucht wurde und welche Besuche heute geplant sind.') }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 grid gap-5">
        @csrf
        @method('patch')
        <input type="hidden" name="notification_preferences" value="1">

        <div class="grid gap-3">
            <label for="notify_by_email" class="flex cursor-pointer items-center gap-3 text-sm">
                <input type="hidden" name="notify_by_email" value="0">
                <input id="notify_by_email" name="notify_by_email" type="checkbox" value="1" class="checkbox checkbox-sm" @checked(old('notify_by_email', $notifyByEmail))>
                <span>{{ __('E-Mail') }}</span>
            </label>

            @if ($teamsAvailable)
                <label for="notify_by_teams" class="flex cursor-pointer items-center gap-3 text-sm">
                    <input type="hidden" name="notify_by_teams" value="0">
                    <input id="notify_by_teams" name="notify_by_teams" type="checkbox" value="1" class="checkbox checkbox-sm" @checked(old('notify_by_teams', $notifyByTeams))>
                    <span>{{ __('Microsoft Teams') }}</span>
                </label>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-primary-button>{{ __('Speichern') }}</x-primary-button>

            @if (session('notification-preferences-saved'))
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-base-content/65"
                >{{ __('Gespeichert.') }}</p>
            @endif
        </div>
    </form>
</section>
