<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fork (Phase 11): end a user's sessions everywhere, after a password reset or deactivation.
 * Database sessions are deleted and the "remember me" token is rotated; with other session
 * drivers the LogOutInactiveUsers middleware still signs a deactivated user out.
 */
final class UserSessions
{
    public static function endAllFor(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}
