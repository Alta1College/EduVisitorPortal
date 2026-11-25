<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time codes that let a returning visitor prove a saved record is theirs before its details
 * can be shown or changed (ReturningVisitors). The code goes to the saved email address only.
 *
 * - Six digits, valid for CODE_TTL_MINUTES, stored only as a hash in the cache.
 * - Each code belongs to one challenge (an encrypted token kept by the screen), so a code sent
 *   to one screen cannot be used on another.
 * - At most MAX_ATTEMPTS wrong codes per challenge, and MAX_SENDS codes per visitor per
 *   SEND_WINDOW_SECONDS, so the code cannot be guessed and the inbox cannot be flooded.
 */
class ReturningVisitorCodes
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS = 3;

    public const SEND_WINDOW_SECONDS = 900;

    public const SENT = 'sent';

    public const NO_EMAIL = 'no_email';

    public const TOO_MANY = 'too_many';

    public const FAILED = 'failed';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Send a new code to the visitor's saved email address.
     *
     * @return array{status: string, challenge: ?string}
     */
    public function send(Visitor $visitor, Site $site): array
    {
        if (blank($visitor->email) || filter_var($visitor->email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => self::NO_EMAIL, 'challenge' => null];
        }

        $limiter = 'returning-code-send|'.$visitor->getKey();

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_SENDS)) {
            return ['status' => self::TOO_MANY, 'challenge' => null];
        }

        RateLimiter::hit($limiter, self::SEND_WINDOW_SECONDS);

        $id = Str::random(32);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($id), [
            'visitor' => (int) $visitor->getKey(),
            'hash' => $this->hash($id, $code),
            'attempts' => 0,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        try {
            Mail::to($visitor->email)->send(new ReturningVisitorCodeMail($code, (string) $site->name, self::CODE_TTL_MINUTES));
        } catch (Throwable $e) {
            Cache::forget($this->key($id));
            Log::warning('Returning visitor code email failed.', ['visitor_id' => $visitor->getKey(), 'error' => $e::class]);

            return ['status' => self::FAILED, 'challenge' => null];
        }

        $this->audit->record('returning_visitor.code_sent', subject: $visitor, site: $site);

        return ['status' => self::SENT, 'challenge' => Crypt::encryptString(json_encode(['c' => $id, 'p' => (int) $visitor->getKey()]))];
    }

    /**
     * Check a code against a challenge. Returns the visitor when it matches; the code is then
     * used up. Wrong codes count towards MAX_ATTEMPTS, after which the challenge is void.
     */
    public function verify(?string $challenge, string $code, Site $site): ?Visitor
    {
        $id = $this->challengeId($challenge);
        $entry = $id !== null ? Cache::get($this->key($id)) : null;

        if (! is_array($entry)) {
            return null;
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';
        $visitor = Visitor::query()->find($entry['visitor']);

        if ($visitor !== null && strlen($code) === 6 && hash_equals($entry['hash'], $this->hash($id, $code))) {
            Cache::forget($this->key($id));
            $this->audit->record('returning_visitor.verified', subject: $visitor, site: $site);

            return $visitor;
        }

        $entry['attempts']++;

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->key($id));
        } else {
            Cache::put($this->key($id), $entry, now()->addMinutes(self::CODE_TTL_MINUTES));
        }

        $this->audit->record('returning_visitor.code_failed', subject: $visitor, site: $site, result: 'failure', context: ['attempts' => $entry['attempts']]);

        return null;
    }

    /**
     * Whether the challenge can still be answered (not expired or used up).
     */
    public function isOpen(?string $challenge): bool
    {
        $id = $this->challengeId($challenge);

        return $id !== null && Cache::has($this->key($id));
    }

    private function challengeId(?string $challenge): ?string
    {
        if ($challenge === null || $challenge === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($challenge), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return is_array($payload) && is_string($payload['c'] ?? null) ? $payload['c'] : null;
    }

    private function key(string $id): string
    {
        return 'returning-visitor-code|'.$id;
    }

    private function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }
}
