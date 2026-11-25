<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Emergency\Console\PurgeRollCalls;
use App\Emergency\Console\RunAutoSignOut;
use App\Emergency\Http\MessagePortWebhookController;
use App\Emergency\Http\RollCallController;
use App\Emergency\Livewire\OnSitePage;
use App\Emergency\Livewire\RollCallPage;
use App\Emergency\Sms\MessagePortGateway;
use App\Emergency\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Active visitor register, emergency roll calls, emergency messages and automatic sign-out
 * (docs/fork/phase-9-emergency.md, docs/fork/emergency-broadcast.md).
 */
class EmergencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, MessagePortGateway::class);
    }

    public function boot(): void
    {
        Livewire::component('emergency.on-site-page', OnSitePage::class);
        Livewire::component('emergency.roll-call-page', RollCallPage::class);

        Route::middleware(['web', 'auth', 'verified'])->prefix('reception')->name('reception.')->group(function (): void {
            Route::get('/on-site', [RollCallController::class, 'onSite'])->name('on-site');
            Route::get('/roll-calls/{rollCall}', [RollCallController::class, 'show'])->whereNumber('rollCall')->name('roll-calls.show');
            Route::get('/roll-calls/{rollCall}/print', [RollCallController::class, 'print'])->whereNumber('rollCall')->name('roll-calls.print');
        });

        // SMS delivery receipts (docs/fork/emergency-broadcast.md). Outside the web group on
        // purpose: no session, cookies or CSRF; trusted only through the HMAC signature.
        RateLimiter::for('messageport-webhook', fn (Request $request) => Limit::perMinute(1200)->by('messageport|'.$request->ip()));
        Route::middleware('throttle:messageport-webhook')
            ->post('/webhooks/messageport', MessagePortWebhookController::class)
            ->name('webhooks.messageport');

        if ($this->app->runningInConsole()) {
            $this->commands([RunAutoSignOut::class, PurgeRollCalls::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:auto-sign-out')->everyFiveMinutes()->withoutOverlapping()->name('visitorportal-auto-sign-out');

            if (config('privacy.purge_enabled')) {
                $schedule->command('visitorportal:roll-calls:purge')->dailyAt('03:20')->withoutOverlapping()->name('visitorportal-roll-calls-purge');
            }
        });
    }
}
