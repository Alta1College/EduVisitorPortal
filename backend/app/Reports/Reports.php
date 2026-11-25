<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Audit\AuditLogger;
use App\Credentials\Livewire\CredentialsPage;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Models\Site;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Builds and sends the emailed reports: weekly summary, monthly compliance, and the record of a
 * roll call once it ends (docs/fork/admin-dashboard.md).
 */
class Reports
{
    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public function __construct(
        private readonly ReportData $data,
        private readonly ReportPdf $pdf,
        private readonly ReportRecipients $recipients,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Send a scheduled report to every recipient group.
     *
     * @return list<array{emails: list<string>, sites: list<string>}> what was sent
     */
    public function sendScheduled(string $report, ?Carbon $now = null, bool $dryRun = false): array
    {
        $sent = [];
        $setting = $report === self::WEEKLY ? ReportRecipients::WEEKLY : ReportRecipients::MONTHLY;

        foreach ($this->recipients->groups($setting) as $group) {
            $sent[] = ['emails' => $group['emails'], 'sites' => $group['sites']->pluck('name')->all()];

            if ($dryRun) {
                continue;
            }

            $mail = $report === self::WEEKLY ? $this->weekly($group['sites'], $now) : $this->monthly($group['sites'], $now);
            Mail::to($group['emails'])->send($mail);

            $this->audit->record('report.sent', context: [
                'report' => $report,
                'sites' => $group['sites']->map(fn (Site $site): int => (int) $site->getKey())->all(),
                'recipients' => count($group['emails']),
                'pdf' => ! $mail->pdfMissing,
            ]);
        }

        return $sent;
    }

    /**
     * Email the record of an ended roll call to the site's roll-call recipients.
     */
    public function sendRollCall(RollCall $rollCall): bool
    {
        $rollCall->loadMissing('site');

        if ($rollCall->isOpen() || $rollCall->site === null) {
            return false;
        }

        $emails = $this->recipients->forSite($rollCall->site, ReportRecipients::ROLL_CALL);

        if ($emails === []) {
            return false;
        }

        $mail = $this->rollCall($rollCall);
        Mail::to($emails)->send($mail);

        $this->audit->record('report.sent', subject: $rollCall, site: $rollCall->site_id, context: [
            'report' => 'roll_call',
            'recipients' => count($emails),
            'pdf' => ! $mail->pdfMissing,
        ]);

        return true;
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function weekly(Collection $sites, ?Carbon $now = null): ReportMail
    {
        $timezone = $this->timezone($sites);
        [$from, $until] = ReportData::lastWeek($timezone, $now);
        $data = $this->data->weekly($sites, $from, $until, $timezone);
        $period = $this->period($from, $until);
        $title = __('Wochenbericht Besucher');

        $lines = [
            __('Anmeldungen: :count (:visitors Personen)', ['count' => $data['arrivals'], 'visitors' => $data['visitors']]),
            __('Automatisch abgemeldet (Abmeldung vergessen): :count', ['count' => $data['auto_sign_outs']]),
            __('Nachweise mit Handlungsbedarf bei Besuchern dieser Woche: :count', ['count' => $data['credential_issues']]),
            __('Räumungsübungen und Notfälle: :count', ['count' => count($data['roll_calls'])]),
        ];

        return $this->mail($title, $period, $lines, 'reports.weekly', [
            'title' => $title, 'period' => $period, 'sites' => $sites->pluck('name')->all(), 'data' => $data,
            'weekdays' => self::weekdays(), 'kinds' => RollCall::kinds(),
        ], 'weekly', $from, [
            [__('Datum'), __('Standort'), __('Besuchertyp'), __('Anmeldungen')],
            $data['csv'],
        ]);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function monthly(Collection $sites, ?Carbon $now = null): ReportMail
    {
        $timezone = $this->timezone($sites);
        [$from, $until] = ReportData::lastMonth($timezone, $now);
        $data = $this->data->monthly($sites, $from, $until, $timezone);
        $period = $this->period($from, $until);
        $title = __('Monatsbericht Nachweise und Sicherheit');
        $states = self::stateLabels();
        $attention = $data['credential_list_total'];

        $lines = [
            __('Nachweise im Monat geprüft: :count', ['count' => $data['credentials_checked']]),
            __('Nachweise mit Handlungsbedarf oder bald ablaufend: :count', ['count' => $attention]),
            __('Räumungsübungen und Notfälle: :count', ['count' => count($data['roll_calls'])]),
            __('Standorte mit überfälliger Räumungsübung: :count', ['count' => collect($data['drills'])->where('overdue', true)->count()]),
        ];

        return $this->mail($title, $period, $lines, 'reports.monthly', [
            'title' => $title, 'period' => $period, 'sites' => $sites->pluck('name')->all(), 'data' => $data,
            'states' => $states, 'kinds' => RollCall::kinds(),
        ], 'monthly', $from, [
            [__('Name'), __('Firma'), __('Nachweis'), __('Status'), __('Gültig bis'), __('Zuletzt geprüft')],
            array_map(fn (array $row): array => [
                $row['name'], $row['company'], $row['type'], $states[$row['state']] ?? $row['state'], $row['expires_on'], $row['checked_at'],
            ], $data['credential_list']),
        ]);
    }

    public function rollCall(RollCall $rollCall): ReportMail
    {
        $data = $this->data->rollCall($rollCall);
        $kinds = RollCall::kinds();
        $statuses = RollCallEntry::statusLabels();
        $title = ($kinds[$data['kind']] ?? $data['kind']).' – '.$data['site'];
        $period = $data['started_at']->translatedFormat('j M Y, H:i');
        $counts = $data['counts'];

        $lines = [
            __('Personen auf der Liste: :count', ['count' => array_sum($counts)]),
            __('Anwesend: :here · Gelände verlassen: :left · Nicht bestätigt: :missing', [
                'here' => $counts[RollCallEntry::ACCOUNTED] ?? 0,
                'left' => $counts[RollCallEntry::LEFT_SITE] ?? 0,
                'missing' => $counts[RollCallEntry::UNACCOUNTED] ?? 0,
            ]),
        ];

        return $this->mail($title, $period, $lines, 'reports.roll-call', [
            'title' => $title, 'period' => $period, 'data' => $data, 'statuses' => $statuses,
        ], Str::slug($kinds[$data['kind']] ?? $data['kind']).'-'.Str::slug($data['site']), $data['started_at'], [
            [__('Name'), __('Besuchertyp'), __('Firma'), __('Gastgeber'), __('Status'), __('Geändert um')],
            array_map(fn (array $row): array => [
                $row['name'], $row['type'], $row['company'], $row['host'], $statuses[$row['status']] ?? $row['status'], $row['status_changed_at'],
            ], $data['entries']),
        ]);
    }

    /**
     * @return array<string, string> credential state => label
     */
    public static function stateLabels(): array
    {
        return array_map(fn (array $label): string => $label[0], CredentialsPage::stateLabels());
    }

    /**
     * Short length of time, largest two units: "45m", "3h 5m", "2d 13h" (in the app language).
     */
    public static function duration(Carbon $from, Carbon $until): string
    {
        $seconds = max(0, $until->getTimestamp() - $from->getTimestamp());

        if ($seconds < 60) {
            return '< '.CarbonInterval::minutes(1)->forHumans(['short' => true]);
        }

        return CarbonInterval::seconds($seconds - $seconds % 60)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /**
     * @return array<int, string> ISO weekday => name
     */
    public static function weekdays(): array
    {
        return [1 => __('Montag'), 2 => __('Dienstag'), 3 => __('Mittwoch'), 4 => __('Donnerstag'), 5 => __('Freitag'), 6 => __('Samstag'), 7 => __('Sonntag')];
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $viewData
     * @param  array{0: list<string>, 1: iterable<array<int, mixed>>}  $csv
     */
    private function mail(string $title, string $period, array $lines, string $view, array $viewData, string $name, Carbon $date, array $csv): ReportMail
    {
        $base = $name.'-'.$date->format('Y-m-d');
        $files = [];
        $pdf = $this->pdf->render($view, $viewData);

        if ($pdf !== null) {
            $files[$base.'.pdf'] = [$pdf, 'application/pdf'];
        }

        $files[$base.'.csv'] = [Csv::build($csv[0], $csv[1]), 'text/csv'];

        return new ReportMail($title, $period, $lines, $files, $pdf === null);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function timezone(Collection $sites): string
    {
        return (string) ($sites->first()?->timezone ?: config('app.timezone'));
    }

    private function period(Carbon $from, Carbon $until): string
    {
        return $from->translatedFormat('j M Y').' – '.$until->copy()->subDay()->translatedFormat('j M Y');
    }
}
