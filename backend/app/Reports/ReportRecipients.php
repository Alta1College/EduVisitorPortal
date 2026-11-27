<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use App\Models\Site;
use App\Settings\SettingsRepository;
use Illuminate\Support\Collection;

/**
 * Who gets a report. Each site has a recipient list (organisation setting, which a site can
 * override); sites with the same list share one report, so someone who covers every site gets
 * one email, not one per site. Empty list: no report.
 *
 * On a site, an empty field follows the organisation; "none" stops the report for that site
 * (it parses to no addresses but, unlike empty, is stored as the site's own value).
 */
class ReportRecipients
{
    public const WEEKLY = 'organisation.reports.weekly_recipients';

    public const MONTHLY = 'organisation.reports.monthly_recipients';

    public const ROLL_CALL = 'organisation.reports.roll_call_recipients';

    /** Site value meaning "no report for this site". */
    public const NONE = 'none';

    /** Comma- or semicolon-separated email addresses, or "none". */
    public const LIST_PATTERN = '/^\s*(?:none|[^@\s,;]+@[^@\s,;]+\.[^@\s,;]+(\s*[,;]\s*[^@\s,;]+@[^@\s,;]+\.[^@\s,;]+)*\s*[,;]?)\s*$/i';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return list<array{emails: list<string>, sites: Collection<int, Site>}>
     */
    public function groups(string $settingKey): array
    {
        $groups = [];

        foreach (Site::query()->where('is_active', true)->orderBy('name')->get() as $site) {
            $emails = $this->forSite($site, $settingKey);

            if ($emails === []) {
                continue;
            }

            $key = implode(',', $emails);
            $groups[$key] ??= ['emails' => $emails, 'sites' => collect()];
            $groups[$key]['sites']->push($site);
        }

        return array_values($groups);
    }

    /**
     * @return list<string>
     */
    public function forSite(Site $site, string $settingKey): array
    {
        return self::parse((string) ($this->settings->forSite($site)->get($settingKey) ?? ''));
    }

    /**
     * @return list<string> lower case, unique, sorted
     */
    public static function parse(string $value): array
    {
        $emails = array_filter(array_map(
            fn (string $email): string => mb_strtolower(trim($email)),
            preg_split('/[,;]/', $value) ?: [],
        ), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        $emails = array_values(array_unique($emails));
        sort($emails);

        return $emails;
    }
}
