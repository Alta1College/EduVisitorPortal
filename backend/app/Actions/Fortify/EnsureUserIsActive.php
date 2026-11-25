<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Actions\Fortify;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class EnsureUserIsActive
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly LoginRateLimiter $limiter,
    ) {}

    public function handle($request, $next)
    {
        $provider = $this->guard->getProvider();
        $user = $provider->retrieveByCredentials($request->only(Fortify::username(), 'password'));

        if (! $user || ! $provider->validateCredentials($user, ['password' => $request->password])) {
            return $next($request);
        }

        if (! $user->is_active) {
            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                // Fork (Phase 11): the generic message, so a correct password for a deactivated
                // account is not confirmed to whoever typed it.
                Fortify::username() => __('auth.failed'),
            ]);
        }

        return $next($request);
    }
}
