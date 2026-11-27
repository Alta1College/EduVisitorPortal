<!--
SPDX-FileCopyrightText: 2026 Simon Grant
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Admin dashboard

The admin panel start page (`/admin`, `App\Filament\Pages\Dashboard`) shows a getting-started
panel and live tiles for the sites picked in the filter.

## Getting started

Five steps with links to the admin pages the user may open: sites and branding; visitor types,
questions and policies; kiosks, QR sign-in and badge printers; hosts (users, roles, Entra groups in
site settings); notifications, Teams and emergency messages. **Don't show again** stores
`users.dashboard_welcome_dismissed_at` for that user; **Show getting started** in the page header
clears it.

## Site filter

"All sites" or one site. Users with `ViewAny:Visit` (admins) may pick any active site. Anyone else
is limited to their assigned sites (`User::assignedSiteIds()`); today only admins can open the
panel, but the limit is enforced in `App\Dashboard\DashboardScope` for every tile. "Today" is the
selected site's day; with several sites it is the installation timezone's day.

## Tiles

| Section | Tile | Meaning |
|---|---|---|
| Now (refreshes every 60 s) | On site now | Signed in and not signed out, whatever the visit's state (same rule as the on-site register); breakdown by visitor type. Links to *On site now* with `Run:RollCall`. |
| | Credentials not cleared on site | People on site whose visitor type asks for a credential that is missing, unchecked, expired, invalid or due for a re-check (`CredentialRegister::onSiteAttention`, per site). Red when above 0. Links to *Credentials* with `Check:Credentials`. |
| | Still signed in from earlier days | Signed in before today and not signed out. |
| Today (5 min) | Arrivals today | Sign-ins today, compared with the same weekday last week up to the same time of day. |
| | Expected vs arrived | Visitors on pre-booked (not walk-in, not cancelled or draft) visits scheduled today, and how many of them have signed in. |
| | Next 7 days | Planned pre-booked visits from tomorrow for seven days, with their number of visitors. |
| Credentials and policies (5 min) | Not checked / Expiring soon / Not valid | Credential records of active types: unchecked; valid and expiring within 30 days; checked invalid or past expiry. With a site filter only people who have visited that site count. |
| | Policies recently changed | Active policies whose current version was published in the last 30 days. Visitors accept the current version at every sign-in, so there is no "due for re-acceptance" list to show. |
| Emergency readiness (5 min) | One tile per site | Last drill or emergency: date, how long ago, whether everyone was accounted for (or still running), SMS delivered/failed. Amber when the last **drill** is older than the threshold, or there has never been one ("No drill recorded"). |

## Trends

Below the tiles, for the sites in the filter. Days and hours are the selected site's local time;
with several sites, the installation timezone's (stored times are shifted by the UTC offset
difference, split where either timezone changes for daylight saving). The period is the last 30
or 90 days, today included (the select on each tile; 30 by default). `App\Dashboard\DashboardTrends`.

| Tile | Definition |
|---|---|
| Arrivals per day | Bar chart of sign-ins (`visit_visitor.checked_in_at`) per day, stacked by the attendance's visitor type ("No visitor type" when none). Colours are the panel's theme colours (primary, info, success, warning, danger, gray; lighter shades after six types). |
| Busiest hours | Grid of weekday × hour, each cell the number of sign-ins in the period, shaded in five steps of the primary colour relative to the busiest cell. 07:00–18:00 and Monday–Friday always show; earlier/later hours and the weekend only when someone signed in then. Each cell has the count as a tooltip and for screen readers. |
| Visitors in the last 30 days | Fixed 30 days, compared with the 30 days before (stats tiles have no period select). **Returning visitors**: share of the distinct visitors signed in during the period whose first sign-in at the sites in scope was before the period started. **Average visit length**: mean of `checked_out_at − checked_in_at` for sign-ins in the period that are signed out, excluding automatic sign-outs (`checked_out_via = 'auto'`) and anything over 24 hours. |
| Top companies | Ten visitor companies with the most visits (distinct visits with at least one sign-in from that company) in the period. Names are compared trimmed and case-insensitively; blank companies are ignored. Counts only, no links. |

Cached 10 minutes per tile, scope and period.

## System health

Only users with the `admin` or `super_admin` role see this section (`DashboardHealthWidget::canView`).
Each item is *OK* (green), *Warning* (amber), *Problem* (red) or *Off* (grey). Cached 60 seconds,
redrawn every minute. It shows counts, times and device names only: never secrets, tokens, phone
numbers, e-mail addresses or error texts. `App\Dashboard\SystemHealth`.

