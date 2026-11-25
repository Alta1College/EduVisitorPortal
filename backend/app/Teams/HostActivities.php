<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\UserPreferences;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Teams alerts for the host notifications, in the host's language. The activity-feed alert
 * contains only the visitor's display name and the site name. With TEAMS_DELIVERY=chat each
 * alert also carries the bot's chat message (TeamsChatMessage) with more detail.
 */
final class HostActivities
{
    /** Visits listed in the daily reminder's chat message before "and N more". */
    private const REMINDER_LINES = 10;

    public static function visitorArrived(User $host, Visit $visit, Visitor $visitor): TeamsActivity
    {
        $locale = self::locale($host);
        $parameters = ['visitor' => self::visitorName($visitor, $locale), 'site' => self::siteName($visit)];

        return new TeamsActivity(
            TeamsActivity::VISITOR_ARRIVED,
            __('Besucher angekommen', [], $locale),
            __(':visitor ist am Standort :site angekommen.', $parameters, $locale),
            $parameters,
            (int) $visit->id,
            TeamsBotMessenger::chatDelivery() ? self::arrivedChat($visit, $visitor, $locale) : null,
        );
    }

    /**
     * @param  Collection<int, Visitor>  $visitors
     */
    public static function visitCreated(User $host, Visit $visit, Collection $visitors): TeamsActivity
    {
        $locale = self::locale($host);
        $first = $visitors->first();
        $name = $first instanceof Visitor ? self::visitorName($first, $locale) : __('Besucher', [], $locale);

        if ($visitors->count() > 1) {
            $name = __(':name und :count weitere', ['name' => $name, 'count' => $visitors->count() - 1], $locale);
        }

        $parameters = ['visitor' => $name, 'site' => self::siteName($visit)];

        return new TeamsActivity(
            TeamsActivity::VISIT_CREATED,
            __('Neuer Besuch für Sie', [], $locale),
            __('Neuer Besuch für Sie: :visitor am Standort :site.', $parameters, $locale),
            $parameters,
            (int) $visit->id,
            TeamsBotMessenger::chatDelivery() ? self::createdChat($visit, $visitors, $parameters, $locale) : null,
        );
    }

    /**
     * @param  Collection<int, Visit>  $visits
     */
    public static function visitReminder(User $host, Collection $visits): TeamsActivity
    {
        $locale = self::locale($host);
        $parameters = ['count' => (string) $visits->count()];
        $preview = __('Heute geplante Besuche: :count', $parameters, $locale);

        return new TeamsActivity(
            TeamsActivity::VISIT_REMINDER,
            __('Ihre Besuche heute', [], $locale),
            $preview,
            $parameters,
            $visits->count() === 1 ? (int) $visits->first()->id : null,
            TeamsBotMessenger::chatDelivery() ? self::reminderChat($visits, $preview, $locale) : null,
        );
    }

    private static function arrivedChat(Visit $visit, Visitor $visitor, string $locale): TeamsChatMessage
    {
        $name = self::visitorName($visitor, $locale);
        $attendance = DB::table('visit_visitor')->where('visit_id', $visit->id)->where('visitor_id', $visitor->id)->first();
        $arrived = $attendance?->checked_in_at !== null ? Carbon::parse($attendance->checked_in_at) : now();
        $photo = self::photoUrl($visit, $visitor);

        return new TeamsChatMessage(
            heading: __('Ihr Besuch ist angekommen', [], $locale),
            summary: __(':visitor ist am Standort :site angekommen.', ['visitor' => $name, 'site' => self::siteName($visit)], $locale),
            facts: [
                [__('Besucher', [], $locale), $name],
                [__('Firma', [], $locale), (string) $visitor->company],
                [__('Besuchertyp', [], $locale), self::visitorTypeName($attendance?->visitor_type_id ?? null)],
                [__('Standort', [], $locale), self::siteName($visit)],
                [__('Angekommen um', [], $locale), self::time($arrived, $visit, $locale)],
            ],
            url: route('portal.visits.show', $visit),
            buttonLabel: __('Im Portal öffnen', [], $locale),
            photoUrl: $photo,
            photoAlt: $photo !== null ? __('Foto von :name', ['name' => $name], $locale) : null,
        );
    }

