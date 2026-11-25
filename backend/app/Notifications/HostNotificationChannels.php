<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Notifications;

use App\Models\User;
use App\Teams\TeamsActivityChannel;
use Illuminate\Http\Request;

/**
 * Which channels a host's notifications (visitor arrived, visit booked, daily reminder) use:
 * the host's own choice (users.notify_by_email / notify_by_teams) or, when unset, the
 * organisation default (settings "Host notifications"). Teams only when it is set up.
 */
final class HostNotificationChannels
{
    /**
     * @return list<string> empty when the host turned every channel off
     */
    public static function for(User $user): array
    {
        $channels = [];

        if (self::emailEnabled($user)) {
            $channels[] = 'mail';
        }

        if (self::teamsEnabled($user)) {
            $channels[] = TeamsActivityChannel::class;
        }

        return $channels;
    }

    public static function emailEnabled(User $user): bool
    {
        return self::flag($user->getAttribute('notify_by_email')) ?? self::emailDefault();
    }

    public static function teamsEnabled(User $user): bool
    {
        return self::teamsAvailable()
            && (self::flag($user->getAttribute('notify_by_teams')) ?? self::teamsDefault());
    }

    /**
     * Teams notifications are switched on and the Teams app id is configured.
     */
    public static function teamsAvailable(): bool
    {
        return (bool) config('teams.enabled') && filled(config('teams.app_id'));
    }

    public static function emailDefault(): bool
    {
        return self::flag(config('organisation.host_notifications.email')) ?? true;
    }

    public static function teamsDefault(): bool
    {
        return self::flag(config('organisation.host_notifications.teams')) ?? false;
    }

    /**
     * Store the host's choice from the profile form as explicit booleans. The Teams choice is
     * only changed while Teams is available (the checkbox is hidden otherwise).
     */
    public static function savePreferences(User $user, Request $request): void
    {
        if (! $request->boolean('notification_preferences')) {
            return;
        }

        $user->setAttribute('notify_by_email', $request->boolean('notify_by_email'));

        if (self::teamsAvailable()) {
            $user->setAttribute('notify_by_teams', $request->boolean('notify_by_teams'));
        }

        $user->save();

        if ($request->hasSession()) {
            $request->session()->flash('notification-preferences-saved', true);
        }
    }

    /**
     * Validation rules for the profile form fields.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'notification_preferences' => ['sometimes', 'boolean'],
            'notify_by_email' => ['sometimes', 'boolean'],
            'notify_by_teams' => ['sometimes', 'boolean'],
        ];
    }

    private static function flag(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