| Item | Source | Status |
|---|---|---|
| Kiosks | Enrolled, not revoked kiosks at active sites in scope, with `kiosks.last_seen_at`. The kiosk screen polls every 5 minutes and `KioskDevices::fromRequest()` stores the time at most every 5 minutes, so a working kiosk is at most ~10 minutes behind; nothing new had to be recorded. Offline = not seen for 15 minutes. | Problem when a kiosk is offline during its site's kiosk hours; Off when there are no kiosks. |
| Badge printing | Audit events `badge.print_failed` in the last 7 days at the sites in scope (written by `BadgePrinting::recordFailure` for queued and test prints), plus enabled printers whose last attempt failed (`last_failed_at` after `last_printed_at`). | Problem when a printer's last attempt failed; Warning when there were failures; Off without printers. |
| Notifications | `failed_jobs` of the last 7 days grouped by the payload's `displayName`: notification and mail jobs (`App\Notifications\…`, `App\Mail\…`, `App\Emergency\Broadcast\…`, `App\Teams\…`, Laravel mail/notification jobs). Other failed jobs are shown as a count. Teams messages Microsoft refuses outright (4xx) are only logged (`Teams notification not delivered.`), so they are not counted. | Warning when any failed. Global (jobs carry no site). |
| Entra host sync | Latest audit event `host_directory.synced` or `host_directory.sync_failed` (already written by `EntraHostSync::run`): time, result and the created/linked/updated/deactivated counts. The error text is not shown. | Off when `ENTRA_HOST_SYNC_ENABLED` is false; Warning when it has never run or the last success is over 24 hours old; Problem when the last run failed. |
| Queue | `OperationalHeartbeat::queueIsFresh()` and `schedulerIsFresh()` (as `visitorportal:health`). | Problem when either is stale. |
| Emergency SMS (MessagePort) | `emergency.sms_enabled`, `MessagePortGateway::configured()` and whether a webhook secret is set (booleans only), and the newest `updated_at` of an SMS `emergency_notifications` row in status *sent* or *delivered* (statuses only the MessagePort webhook sets; a failed receipt cannot be told apart from a refused send, so it is not used). | Off when SMS is switched off; Problem when on but not configured; Warning without a webhook secret. |

### Kiosk hours setting

`organisation.visitor_experience.kiosk_hours` (Organisation settings → Visitor experience, "Kiosk
opening hours"; env `KIOSK_HOURS`, default `07:00-18:00`): `HH:MM-HH:MM`, Monday to Friday in the
site's timezone, or `off` to never report offline kiosks at that site. Sites can override it.

## Drill warning setting

`organisation.emergency.drill_warning_days` (Organisation settings → Notifications, "Warn when no
drill for this many days"; default 90, env `EMERGENCY_DRILL_WARNING_DAYS`). Sites can override it in
their site settings.

## Performance

Each tile runs a few aggregate queries (counts and `group by`, never all rows) and caches the
numbers with `Cache::remember`: 60 s for *Now* and system health, 10 minutes for trends (the key also
holds the period), 5 minutes for the rest. The cache key is the set
of sites in scope plus the day, so users with the same scope share entries. Labels are translated
when drawn, not cached. A fork migration adds indexes on `visit_visitor (checked_out_at,
checked_in_at)`, `visit_visitor (checked_in_at)` and `visits (site_id, scheduled_from)`. Tiles
other than the getting-started panel load lazily after the page.

## Scheduled reports and exports (PR 3)

Code: `backend/app/Reports/*`, views `resources/views/reports/*`, mail `resources/views/mail/reports/report.blade.php`.

| Report | When | Contents | Names? |
|---|---|---|---|
| Weekly summary | Mondays 07:00 (`REPORTS_WEEKLY_TIME`) | Previous Monday–Sunday: sign-ins by day, site and visitor type, busiest hours, average visit length, automatic sign-outs, people still signed in, credentials needing action among that week's visitors, roll calls, top companies | No |
| Monthly compliance | 1st of the month 07:00 (`REPORTS_MONTHLY_TIME`) | Previous calendar month: credential states and the list of people whose credential needs action or expires soon (up to 500), roll calls with outcomes, last drill per site against its warning days, current policy versions, data-retention runs | Yes (credential list) |
| Roll-call record | Queued when a roll call (drill or emergency) is ended | Everyone on the list with their final status, who started and ended it, emergency message delivery counts. Safety notes (e.g. medical) are left out | Yes |

- Each email has a short summary, the full report as an A4 PDF (Gotenberg) and the figures as CSV. If Gotenberg fails, the email still goes out with the CSV and says so.
- **Recipients:** Admin → Organisation settings → **Reports**: comma-separated addresses per report. Each site can override them (site settings): an empty site field follows the organisation, and `none` switches that report off for the site. Sites with the same recipients share one report; an empty organisation list sends nothing (the default). Addresses and times are checked when the settings are saved.
- Times use the application timezone. Run by hand: `php artisan visitorportal:reports:send weekly|monthly [--dry-run]` (the dry run lists recipient counts and sites only).
- Every report sent is audited as `report.sent` (report, sites, number of recipients).

**Manual export:** the Visits and Visitors lists in the admin area have **Export sign-ins** and **Export visitors** buttons (date range up to 366 days, optional site). Only users who may see every visit (admins) see them; each export is audited as `report.exported`. CSV cells that a spreadsheet would treat as a formula are prefixed with an apostrophe.
