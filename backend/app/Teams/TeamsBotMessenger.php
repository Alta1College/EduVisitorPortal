<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use App\Microsoft\GraphAppToken;
use App\Microsoft\GraphTokenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proactive 1:1 messages from the Teams app's bot (Azure Bot, single tenant) through the Bot
 * Connector REST API:
 *
 * 1. client-credentials token for https://api.botframework.com/.default from the bot's tenant;
 * 2. POST {serviceUrl}v3/conversations creates (or returns) the 1:1 chat with the user;
 * 3. POST {serviceUrl}v3/conversations/{id}/activities posts the message.
 *
 * The conversation id is cached per bot and user. Creating a 1:1 conversation is idempotent
 * (Teams returns the existing chat), so losing the cache only costs one extra request; this
 * keeps the upstream users table unchanged.
 */
class TeamsBotMessenger
{
    public const SCOPE = 'https://api.botframework.com/.default';

    private const CONVERSATION_TTL_DAYS = 30;

    public static function chatDelivery(): bool
    {
        return config('teams.delivery') === 'chat';
    }

    public static function appId(): string
    {
        return trim((string) (config('teams.bot.app_id') ?: config('teams.client_id')));
    }

    /**
     * Send the message to the user with this Entra object id.
     *
     * @param  array<string, mixed>  $context  ids for the log (never secrets or visitor data)
     * @return bool false when the bot cannot reach the user (403/404: bot not installed for them,
     *              Teams channel not enabled, unknown user); the caller then falls back
     *
     * @throws TeamsNotificationException on throttling or server errors (the job is retried)
     * @throws ConnectionException|GraphTokenException
     */
    public function send(string $aadObjectId, TeamsChatMessage $message, array $context = []): bool
    {
        try {
            return $this->deliver($aadObjectId, $message, $context);
        } catch (GraphTokenException $e) {
            // Entra refused the bot's credentials (wrong app id, secret or tenant): the alert may
            // still work with the Graph app. Other token failures (5xx) are retried.
            if (! in_array($e->getCode(), [400, 401], true)) {
                throw $e;
            }

            Log::warning('Teams bot message not delivered; sending the activity alert instead.', [
                ...$context,
                'step' => 'token',
                'status' => $e->getCode(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function deliver(string $aadObjectId, TeamsChatMessage $message, array $context): bool
    {
        $token = $this->token();
        $conversationId = $this->conversation($token, $aadObjectId, $context);

        if ($conversationId === null) {
            return false;
        }

        $response = $this->post($token, 'v3/conversations/'.rawurlencode($conversationId).'/activities', $message->activity());

        if ($response->successful()) {
            return true;
        }

        $this->failed($response, $context, 'message');
        // A stale cached chat (e.g. the app was removed and installed again) is created afresh next time.
        Cache::forget($this->conversationKey($aadObjectId));

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function conversation(GraphAppToken $token, string $aadObjectId, array $context): ?string
    {
        $key = $this->conversationKey($aadObjectId);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->post($token, 'v3/conversations', [
            'isGroup' => false,
            'bot' => ['id' => '28:'.self::appId()],
            'members' => [['id' => $aadObjectId]], // the plain Entra object id ("8:orgid:" is rejected)
            'channelData' => ['tenant' => ['id' => $this->tenantId()]],
        ]);

        $id = $response->json('id');

        if (! $response->successful() || ! is_string($id) || $id === '') {
            $this->failed($response, $context, 'conversation');

            return null;
        }

        Cache::put($key, $id, now()->addDays(self::CONVERSATION_TTL_DAYS));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(GraphAppToken $token, string $path, array $body): Response
    {
        $url = rtrim((string) config('teams.bot.service_url', 'https://smba.trafficmanager.net/teams/'), '/').'/'.$path;
        $timeout = (int) config('teams.timeout', 15);

        return $token->send(
            fn (string $bearer): Response => Http::withToken($bearer)->timeout($timeout)->acceptJson()->asJson()->post($url, $body),
        );
    }

    /**
     * Throws for temporary failures; logs permanent ones (status and error code only).
     *
     * @param  array<string, mixed>  $context
     */
    private function failed(Response $response, array $context, string $step): void
    {
        $code = (string) ($response->json('error.code') ?? 'unknown');

        if ($response->status() === 429 || $response->serverError()) {
            throw new TeamsNotificationException(sprintf('The Teams bot service did not accept the %s yet (%d %s).', $step, $response->status(), $code));
        }

        Log::warning('Teams bot message not delivered; sending the activity alert instead.', [
            ...$context,
            'step' => $step,
            'status' => $response->status(),
            'bot_error' => $code,
        ]);
    }

    private function token(): GraphAppToken
    {
        return GraphAppToken::fromArray([
            'tenant_id' => $this->tenantId(),
            'client_id' => self::appId(),
            'client_secret' => config('teams.bot.app_secret') ?: config('teams.client_secret'),
            'authority' => config('teams.authority', 'https://login.microsoftonline.com'),
            'timeout' => (int) config('teams.timeout', 15),
            'scope' => self::SCOPE,
        ], 'config/teams.php (bot)');
    }

    private function tenantId(): string
    {
        return trim((string) (config('teams.bot.tenant_id') ?: config('teams.tenant_id')));
    }

    private function conversationKey(string $aadObjectId): string
    {
        return 'teams-bot.conversation.'.hash('sha256', self::appId().'|'.$this->tenantId().'|'.strtolower($aadObjectId));
    }
}
