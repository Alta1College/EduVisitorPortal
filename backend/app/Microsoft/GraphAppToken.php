<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Microsoft;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Graph app-only access token (OAuth 2.0 client credentials), cached until shortly
 * before it expires. Shared by the Entra host sync and Teams notifications; with a different
 * scope also used for other Microsoft APIs (e.g. the Bot Connector, https://api.botframework.com/.default).
 */
final class GraphAppToken
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $authority = 'https://login.microsoftonline.com',
        private readonly string $graphEndpoint = 'https://graph.microsoft.com',
        private readonly int $timeout = 30,
        private readonly ?string $scope = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  tenant_id, client_id, client_secret, authority?, graph_endpoint?, timeout?, scope?
     *
     * @throws GraphTokenException when a credential is missing
     */
    public static function fromArray(array $config, string $source): self
    {
        foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
            if (blank($config[$key] ?? null)) {
                throw new GraphTokenException("Microsoft Graph is missing its {$key} (see {$source}).");
            }
        }

        return new self(
            (string) $config['tenant_id'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) ($config['authority'] ?? 'https://login.microsoftonline.com'),
            (string) ($config['graph_endpoint'] ?? 'https://graph.microsoft.com'),
            (int) ($config['timeout'] ?? 30),
            filled($config['scope'] ?? null) ? (string) $config['scope'] : null,
        );
    }

    public function graphEndpoint(): string
    {
        return rtrim($this->graphEndpoint, '/');
    }

    /**
     * Run a Graph request with the token; on 401 (token revoked or rotated early) fetch a new
     * token once and retry.
     *
     * @param  Closure(string): Response  $request  receives the bearer token
     *
     * @throws ConnectionException|GraphTokenException
     */
    public function send(Closure $request): Response
    {
        $response = $request($this->get());

        if ($response->status() === 401) {
            $this->forget();
            $response = $request($this->get());
        }

        return $response;
    }

    /**
     * @throws ConnectionException|GraphTokenException
     */
    public function get(): string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout($this->timeout)->post(
            rtrim($this->authority, '/').'/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scope(),
            ],
        );

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Entra's error code only, never the secret.
            throw new GraphTokenException(sprintf('Microsoft Entra refused the app credentials (%d %s).', $response->status(), (string) $response->json('error', 'unknown')), $response->status());
        }

        Cache::put($this->cacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /**
     * The OAuth scope requested: the given one, otherwise Microsoft Graph's /.default.
     */
    public function scope(): string
    {
        return $this->scope ?? $this->graphEndpoint().'/.default';
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'microsoft-graph.app-token.'.hash('sha256', $this->authority.'|'.$this->tenantId.'|'.$this->clientId.'|'.$this->scope());
    }
}
