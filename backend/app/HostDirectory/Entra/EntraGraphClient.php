<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Entra;

use App\Microsoft\GraphAppToken;
use App\Microsoft\GraphTokenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads group members from Microsoft Graph with OAuth 2.0 client credentials (application
 * permissions GroupMember.Read.All and User.Read.All). Read-only.
 */
class EntraGraphClient
{
    private const SELECT = 'id,givenName,surname,displayName,mail,userPrincipalName,accountEnabled';

    private readonly GraphAppToken $token;

    public function __construct(
        string $tenantId,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        string $authority = 'https://login.microsoftonline.com',
        private readonly string $graphEndpoint = 'https://graph.microsoft.com',
        private readonly int $timeout = 30,
    ) {
        $this->token = new GraphAppToken($tenantId, $clientId, $clientSecret, $authority, $graphEndpoint, $timeout);
    }

    public static function fromConfig(): self
    {
        $config = (array) config('host_directory.entra');

        foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
            if (blank($config[$key] ?? null)) {
                throw new EntraSyncException("Entra host sync is missing its {$key} (see config/host_directory.php).");
            }
        }

        return new self(
            (string) $config['tenant_id'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) ($config['authority'] ?? 'https://login.microsoftonline.com'),
            (string) ($config['graph_endpoint'] ?? 'https://graph.microsoft.com'),
            (int) ($config['timeout'] ?? 30),
        );
    }

    /**
     * Every user in the group, including members of nested groups.
     *
     * @return list<array<string, mixed>>
     */
    public function groupUsers(string $groupId): array
    {
        $url = rtrim($this->graphEndpoint, '/').'/v1.0/groups/'.rawurlencode($groupId)
            .'/transitiveMembers/microsoft.graph.user?$select='.self::SELECT.'&$top=999';
        $users = [];

        // Follow @odata.nextLink; the cap stops a runaway loop on a malformed response.
        for ($page = 0; $url !== null && $page < 200; $page++) {
            $response = $this->get($url);

            if ($response->status() === 404) {
                throw new EntraSyncException("Entra group {$groupId} was not found.");
            }

            if (! $response->successful()) {
                throw new EntraSyncException(sprintf('Microsoft Graph refused the group request (%d %s).', $response->status(), (string) ($response->json('error.code') ?? 'unknown')));
            }

            foreach ((array) $response->json('value', []) as $user) {
                if (is_array($user) && is_string($user['id'] ?? null)) {
                    $users[] = $user;
                }
            }

            $next = $response->json('@odata.nextLink');
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $users;
    }

    private function get(string $url): Response
    {
        try {
            return $this->token->send(
                fn (string $token): Response => Http::withToken($token)->timeout($this->timeout)->acceptJson()->get($url),
            );
        } catch (ConnectionException $e) {
            throw new EntraSyncException('Microsoft Graph could not be reached: '.$e->getMessage(), 0, $e);
        } catch (GraphTokenException $e) {
            throw new EntraSyncException($e->getMessage(), 0, $e);
        }
    }
}
