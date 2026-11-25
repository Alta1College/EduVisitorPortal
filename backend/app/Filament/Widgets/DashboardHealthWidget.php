<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Dashboard\SystemHealth;
use App\Filament\Widgets\Concerns\UsesDashboardScope;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * System health for administrators: kiosks, badge printing, notifications, Entra host sync,
 * queue worker and scheduler, emergency SMS (docs/fork/admin-dashboard.md). Hidden from
 * everyone without the admin or super_admin role.
 */
class DashboardHealthWidget extends Widget
{
    use UsesDashboardScope;

    public const ROLES = ['admin', 'super_admin'];

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-health';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(self::ROLES);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);

        $report = app(SystemHealth::class)->report($this->dashboardScope());
        $timezone = $this->dashboardScope()->timezone;
        $when = fn (?string $at): string => $at === null ? __('nie') : Carbon::parse($at)->timezone($timezone)->locale(app()->getLocale())->diffForHumans();

        $kiosks = $report['kiosks'];
        $printing = $report['printing'];
        $notifications = $report['notifications'];
        $entra = $report['entra'];
        $queue = $report['queue'];
        $sms = $report['sms'];

        $items = [
            [
                'key' => 'kiosks',
                'icon' => 'heroicon-o-device-tablet',
                'label' => __('Kiosks'),
                'status' => $kiosks['status'],
                'value' => $kiosks['total'] === 0
                    ? __('Keine Kiosks eingerichtet')
                    : __(':online von :total erreichbar', ['online' => $kiosks['total'] - count(array_filter($kiosks['devices'], fn (array $d): bool => $d['offline'])), 'total' => $kiosks['total']]),
                'detail' => $kiosks['total'] === 0 ? null : __(':count während der Öffnungszeiten länger als :minutes Minuten nicht erreichbar', ['count' => $kiosks['offline'], 'minutes' => SystemHealth::KIOSK_OFFLINE_MINUTES]),
                'list' => array_map(fn (array $device): array => [
                    'text' => $device['name'].' · '.$device['site'],
                    'meta' => __('Zuletzt aktiv: :when', ['when' => $when($device['last_seen_at'])]),
                    'alert' => $device['offline'] && $device['in_hours'],
                ], $kiosks['devices']),
            ],
            [
                'key' => 'printing',
                'icon' => 'heroicon-o-printer',
                'label' => __('Ausweisdruck'),
                'status' => $printing['status'],
                'value' => $printing['printers'] === 0 && $printing['failed'] === 0
                    ? __('Keine Drucker eingerichtet')
                    : trans_choice('{0} Keine Fehler in 7 Tagen|[1,*] :count Fehler in 7 Tagen', $printing['failed'], ['count' => $printing['failed']]),
                'detail' => $printing['failing_printers'] > 0
                    ? __(':count Drucker mit Fehler beim letzten Druck', ['count' => $printing['failing_printers']])
                    : null,
                'list' => [],
            ],
            [
                'key' => 'notifications',
                'icon' => 'heroicon-o-envelope',
                'label' => __('Benachrichtigungen'),
                'status' => $notifications['status'],
                'value' => trans_choice('{0} Keine fehlgeschlagenen in 7 Tagen|[1,*] :count fehlgeschlagen in 7 Tagen', $notifications['failed'], ['count' => $notifications['failed']]),
                'detail' => __('E-Mail und Teams aus der Warteschlange; andere fehlgeschlagene Aufträge: :count', ['count' => $notifications['other_failed']]),
                'list' => [],
            ],
            [
                'key' => 'entra',
                'icon' => 'heroicon-o-user-group',
                'label' => __('Entra-Gastgebersynchronisierung'),
                'status' => $entra['status'],
                'value' => match (true) {
                    ! $entra['enabled'] => __('Ausgeschaltet'),
                    $entra['last_at'] === null => __('Noch nicht gelaufen'),
                    $entra['succeeded'] => __('Erfolgreich · :when', ['when' => $when($entra['last_at'])]),
                    default => __('Fehlgeschlagen · :when', ['when' => $when($entra['last_at'])]),
                },
                'detail' => $entra['counts'] !== []
                    ? __(':created neu, :linked verknüpft, :updated aktualisiert, :deactivated deaktiviert', $entra['counts'])
                    : null,
                'list' => [],
            ],
            [
                'key' => 'queue',
                'icon' => 'heroicon-o-queue-list',
                'label' => __('Warteschlange'),
                'status' => $queue['status'],
                'value' => $queue['queue'] ? __('Warteschlange läuft') : __('Warteschlange läuft nicht'),
                'detail' => $queue['scheduler'] ? __('Zeitplaner läuft') : __('Zeitplaner läuft nicht'),
                'list' => [],
            ],
            [
                'key' => 'sms',
                'icon' => 'heroicon-o-device-phone-mobile',
                'label' => __('Notfall-SMS (MessagePort)'),
                'status' => $sms['status'],
                'value' => match (true) {
                    ! $sms['enabled'] => __('Ausgeschaltet'),
                    ! $sms['configured'] => __('Nicht eingerichtet'),
                    ! $sms['webhook'] => __('Eingerichtet, ohne Webhook-Geheimnis für Zustellberichte'),
                    default => __('Eingerichtet'),
                },
                'detail' => __('Letzter Zustellbericht: :when', ['when' => $when($sms['last_receipt_at'])]),
                'list' => [],
            ],
        ];

        return ['items' => $items];
    }
}
