<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Optional Microsoft Teams activity-feed notifications for hosts (docs/fork/teams-notifications.md).
 * Hosts get a Teams alert when a visitor arrives, when a visit is booked for them and for the
 * daily reminder, alongside or instead of e-mail (organisation setting plus per-host choice).
 *
 * Credentials default to the Microsoft Graph mail app (MICROSOFT_GRAPH_*), which then needs the
 * TeamsActivity.Send application permission as well. Alerts only reach users who have the Teams
 * app (built by `php artisan visitorportal:teams:package`) installed.
 *
 * Delivery `chat` sends a 1:1 message with an Adaptive Card from the Teams app's bot instead
 * (Azure Bot resource, Bot Connector API). The bot defaults to the same app registration and
 * tenant. If the bot cannot reach a host (bot not installed, Teams channel off), the activity
 * alert is sent instead.
 */
return [
    'enabled' => (bool) env('TEAMS_NOTIFICATIONS_ENABLED', false),

    // Id of the Teams app in its manifest (a GUID you generate once and keep).
    'app_id' => env('TEAMS_APP_ID'),

    'tenant_id' => env('TEAMS_TENANT_ID', env('MICROSOFT_GRAPH_TENANT_ID')),
    'client_id' => env('TEAMS_CLIENT_ID', env('MICROSOFT_GRAPH_CLIENT_ID')),
    'client_secret' => env('TEAMS_CLIENT_SECRET', env('MICROSOFT_GRAPH_CLIENT_SECRET')),
    'authority' => env('MICROSOFT_GRAPH_AUTHORITY', 'https://login.microsoftonline.com'),
    'graph_endpoint' => env('MICROSOFT_GRAPH_ENDPOINT', 'https://graph.microsoft.com'),
    'timeout' => (int) env('TEAMS_TIMEOUT', 15),

    // activity: alert in the Teams activity feed. chat: message from the app's bot.
    'delivery' => env('TEAMS_DELIVERY', 'activity') === 'chat' ? 'chat' : 'activity',

    // Empty values fall back (an empty line in app.env must not blank out the default).
    'bot' => [
        'app_id' => env('TEAMS_BOT_APP_ID') ?: (env('TEAMS_CLIENT_ID') ?: env('MICROSOFT_GRAPH_CLIENT_ID')),
        'app_secret' => env('TEAMS_BOT_APP_SECRET') ?: (env('TEAMS_CLIENT_SECRET') ?: env('MICROSOFT_GRAPH_CLIENT_SECRET')),
        'tenant_id' => env('TEAMS_BOT_TENANT_ID') ?: (env('TEAMS_TENANT_ID') ?: env('MICROSOFT_GRAPH_TENANT_ID')),
        'service_url' => env('TEAMS_BOT_SERVICE_URL') ?: 'https://smba.trafficmanager.net/teams/',
        // Visitor's sign-in photo in the card, through a signed link valid for photo_link_hours.
        'include_photo' => (bool) env('TEAMS_BOT_INCLUDE_PHOTO', false),
        'photo_link_hours' => 24,
    ],
];
