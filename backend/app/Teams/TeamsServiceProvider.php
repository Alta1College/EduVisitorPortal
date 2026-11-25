<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use App\Teams\Console\BuildTeamsAppPackage;
use App\Teams\Http\TeamsBotMessagesController;
use App\Teams\Http\TeamsTabController;
use App\Teams\Http\TeamsVisitorPhotoController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Microsoft Teams notifications for hosts (docs/fork/teams-notifications.md): the Teams app's
 * tab page, the bot's messaging endpoint and photo links, and the package command. The channel
 * itself is App\Teams\TeamsActivityChannel, chosen by App\Notifications\HostNotificationChannels.
 */
class TeamsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('teams-tab', fn (Request $request) => Limit::perMinute(60)->by('teams-tab|'.$request->ip()));

        // Anonymous, shows no data; the button opens the signed-in portal in the browser.
        Route::middleware(['web', 'throttle:teams-tab'])
            ->get('/teams/tab', TeamsTabController::class)
            ->name('teams.tab');

        RateLimiter::for('teams-bot', fn (Request $request) => Limit::perMinute(120)->by('teams-bot|'.$request->path().'|'.$request->ip()));

        // Outside the web group on purpose: no session, cookies or CSRF. The bot is
        // notification-only; the endpoint acknowledges and ignores whatever Teams posts.
        Route::middleware('throttle:teams-bot')
            ->post('/teams/bot/messages', TeamsBotMessagesController::class)
            ->name('teams.bot.messages');

        // Visitor photo in the bot's card: temporary signed link, encrypted path, no session.
        Route::middleware(['signed', 'throttle:teams-bot'])
            ->get('/teams/visitor-photo', TeamsVisitorPhotoController::class)
            ->name('teams.visitor-photo');

        if ($this->app->runningInConsole()) {
            $this->commands([BuildTeamsAppPackage::class]);
        }
    }
}
