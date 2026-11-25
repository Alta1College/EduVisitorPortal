<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Emergency\RollCall;
use App\Filament\Widgets\Concerns\UsesDashboardScope;
use App\Support\ForkPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Last drill or emergency per site (docs/fork/admin-dashboard.md). A site is highlighted when its
 * last drill is older than organisation.emergency.drill_warning_days.
 */
class DashboardSafetyWidget extends StatsOverviewWidget
{
    use UsesDashboardScope;

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = '300s';

    protected function getHeading(): ?string
    {
        return __('Notfallbereitschaft');
    }

    protected function getStats(): array
    {
        $onSiteUrl = $this->dashboardUser()->can(ForkPermissions::RUN_ROLL_CALL) ? route('reception.on-site') : null;
        $stats = [];

        foreach ($this->dashboardStats()->safety($this->dashboardScope()) as $site) {
            $drillAt = $site['last_drill_at'] !== null ? Carbon::parse($site['last_drill_at']) : null;
            $drillOverdue = $drillAt === null || $drillAt->lt(now()->subDays($site['warning_days']));
            $last = $site['last'];

            if ($last === null) {
                $stats[] = Stat::make($site['site_name'], __('Keine Übung erfasst'))
                    ->description(__('Noch keine Übung und kein Notfall an diesem Standort'))
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->url($onSiteUrl);

                continue;
            }

            $startedAt = Carbon::parse($last['started_at'])->timezone($site['timezone']);
            $days = (int) floor($startedAt->copy()->startOfDay()->diffInDays(now($site['timezone'])->startOfDay(), true));

            $lines = [
                (RollCall::kinds()[$last['kind']] ?? $last['kind']).' · '.trans_choice('{0} heute|{1} vor :count Tag|[2,*] vor :count Tagen', $days, ['count' => $days]),
                match (true) {
                    $last['open'] => __('Läuft noch'),
                    $last['unaccounted'] === 0 => __('Alle erfasst'),
                    default => __(':count nicht erfasst', ['count' => $last['unaccounted']]),
                },
            ];

            if ($last['sms_delivered'] > 0 || $last['sms_failed'] > 0) {
                $lines[] = __('SMS: :delivered zugestellt, :failed fehlgeschlagen', ['delivered' => $last['sms_delivered'], 'failed' => $last['sms_failed']]);
            }

            if ($drillOverdue) {
                $lines[] = $drillAt === null
                    ? __('Keine Übung erfasst')
                    : __('Letzte Übung vor mehr als :days Tagen', ['days' => $site['warning_days']]);
            }

            $stats[] = Stat::make($site['site_name'], $startedAt->isoFormat('LL'))
                ->description(implode(' · ', $lines))
                ->icon($drillOverdue ? 'heroicon-o-shield-exclamation' : 'heroicon-o-shield-check')
                ->color($drillOverdue || (! $last['open'] && $last['unaccounted'] > 0) ? 'warning' : 'success')
                ->url($onSiteUrl);
        }

        return $stats;
    }
}
