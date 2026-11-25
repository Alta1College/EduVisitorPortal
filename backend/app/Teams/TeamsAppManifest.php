<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use App\Teams\Package\TeamsAppIcons;
use InvalidArgumentException;

/**
 * The Teams app manifest (schema v1.17): a personal "Visits" tab, the activity types the host
 * notifications send and, with TEAMS_DELIVERY=chat, the notification-only bot that sends the
 * chat messages. Every value comes from configuration.
 */
final class TeamsAppManifest
{
    public const SCHEMA_VERSION = '1.17';

    public const TAB_ENTITY_ID = 'visits';

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException with a message for the administrator
     */
    public static function build(string $version = '1.0.0'): array
    {
        $appId = trim((string) config('teams.app_id'));
        $clientId = trim((string) config('teams.client_id'));

        if (preg_match(self::GUID, $appId) !== 1) {
            throw new InvalidArgumentException('TEAMS_APP_ID must be set to a GUID (generate one once, e.g. with `php -r "echo Illuminate\\Support\\Str::uuid();"`, and keep it).');
        }

        if (preg_match(self::GUID, $clientId) !== 1) {
            throw new InvalidArgumentException('The Microsoft Graph client id (TEAMS_CLIENT_ID or MICROSOFT_GRAPH_CLIENT_ID) must be set to the app registration\'s GUID.');
        }

        $botId = strtolower(TeamsBotMessenger::appId());

        if (TeamsBotMessenger::chatDelivery() && preg_match(self::GUID, $botId) !== 1) {
            throw new InvalidArgumentException('TEAMS_DELIVERY=chat needs the bot app registration id (TEAMS_BOT_APP_ID, otherwise the Microsoft Graph client id) as a GUID.');
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new InvalidArgumentException('The version must look like 1.0.0.');
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === '') {
            throw new InvalidArgumentException('APP_URL must be set to the portal\'s address.');
        }

        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $base = 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
        $name = trim((string) config('branding.name')) ?: 'Edu Visitor Portal';

        $activityTypes = [];

        foreach (TeamsActivity::types() as $type => $definition) {
            $activityTypes[] = ['type' => $type, ...$definition];
        }

        $manifest = [
            '$schema' => 'https://developer.microsoft.com/en-us/json-schemas/teams/v'.self::SCHEMA_VERSION.'/MicrosoftTeams.schema.json',
            'manifestVersion' => self::SCHEMA_VERSION,
            'version' => $version,
            'id' => strtolower($appId),
            'developer' => [
                'name' => mb_substr($name, 0, 32),
                'websiteUrl' => $base,
                'privacyUrl' => $base,
                'termsOfUseUrl' => $base,
            ],
            'name' => [
                'short' => mb_substr($name, 0, 30),
                'full' => mb_substr($name, 0, 100),
            ],
            'description' => [
                'short' => mb_substr('Visitor alerts for hosts from '.$name, 0, 80),
                'full' => 'Get an alert in Teams when your visitor arrives, when a visit is booked for you and each morning for the visits planned that day. Open the portal from the Visits tab.',
            ],
            'icons' => ['color' => 'color.png', 'outline' => 'outline.png'],
            'accentColor' => TeamsAppIcons::primaryColour(),
            'staticTabs' => [[
                'entityId' => self::TAB_ENTITY_ID,
                'name' => 'Visits',
                'contentUrl' => $base.'/teams/tab?visit={subEntityId}',
                'websiteUrl' => $base,
                'scopes' => ['personal'],
            ]],
            'permissions' => ['identity'],
            'validDomains' => [$host],
            // The resource and the resource-specific permission let the Graph app send activity
            // alerts to the users who have this Teams app installed (Microsoft's activity feed
            // checks both, alongside the tenant-wide TeamsActivity.Send permission).
            'webApplicationInfo' => [
                'id' => strtolower($clientId),
                'resource' => 'api://'.$host.'/'.strtolower($clientId),
            ],
            'authorization' => [
                'permissions' => [
                    'resourceSpecific' => [
                        ['name' => 'TeamsActivity.Send.User', 'type' => 'Application'],
                    ],
                ],
            ],
            'activities' => ['activityTypes' => $activityTypes],
        ];

        if (TeamsBotMessenger::chatDelivery()) {
            // Chat messages to hosts. Notification-only: the bot never replies, users cannot
            // message it, and its messaging endpoint ignores what Teams posts.
            $manifest['bots'] = [[
                'botId' => $botId,
                'scopes' => ['personal'],
                'isNotificationOnly' => true,
                'supportsFiles' => false,
                'supportsCalling' => false,
                'supportsVideo' => false,
            ]];
        }

        return $manifest;
    }
}
