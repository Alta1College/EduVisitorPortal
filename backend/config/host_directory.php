<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Optional Microsoft Entra host sync (docs/fork/phase-10-entra-hosts-and-deployment.md).
 * Members of each site's Entra group(s) (setting "Entra host groups") become local host
 * accounts at that site. Visitors keep choosing hosts from the local database, so sign-in keeps
 * working when Microsoft is unreachable. Staff then sign in with OIDC SSO
 * (OIDC_PROVISIONING_MODE=invited_only links them by verified email).
 *
 * Credentials default to the Microsoft Graph mail app (MICROSOFT_GRAPH_*), which then needs the
 * GroupMember.Read.All and User.Read.All application permissions as well.
 */
return [
    'entra' => [
        'enabled' => (bool) env('ENTRA_HOST_SYNC_ENABLED', false),
        'tenant_id' => env('ENTRA_HOST_SYNC_TENANT_ID', env('MICROSOFT_GRAPH_TENANT_ID')),
        'client_id' => env('ENTRA_HOST_SYNC_CLIENT_ID', env('MICROSOFT_GRAPH_CLIENT_ID')),
        'client_secret' => env('ENTRA_HOST_SYNC_CLIENT_SECRET', env('MICROSOFT_GRAPH_CLIENT_SECRET')),
        'authority' => env('MICROSOFT_GRAPH_AUTHORITY', 'https://login.microsoftonline.com'),
        'graph_endpoint' => env('MICROSOFT_GRAPH_ENDPOINT', 'https://graph.microsoft.com'),
        'timeout' => (int) env('ENTRA_HOST_SYNC_TIMEOUT', 30),
        // Role given to accounts the sync creates (upstream roles: user, manager, receptionist, admin).
        'role' => env('ENTRA_HOST_SYNC_ROLE', 'user'),
        // Cron expression; hourly at quarter past by default.
        'schedule' => env('ENTRA_HOST_SYNC_CRON', '15 * * * *'),
    ],
];
