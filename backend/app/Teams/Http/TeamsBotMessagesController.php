<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams\Http;

use Illuminate\Http\Response;

/**
 * The Azure Bot's messaging endpoint (POST /teams/bot/messages). Azure requires one, and Teams
 * posts to it when a user installs the app, opens the bot's chat or types in it.
 *
 * The bot is notification-only (manifest isNotificationOnly): it never answers and the portal
 * never reads, stores or acts on what is posted here. Because nothing is trusted or used, the
 * Bot Framework token is not validated either; the endpoint only acknowledges with an empty
 * 200. It runs outside the web middleware group (no session, cookies or CSRF) and is throttled.
 */
class TeamsBotMessagesController
{
    public function __invoke(): Response
    {
        return response('', 200);
    }
}
