<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Audit\AuditLogger;
use App\Dashboard\DashboardScope;
use App\Emergency\AutoSignOut;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manual CSV exports for audits: attendances (visits list) and visitors with their sign-ins
 * (visitors list), for a date range and optionally one site. Only for users who may see every
 * visit; each export is recorded in the audit log.
 */
class Exports
{
    /** Longest range one export may cover. */
    public const MAX_DAYS = 366;

    public static function visitsAction(): Action
    {
        return self::action('exportVisits', 'visits', __('Anmeldungen exportieren'));
    }

    public static function visitorsAction(): Action
    {
        return self::action('exportVisitors', 'visitors', __('Besucher exportieren'));
    }

    public static function allowed(?User $user): bool
    {
        return $user !== null && $user->can('ViewAny:Visit');
    }

    /**
     * CSV of attendances (sign-ins) in the range.
     *
     * @param  list<int>|null  $siteIds  null = every site
     */
    public function visits(Carbon $from, Carbon $until, ?array $siteIds): string
    {
        $auto = __('Automatisch');
        $confidential = __('Vertraulich');

        $rows = $this->attendances($from, $until, $siteIds)
            ->leftJoin('sites', 'sites.id', '=', 'visits.site_id')
            ->leftJoin('visitor_types', 'visitor_types.id', '=', 'visit_visitor.visitor_type_id')
            ->leftJoin('users as hosts', 'hosts.id', '=', 'visits.host_user_id')
            ->orderBy('visit_visitor.checked_in_at')
            ->select([
                'visit_visitor.checked_in_at', 'visit_visitor.checked_out_at', 'visit_visitor.checked_out_via',
                'sites.name as site', 'visitors.first_name', 'visitors.name as last_name', 'visitors.company',
                'visitor_types.name as type', 'hosts.first_name as host_first', 'hosts.name as host_last',
                'visits.title', 'visits.is_confidential',
            ])
            ->cursor()
            ->map(function (object $row) use ($auto, $confidential): array {
                $in = Carbon::parse((string) $row->checked_in_at);
                $out = $row->checked_out_at !== null ? Carbon::parse((string) $row->checked_out_at) : null;

                return [
                    $in->toDateString(), $in->format('H:i'), $out?->format('Y-m-d H:i'),
                    $row->checked_out_via === AutoSignOut::CHANNEL ? $auto : '',
                    $row->site, $row->first_name, $row->last_name, $row->company, $row->type,
                    trim(($row->host_first ?? '').' '.($row->host_last ?? '')),
                    $row->is_confidential ? $confidential : $row->title,
                ];
            });

        return Csv::build([
            __('Datum'), __('Angemeldet'), __('Abgemeldet'), __('Abmeldung'), __('Standort'), __('Vorname'), __('Nachname'),
            __('Firma'), __('Besuchertyp'), __('Gastgeber'), __('Besuch'),
        ], $rows);
    }

    /**
     * CSV of the people who signed in during the range, with how often and when.
     *
     * @param  list<int>|null  $siteIds  null = every site
     */
    public function visitors(Carbon $from, Carbon $until, ?array $siteIds): string
    {
        $rows = $this->attendances($from, $until, $siteIds)
            ->groupBy('visitors.id', 'visitors.first_name', 'visitors.name', 'visitors.company', 'visitors.email', 'visitors.phone')
            ->orderBy('visitors.name')
            ->orderBy('visitors.first_name')
            ->selectRaw('visitors.first_name, visitors.name as last_name, visitors.company, visitors.email, visitors.phone, count(*) as sign_ins, min(visit_visitor.checked_in_at) as first_at, max(visit_visitor.checked_in_at) as last_at')
            ->cursor()
            ->map(fn (object $row): array => [
                $row->first_name, $row->last_name, $row->company, $row->email, $row->phone, (int) $row->sign_ins,
                Carbon::parse((string) $row->first_at)->format('Y-m-d H:i'), Carbon::parse((string) $row->last_at)->format('Y-m-d H:i'),
            ]);

        return Csv::build([
            __('Vorname'), __('Nachname'), __('Firma'), __('E-Mail'), __('Telefon'), __('Anmeldungen'), __('Erste Anmeldung'), __('Letzte Anmeldung'),
        ], $rows);
    }

    private static function action(string $name, string $kind, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => self::allowed(auth()->user()))
            ->modalHeading($label)
            ->modalDescription(__('CSV-Datei für Prüfungen und Nachweise. Jeder Export wird im Audit-Protokoll vermerkt.'))
            ->modalSubmitActionLabel(__('Herunterladen'))
            ->schema([
                DatePicker::make('from')->label(__('Von'))->required()->default(fn (): string => now()->subDays(30)->toDateString())->native(false),
                DatePicker::make('until')->label(__('Bis'))->required()->default(fn (): string => now()->toDateString())->afterOrEqual('from')->native(false),
                Select::make('site_id')
                    ->label(__('Standort'))
                    ->placeholder(__('Alle Standorte'))
                    ->options(fn (): array => DashboardScope::siteOptions(auth()->user()))
                    ->native(false),
            ])
            ->action(function (array $data) use ($kind): ?StreamedResponse {
                $user = auth()->user();
                abort_unless($user instanceof User && self::allowed($user), 403);

                $timezone = (string) config('app.timezone');
                $from = Carbon::parse((string) $data['from'], $timezone)->startOfDay();
                $until = Carbon::parse((string) $data['until'], $timezone)->startOfDay()->addDay();

                if ($from->diffInDays($until) > self::MAX_DAYS) {
                    $from = $until->copy()->subDays(self::MAX_DAYS);
                }

                $siteIds = filled($data['site_id'] ?? null) && array_key_exists((int) $data['site_id'], DashboardScope::siteOptions($user))
                    ? [(int) $data['site_id']]
                    : DashboardScope::allowedSiteIds($user);

                $exports = app(self::class);
                $csv = $kind === 'visits' ? $exports->visits($from, $until, $siteIds) : $exports->visitors($from, $until, $siteIds);

                app(AuditLogger::class)->record('report.exported', site: $siteIds !== null && count($siteIds) === 1 ? $siteIds[0] : null, context: [
                    'export' => $kind,
                    'from' => $from->toDateString(),
                    'until' => $until->copy()->subDay()->toDateString(),
                    'sites' => $siteIds,
                ]);

                $file = ($kind === 'visits' ? 'sign-ins' : 'visitors').'-'.$from->toDateString().'-'.$until->copy()->subDay()->toDateString().'.csv';

                return response()->streamDownload(function () use ($csv): void {
                    echo $csv;
                }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function attendances(Carbon $from, Carbon $until, ?array $siteIds): Builder
    {
        return DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->join('visitors', 'visitors.id', '=', 'visit_visitor.visitor_id')
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('visits.site_id', $siteIds))
            ->whereNotNull('visit_visitor.checked_in_at')
            ->where('visit_visitor.checked_in_at', '>=', $from)
            ->where('visit_visitor.checked_in_at', '<', $until);
    }
}
