<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;

/**
 * Fork (Phase 11): failed local logins take at least the same minimum time, whether or not the
 * e-mail address has an account. Without this, an unknown address fails before any password hash
 * is checked and a known one after several, so response times reveal which staff have accounts.
 * Successful logins (including the step to two-factor) return without the extra wait.
 */
class TimeboxLoginAttempt
{
    public function __construct(private readonly Timebox $timebox) {}

    public function handle($request, Closure $next)
    {
        return $this->timebox->call(function (Timebox $timebox) use ($request, $next) {
            // Fortify's pipeline turns a failed login into an ordinary redirect response, so the
            // outcome is read from the session: signed in, or passed on to the two-factor step.
            $response = $next($request);

            if (Auth::guard(config('fortify.guard'))->check() || $request->session()->has('login.id')) {
                $timebox->returnEarly();
            }

            return $response;
        }, (int) config('auth.login_minimum_failure_microseconds', 1_500_000));
    }
}
