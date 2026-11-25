<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(Request $request): RequestPasswordResetLinkViewResponse
    {
        return app(RequestPasswordResetLinkViewResponse::class);
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        if (config('fortify.lowercase_usernames') && $request->has(Fortify::email())) {
            $request->merge([
                Fortify::email() => Str::lower($request->{Fortify::email()}),
            ]);
        }

        // Fork (Phase 11): the response is always the same "link sent" message, whether or not the
        // address has an account, is SSO-only or was asked for recently, so this form cannot be
        // used to find staff addresses. Requests are also rate-limited per address and IP.
        $email = Str::lower((string) $request->input(Fortify::email()));
        $sent = app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
        $throttleKey = 'password-reset-link:'.sha1($email).'|'.$request->ip();
        $maxAttempts = max(1, (int) config('auth.password_reset_rate_limit.max_attempts', 6));

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return $sent;
        }

        RateLimiter::hit($throttleKey, max(1, (int) config('auth.password_reset_rate_limit.decay_seconds', 60)));

        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->local_login_allowed === false) {
            return $sent;
        }

        $this->broker()->sendResetLink($request->only(Fortify::email()));

        return $sent;
    }

    /**
     * Get the broker to be used during password reset.
     */
    protected function broker(): PasswordBroker
    {
        return Password::broker(config('fortify.passwords'));
    }
}