    /**
     * @param  Collection<int, Visitor>  $visitors
     * @param  array<string, string>  $parameters  visitor, site (as in the activity alert)
     */
    private static function createdChat(Visit $visit, Collection $visitors, array $parameters, string $locale): TeamsChatMessage
    {
        $names = $visitors->map(fn (Visitor $v): string => self::visitorName($v, $locale))->values();
        $companies = $visitors->pluck('company')->filter(fn ($company): bool => filled($company))->unique()->values();
        $first = $visitors->first();
        $typeId = $first instanceof Visitor
            ? DB::table('visit_visitor')->where('visit_id', $visit->id)->where('visitor_id', $first->id)->value('visitor_type_id')
            : null;
        $from = $visit->scheduled_from !== null ? Carbon::parse($visit->scheduled_from) : null;
        $until = $visit->scheduled_until !== null ? Carbon::parse($visit->scheduled_until) : null;

        return new TeamsChatMessage(
            heading: __('Für Sie wurde ein Besuch gebucht', [], $locale),
            summary: __('Neuer Besuch für Sie: :visitor am Standort :site.', $parameters, $locale),
            facts: [
                [__('Besucher', [], $locale), self::list($names, 5)],
                [__('Firma', [], $locale), self::list($companies, 3)],
                [__('Besuchertyp', [], $locale), self::visitorTypeName($typeId)],
                [__('Standort', [], $locale), self::siteName($visit)],
                [__('Datum', [], $locale), $from !== null ? self::date($from, $visit, $locale) : ''],
                [__('Uhrzeit', [], $locale), self::timeRange($from, $until, $visit, $locale)],
            ],
            url: route('portal.visits.show', $visit),
            buttonLabel: __('Im Portal öffnen', [], $locale),
        );
    }

    /**
     * @param  Collection<int, Visit>  $visits
     */
    private static function reminderChat(Collection $visits, string $preview, string $locale): TeamsChatMessage
    {
        $lines = [];

        foreach ($visits->take(self::REMINDER_LINES) as $visit) {
            $visit->loadMissing('visitors');
            $from = $visit->scheduled_from !== null ? Carbon::parse($visit->scheduled_from) : null;
            $until = $visit->scheduled_until !== null ? Carbon::parse($visit->scheduled_until) : null;
            $names = $visit->visitors->map(fn (Visitor $v): string => self::visitorName($v, $locale))->values();

            $lines[] = implode(' · ', array_filter([
                self::timeRange($from, $until, $visit, $locale),
                self::list($names, 3),
                self::siteName($visit),
            ], fn (string $part): bool => $part !== ''));
        }

        if ($visits->count() > self::REMINDER_LINES) {
            $lines[] = __('Und :count weitere Besuche', ['count' => $visits->count() - self::REMINDER_LINES], $locale);
        }

        return new TeamsChatMessage(
            heading: __('Ihre Besuche heute', [], $locale),
            summary: $preview,
            facts: [],
            url: $visits->count() === 1 ? route('portal.visits.show', $visits->first()) : route('overview'),
            buttonLabel: __('Im Portal öffnen', [], $locale),
            lines: $lines,
        );
    }

    /**
     * The photo taken at this sign-in as a signed link that expires (teams.bot.photo_link_hours);
     * only with TEAMS_BOT_INCLUDE_PHOTO. The file path inside the link is encrypted.
     */
    private static function photoUrl(Visit $visit, Visitor $visitor): ?string
    {
        if (! (bool) config('teams.bot.include_photo')) {
            return null;
        }

        $path = VisitResponse::query()
            ->where('visit_id', $visit->id)
            ->where('visitor_id', $visitor->id)
            ->where('field_type', FormField::TYPE_PHOTO)
            ->whereNotNull('file_path')
            ->latest('id')
            ->value('file_path');

        if (! is_string($path) || ! Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->exists($path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'teams.visitor-photo',
            now()->addHours(max(1, (int) config('teams.bot.photo_link_hours', 24))),
            ['photo' => Crypt::encryptString($path)],
        );
    }

    /**
     * @param  Collection<int, string>  $items
     */
    private static function list(Collection $items, int $max): string
    {
        return $items->take($max)->implode(', ').($items->count() > $max ? ' …' : '');
    }

    private static function visitorTypeName(mixed $id): string
    {
        return $id !== null ? (string) (VisitorType::query()->whereKey($id)->value('name') ?? '') : '';
    }

    private static function time(CarbonInterface $moment, Visit $visit, string $locale): string
    {
        return $moment->copy()->timezone(self::timezone($visit))->locale($locale)->format('H:i');
    }

    private static function date(CarbonInterface $moment, Visit $visit, string $locale): string
    {
        return $moment->copy()->timezone(self::timezone($visit))->locale($locale)->translatedFormat('l, j F Y');
    }

    private static function timeRange(?CarbonInterface $from, ?CarbonInterface $until, Visit $visit, string $locale): string
    {
        if ($from === null) {
            return '';
        }

        return self::time($from, $visit, $locale).($until !== null ? '–'.self::time($until, $visit, $locale) : '');
    }

    private static function timezone(Visit $visit): string
    {
        return (string) ($visit->loadMissing('site')->site?->timezone ?: config('app.timezone'));
    }

    private static function locale(User $user): string
    {
        return UserPreferences::normalizeLocale($user->locale) ?? (string) config('app.locale');
    }

    private static function visitorName(Visitor $visitor, string $locale): string
    {
        $name = trim(($visitor->first_name ?? '').' '.($visitor->name ?? ''));

        return $name !== '' ? $name : __('Besucher', [], $locale);
    }

    private static function siteName(Visit $visit): string
    {
        return (string) ($visit->loadMissing('site')->site?->name ?? '');
    }
}
