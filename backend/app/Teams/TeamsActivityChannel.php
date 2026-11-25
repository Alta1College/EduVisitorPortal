<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use App\HostDirectory\Entra\DirectoryAccount;
use App\Microsoft\GraphAppToken;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notification channel for a host's Teams notification. Notifications using it implement
 * toTeams($notifiable): ?TeamsActivity.
 *
 * - TEAMS_DELIVERY=activity (default): personal activity-feed alert (Microsoft Graph
 *   sendActivityNotification, application permission TeamsActivity.Send).
 * - TEAMS_DELIVERY=chat: a 1:1 chat message from the Teams app's bot (TeamsBotMessenger) when the
 *   host is linked to an Entra account; if the bot cannot reach them (403/404) or they have no
 *   Entra link, the activity alert is sent instead, once.
 *
 * Failures never reach the visitor: notifications are queued. Permanent errors (app not
 * installed for the user, user unknown, permission missing) are logged and dropped; throttling,
 * server and connection errors throw so the queued job is retried.
 */
class TeamsActivityChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toTeams')) {
            return;
        }

        $activity = $notification->toTeams($notifiable);

        if (! $activity instanceof TeamsActivity) {
            return;
        }

        $context = ['user_id' => $notifiable->id, 'visit_id' => $activity->visitId, 'activity_type' => $activity->activityType];

        if ($activity->chat !== null && TeamsBotMessenger::chatDelivery()) {
            $objectId = $this->entraObjectId($notifiable);

            // The bot addresses users by Entra object id only; without one, use the alert.
            if ($objectId !== null && app(TeamsBotMessenger::class)->send($objectId, $activity->chat, $context)) {
                return;
            }
        }

        $recipient = $this->recipient($notifiable);
        $appId = (string) config('teams.app_id');

        if ($recipient === null || $appId === '') {
            Log::warning('Teams notification skipped: no recipient id or Teams app id.', $context);

            return;
        }

        $token = GraphAppToken::fromArray((array) config('teams'), 'config/teams.php');
        $url = $token->graphEndpoint().'/v1.0/users/'.rawurlencode($recipient).'/teamwork/sendActivityNotification';
        $body = $this->body($activity, $appId);
        $timeout = (int) config('teams.timeout', 15);

        $response = $token->send(
            fn (string $bearer): Response => Http::withToken($bearer)->timeout($timeout)->acceptJson()->asJson()->post($url, $body),
        );

        if ($response->successful()) {
            return;
        }

        $context['status'] = $response->status();
        $context['graph_error'] = (string) ($response->json('error.code') ?? 'unknown');

        if ($response->status() === 429 || $response->serverError()) {
            throw new TeamsNotificationException(sprintf('Microsoft Graph did not accept the Teams notification yet (%d %s).', $response->status(), $context['graph_error']));
        }

        // 403: app not installed for the user or permission missing; 404: user not found.
        // Retrying would not help.
        Log::warning('Teams notification not delivered.', $context);
    }

    /**
     * Graph accepts the Entra object id or the user principal name (usually the e-mail address).
     */
    public function recipient(User $user): ?string
    {
        return $this->entraObjectId($user) ?? (filled($user->email) ? (string) $user->email : null);
    }

    /**
     * The host's Entra object id, linked by the Entra host sync.
     */
    public function entraObjectId(User $user): ?string
    {
        $objectId = DirectoryAccount::query()
            ->where('provider', DirectoryAccount::PROVIDER_ENTRA)
            ->where('user_id', $user->id)
            ->value('external_id');

        return is_string($objectId) && $objectId !== '' ? $objectId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(TeamsActivity $activity, string $appId): array
    {
        $parameters = [];

        foreach ($activity->templateParameters as $name => $value) {
            $parameters[] = ['name' => (string) $name, 'value' => Str::limit((string) $value, 100)];
        }

        return [
            'topic' => [
                'source' => 'text',
                'value' => Str::limit($activity->title, 100),
                'webUrl' => self::deepLink($appId, $activity->visitId),
            ],
            'activityType' => $activity->activityType,
            'previewText' => ['content' => Str::limit($activity->previewText, 150)],
            'templateParameters' => $parameters,
        ];
    }

    /**
     * Opens the app's personal "visits" tab; the visit id is passed as the sub-entity.
     */
    public static function deepLink(string $appId, ?int $visitId): string
    {
        $url = 'https://teams.microsoft.com/l/entity/'.rawurlencode($appId).'/'.TeamsAppManifest::TAB_ENTITY_ID;

        if ($visitId === null) {
            return $url;
        }

        return $url.'?context='.rawurlencode((string) json_encode(['subEntityId' => (string) $visitId]));
    }
}
