<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports\Console;

use App\Reports\Reports;
use Illuminate\Console\Command;

/**
 * Sends the weekly summary or the monthly compliance report (scheduled; can be run by hand).
 */
class SendScheduledReport extends Command
{
    protected $signature = 'visitorportal:reports:send
        {report : weekly or monthly}
        {--dry-run : Only list who would get which sites}';

    protected $description = 'Email the weekly summary or the monthly compliance report to the configured recipients';

    public function handle(Reports $reports): int
    {
        $report = (string) $this->argument('report');

        if (! in_array($report, [Reports::WEEKLY, Reports::MONTHLY], true)) {
            $this->error('Report must be "weekly" or "monthly".');

            return self::INVALID;
        }

        $sent = $reports->sendScheduled($report, dryRun: (bool) $this->option('dry-run'));

        if ($sent === []) {
            $this->info('No recipients configured; nothing sent.');

            return self::SUCCESS;
        }

        foreach ($sent as $group) {
            // Count only: addresses stay out of logs.
            $this->info(sprintf('%s: %d recipient(s) for %s', $this->option('dry-run') ? 'Would send' : 'Sent', count($group['emails']), implode(', ', $group['sites'])));
        }

        return self::SUCCESS;
    }
}
