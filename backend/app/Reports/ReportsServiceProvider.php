<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Reports\Console\SendScheduledReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Emailed reports (docs/fork/admin-dashboard.md, "Scheduled reports"). Times are in the
 * application timezone; nothing is sent until recipients are set in the organisation settings.
 */
class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SendScheduledReport::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:reports:send weekly')
                ->weeklyOn(1, (string) config('organisation.reports.weekly_time', '07:00'))
                ->withoutOverlapping()
                ->name('visitorportal-reports-weekly');

            $schedule->command('visitorportal:reports:send monthly')
                ->monthlyOn(1, (string) config('organisation.reports.monthly_time', '07:00'))
                ->withoutOverlapping()
                ->name('visitorportal-reports-monthly');
        });
    }
}
