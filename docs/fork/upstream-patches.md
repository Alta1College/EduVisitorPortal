# Upstream patches

Every modification to a file that exists upstream (P0etInc0de/VisitorPortal) is listed here, so
upstream merges can be reviewed quickly. New files added by the fork are not listed.

| File | Change | Reason | Phase | Offer upstream? |
|---|---|---|---|---|
| `backend/composer.lock` | Symfony components updated to patch releases | Security advisories (see assessment §2) | 1 | Superseded by upstream when it updates |
| `backend/bootstrap/providers.php` | Register `AuditServiceProvider`, `SettingsServiceProvider` (Phase 2) and `VisitorWorkflowServiceProvider` (Phase 3) ahead of the upstream providers | Settings overlay must apply before the admin panel reads `config('branding.*')` | 2, 3 | No |
| `visit_visitor` table (migration, no file edit) | Additive nullable column `visitor_type_id` | Visitor type of each attendance | 3 | No |
| `backend/app/Support/VisitorPortalPermissions.php` | Merge `ForkPermissions::ADMIN` into the admin role (one statement) | Admin role needs the settings and audit permissions | 2 | No |
| `backend/app/Support/UserPreferences.php` | `browserLocale()` falls back to `config('app.locale')` instead of always `en` | Organisation default language | 2 | Yes |
| `backend/app/Models/Site.php` | `Site::default()` timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/app/Filament/Resources/Sites/Schemas/SiteForm.php` | Timezone field default from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/database/factories/SiteFactory.php` | Timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/lang/{en,de,fr,cs}.json` | 121 missing upstream translations appended (two-factor/security screens, validation messages, guest emails, mail subjects) | Untranslated German showed in English UI | fix | Yes |
| `backend/lang/{en,de,fr,cs}.json` | Strings for fork UI appended at the end of each file | Upstream's German-key convention; `LocalizationSourceTest` requires every key in every supported locale | 2 | No (append-only; resolve conflicts by keeping both sides) |
| `backend/app/Mail/{Guest,Host}/*Mail.php` (6 files) | Envelope subjects wrapped in `__()` | Subjects were hard-coded German in every language | fix | Yes |
| `backend/tests/Feature/{Auth/MfaTest,Notification/NotificationTest,Portal/CheckInOutBoardTest,Receptionist/ReceptionAdministerVisitTest}.php` | Assertions use `__()`/`trans_choice()` instead of literal German | Tests had asserted the untranslated text | fix | Yes |
| `backend/resources/views/layouts/{app,guest}.blade.php`, `backend/resources/views/monitor/show.blade.php` | One line after the app stylesheet: `<x-brand-stylesheet />` | Organisation colours in the portal, login and welcome screens | 2 (follow-up) | No |
| `backend/app/Services/VisitActionService.php` | `checkInParticipant` / `checkOutParticipant` accept `?User $actionBy` (null-safe id) | Visitors check themselves in and out on public channels; no fake staff user | 4 | Yes |
| `backend/app/Livewire/Portal/CheckInOutBoard.php` | Walk-in visit creation moved to `App\Services\WalkInVisitService` (behaviour unchanged) | One walk-in implementation for reception and public channels | 4 | Yes |
| `visit_visitor` table (migration, no file edit) | Additive nullable columns `checked_in_via`, `checked_out_via` | Record the channel of each check-in/out | 4 | No |
| `backend/bootstrap/providers.php` | Register `PublicVisitServiceProvider` | Public route group, rate limiters, host directory | 4 | No |
| `visit_visitor` table (migration, no file edit) | Additive nullable column `kiosk_id` | Which kiosk recorded a sign-in | 5 | No |
| `backend/bootstrap/providers.php` | Register `MessagingServiceProvider` and `BadgesServiceProvider` | Microsoft Graph mail; badge printing and reprint route | 7, 8 | No |
| `backend/config/mail.php` | Additional `microsoft-graph` mailer entry | Microsoft 365 mail with OAuth | 7 | Could offer |
| `backend/app/Livewire/Reception/{DashboardPage,AllVisitsPage}.php` | One extra `label_url` key in each participant row | Reception "Label" reprint button when the site has a label printer | 8 | No |
| `backend/resources/views/livewire/reception/partials/dashboard-participant-row.blade.php`, `backend/resources/views/reception/partials/all-visits/participant-row.blade.php` | "Label" form button before upstream's PDF badge button (shown only with `label_url`) | Reprint on the label printer | 8 | No |
| `backend/resources/views/livewire/reception/{dashboard-page,all-visits-page}.blade.php` | `<x-bp.alert />` (upstream component) at the top | Show "Printing on …" after a reprint | 8 | No |
| `kiosks`, `visitor_types` tables (migration, no file edit) | Additive `kiosks.badge_printer_id`, `visitor_types.print_badge` | Which printer a kiosk uses; which types get a badge | 8 | No |
| `backend/bootstrap/providers.php` | Register `EmergencyServiceProvider` | Register, roll calls, automatic sign-out | 9 | No |
| `backend/app/Support/VisitorPortalPermissions.php` | Merge `ForkPermissions::RECEPTIONIST` into the receptionist role (one statement) | Receptionists run roll calls | 9 | No |
| `backend/resources/views/layouts/navigation.blade.php` | "On site now" link (desktop and mobile), shown with `Run:RollCall` | Reach the register and roll calls | 9 | No |
| `backend/lang/{en,de,fr,cs}.json` | Two missing upstream plurals (`:count Stunde…`, `:count Treffer…`) | German showed on the check-in/out board | 9 | Yes |
| `backend/tests/Feature/Portal/CheckInOutBoardTest.php` | Two assertions use `trans_choice()` instead of literal German "Treffer" | The plural is now translated | 9 | Yes |
| `backend/database/migrations/0000_01_01_000000_create_sites_table.php` | Default site timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` (new installs) | 9 | Yes |
| `backend/config/app.php`, `backend/config/branding.php` | Default `APP_NAME`/`BRANDING_NAME` and monitor heading "Edu Visitor Portal" | Product name (still overridable by env) | Rename | No |
| `backend/.env.example`, `docker-compose.{demo,prod,sso-test}.yml` | Default app and branding name "Edu Visitor Portal" | Product name | Rename | No |
| `backend/lang/{en,de,fr,cs}.json` | Product name in the SSO two-factor note (values only) | Product name | Rename | No |
| `README.md` | Rewritten for Edu Visitor Portal: features, screenshots, upstream attribution, second copyright line | Product documentation for the fork | Rename | No |
| `README.de.md` | Removed | The German readme described upstream only and was no longer maintained | Rename | No |
| SPDX headers of changed upstream files | Extra `SPDX-FileCopyrightText: 2026 Simon Grant` line beneath upstream's | Copyright of fork changes | Rename | No |
| `backend/app/Auth/Sso/Facile/FacileOidcAuthenticator.php`, `backend/config/sso.php` | `email_verified` read from a configurable list of claims (`OIDC_EMAIL_VERIFIED_CLAIMS`, default `email_verified`); only `true`/`"true"` counts | Microsoft Entra ID sends `xms_edov`, not `email_verified`, so `invited_only` linking failed | 10 | Could offer |
| `backend/.env.example` | `OIDC_EMAIL_VERIFIED_CLAIMS`, `ENTRA_HOST_SYNC_*`, `ENTRA_HOST_GROUP_IDS` | Document the new options | 10 | No |
| `backend/bootstrap/providers.php` | Register `CredentialsServiceProvider` | Visitor credentials (docs/fork/credentials.md) | Credentials | No |
| `backend/resources/views/layouts/navigation.blade.php` | "Credentials" link (desktop and mobile), shown with `Check:Credentials` | Reach the reception credentials page | Credentials | No |
| `backend/app/Support/VisitorPortalPermissions.php` | (no new edit: `ForkPermissions::ADMIN`/`RECEPTIONIST` now include the credential permissions) | — | Credentials | No |
| `form_fields` table (migration, no file edit) | Additive nullable `credential_type_id` | Link a question to a credential type | Credentials | No |
| `docker-compose.prod.yml` | `MAIL_MAILER` configurable (default `smtp`), `MAIL_HOST`/`MAIL_PORT` optional; app, queue and scheduler also read an optional `app.env` | Microsoft Graph mail and fork settings (Entra sync, visitor defaults) in production | Deploy | Could offer |
| `backend/config/sso.php` | `login_email_placeholder` (env `LOGIN_EMAIL_PLACEHOLDER`, default the upstream example) | Instance-specific example address on the login form | Login | No |
| `backend/resources/views/auth/login.blade.php` | E-mail placeholder reads `sso.login_email_placeholder` | Same | Login | No |
| `backend/.env.example` | `LOGIN_EMAIL_PLACEHOLDER` | Document the option | Login | No |
| `backend/resources/views/profile/recovery-codes.blade.php`, `backend/resources/views/security/mfa/recovery-codes.blade.php` | `<x-recovery-codes-download>` button | Save recovery codes as a .txt file (built in the browser) | Login | No |
| `backend/app/Models/User.php`, `backend/resources/views/filament/sidebar-footer.blade.php` | `HasName::getFilamentName()` returns the full name; sidebar footer uses it; `full_name` trimmed | Admin panel showed only the surname (`name` column) | Fix | Could offer |
| `docker/caddy/Caddyfile`, `docker-compose.caddy.yml` | (fork files) import and mount optional server-only `docker/caddy/sites/*.caddy` | Installation-specific hostnames, e.g. renewing another device's certificate | Deploy | n/a |
| `docker/app/Dockerfile` | `apt-get upgrade` in the base layer | Debian security updates (e.g. Apache) even when the pinned base tag lags | 11 | Could offer |
| `backend/app/Providers/FortifyServiceProvider.php` | `TimeboxLoginAttempt` in the login pipeline | No account enumeration by login timing | 11 | Could offer |
| `backend/app/Actions/Fortify/EnsureUserIsActive.php` | Generic `auth.failed` message | Do not confirm a correct password for a deactivated account | 11 | Could offer |
| `backend/app/Actions/Fortify/ResetUserPassword.php` | Ends all sessions after a reset (`UserSessions`) | Reset logs out an attacker's sessions | 11 | Could offer |
| `backend/app/Http/Controllers/Auth/PasswordResetLinkController.php` | Always "link sent"; per address+IP rate limit | No account enumeration; the `password-reset` limiter was never attached | 11 | Could offer |
| `backend/app/Models/User.php` | Deactivation ends sessions (`UserSessions`) | Former staff lose access at once | 11 | Could offer |
| `backend/bootstrap/app.php`, `backend/app/Providers/Filament/AdminPanelProvider.php` | `LogOutInactiveUsers`; panel also gets `SecurityHeaders` | Deactivated users signed out; headers on `/admin` | 11 | Could offer |
| `backend/app/Providers/AppServiceProvider.php` | Two-factor limit per account; Livewire temporary upload throttle; `URL::forceRootUrl(APP_URL)` in production | Brute force, upload abuse, Host-header link poisoning | 11 | Could offer |
| `backend/config/auth.php`, `backend/config/upload.php`, `backend/phpunit.xml` | `login_minimum_failure_microseconds`, `temporary_uploads_per_minute` (0 µs in tests) | Settings for the above | 11 | Could offer |
| `backend/resources/views/mail/host/guestCheckedIn.blade.php`, `.../visitCreated.blade.php` | Visitor fields through `MarkdownText::escape` | No links injected into host e-mails | 11 | Could offer |
| `backend/composer.lock`, `backend/package-lock.json` | laravel/framework 12.73; follow-redirects 1.16.3 | Advisories | 11 | n/a |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | `resourceEditPageRedirect('index')` | Saving an edit returns to the list | Bug fix | Could offer |
| `backend/app/Notifications/Host/{GuestCheckedInMailNotification,VisitCreated,VisitReminderDaily}.php` | `via()` returns `HostNotificationChannels::for()`; extra `toTeams()` | Host notifications by e-mail and/or Teams (docs/fork/teams-notifications.md) | Teams | No |
| `backend/app/Http/Controllers/Portal/VisitController.php` | `store` calls `VisitCreatedNotifier::notify()` | Upstream's `VisitCreated` notification was never sent; hosts now hear about visits booked for them | Teams | Could offer |
| `backend/app/Http/Controllers/Auth/ProfileController.php`, `backend/app/Http/Requests/ProfileUpdateRequest.php` | Validate and save `notify_by_email` / `notify_by_teams` (kept out of the mass fill) | Per-host notification channels | Teams | No |
| `backend/resources/views/profile/edit.blade.php` | "Notifications" section (fork partial `update-notification-preferences-form`) | Same | Teams | No |
| `backend/app/Http/Middleware/SecurityHeaders.php` | `frame-ancestors` read from the `SecurityHeaders::FRAME_ANCESTORS` request attribute (default `'self'`) | Only the Teams tab page may be framed by Teams | Teams | Could offer |
| `backend/bootstrap/providers.php` | Register `TeamsServiceProvider` | Teams tab route, package command | Teams | No |
| `users` table (migration, no file edit) | Additive nullable `notify_by_email`, `notify_by_teams` | Host's own channel choice (null = organisation default) | Teams | No |
| `backend/.env.example` | `TEAMS_NOTIFICATIONS_ENABLED`, `TEAMS_APP_ID`, optional `TEAMS_*` credentials | Document the options | Teams | No |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | Local initials avatar provider; `AccountWidget` on the start page | No ui-avatars.com (privacy, CSP); simple start page | Low-risk batch | Could offer |
| `backend/app/Filament/Widgets/GithubLinkWidget.php`, `backend/resources/views/filament/widgets/github-link-widget.blade.php`, `backend/config/filament-shield.php` | GitHub link widget removed | Not wanted in the school admin | Low-risk batch | No |
| `backend/config/visitorportal.php`, `backend/app/Filament/Resources/Departments/DepartmentResource.php`, `backend/app/Filament/Resources/Users/{Schemas/UserForm,Tables/UsersTable,Schemas/UserInfolist}.php` | `DEPARTMENTS_ENABLED` (default off) hides departments | Sites and Entra host groups cover schools; data and permissions kept | Low-risk batch | Could offer |
| `docker/app/entrypoint.sh`, `docker/app/php.ini` | Production caches routes and events; opcache does not re-check files | Faster requests; code is fixed in the image | Low-risk batch | Could offer |
| `backend/.env.example` | `TEAMS_DELIVERY`, optional `TEAMS_BOT_*` | Document the Teams bot chat messages | Teams | No |
| `backend/.env.example` | Emergency message and MessagePort variables appended (`EMERGENCY_*`, `MESSAGEPORT_*`) | Document the emergency SMS/e-mail broadcast settings | Emergency broadcast | No |
| `backend/app/Filament/Pages/Dashboard.php` | Site filter form, explicit dashboard tiles (incl. trends and system health), "Show getting started" header action, second copyright line | Admin dashboard (docs/fork/admin-dashboard.md) | Dashboard | No |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | `AccountWidget` placeholder removed | The start page lists its own tiles | Dashboard | No |
| `users` table (migration, no file edit) | Additive nullable `dashboard_welcome_dismissed_at` | Per-user "Don't show again" for the getting-started panel | Dashboard | No |
| `visit_visitor`, `visits` tables (migration, no file edit) | Indexes `(checked_out_at, checked_in_at)`, `(checked_in_at)` and `visits (site_id, scheduled_from)`; no column changes | Fast dashboard counts | Dashboard | Could offer |
| `backend/lang/{en,de,fr,cs}.json` | Dashboard strings appended | Same convention as other fork strings | Dashboard | No |
| `backend/public/favicon.*`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`, `android-chrome-*.png`, `site.webmanifest`, `backend/resources/views/partials/favicons.blade.php` | Edu Visitor Portal badge icon (new `favicon.svg`; PNG/ICO rendered from it); manifest name | Own product icon | Branding | No |
| `backend/public/images/branding/logo-with-text-{light,dark}.svg` | Default logo: badge icon + "Edu Visitor Portal" | Default until a school uploads its own | Branding | No |
| `backend/resources/views/layouts/navigation.blade.php` | Header shows only the logo (larger in the sidebar); the name appears only when no logo is set | "Visitor portal" label next to the logo was truncated and redundant | Branding | Could offer |
| `backend/app/Policies/VisitPolicy.php` | `update`, `delete` and `cancel` refuse a visit with attendance (for every role); new `addNote` ability | Immutable visitor log: an admin could edit and delete attended visits | Visit log | Could offer |
| `backend/app/Policies/VisitorPolicy.php` | `delete` refuses a visitor with attendance | Deleting a visitor cascaded to their attendance rows | Visit log | Could offer |
| `backend/app/Filament/Resources/Visits/Pages/{EditVisit,ViewVisit}.php` | Delete action removed; cancel/restore are fork actions with policy checks and audit; "Add note" action | Same; upstream's cancel/restore actions had no authorisation check | Visit log | Could offer |
| `backend/app/Filament/Resources/Visits/Tables/VisitsTable.php` | Bulk delete replaced by "Cancel selected visits" (skips attended, finished and cancelled visits) | Cancel instead of delete | Visit log | Could offer |
| `backend/app/Filament/Resources/Visits/RelationManagers/VisitorsRelationManager.php` | Read-only once the visit has attendance | No adding/removing visitors on a visit that took place | Visit log | Could offer |
| `backend/app/Filament/Resources/Visitors/Tables/VisitorsTable.php` | Bulk delete authorises each record | Visitors with attendance are skipped instead of failing | Visit log | Could offer |
| `backend/app/Services/RecurringVisitService.php` | Series-wide updates and pruning skip occurrences with attendance | A series edit would otherwise rewrite or delete past attended occurrences | Visit log | Could offer |
| `backend/bootstrap/providers.php` | Register `VisitLogServiceProvider` | Visit/visitor model guards and visitor change audit | Visit log | No |
| `audit_events` table (migration, no file edit) | `BEFORE UPDATE`/`BEFORE DELETE` triggers (fork table) | Database-level append-only audit log | Visit log | No |
| `backend/tests/Feature/AdminPanel/AdminAdministerVisitsTest.php` | `test_admin_can_delete_visits` became `test_admin_cancels_instead_of_deleting_visits` | The delete action was replaced by cancel | Visit log | No |
| `backend/resources/views/layouts/app.blade.php`, `backend/resources/views/layouts/navigation.blade.php` | Page header, sidebar and mobile menus use `bg-base-200` (brand background); menu hover uses `bg-base-100` | Sidebar and header showed white instead of the organisation background colour | Branding | Could offer |
| `backend/bootstrap/providers.php` | Register `ReportsServiceProvider` | Scheduled reports (weekly, monthly, roll-call record) | Reports | No |
| `backend/app/Filament/Resources/Visits/Pages/ListVisits.php`, `backend/app/Filament/Resources/Visitors/Pages/ListVisitors.php` | Header action: CSV export with date range and site (`App\Reports\Exports`) | Exports for audits; admins only, audited | Reports | Could offer |
| `backend/app/Support/AppMfaPolicy.php` | `APP_MFA_REQUIRED_ROLES=*` means every role | Require MFA for all password sign-ins, not only admins | Returning visitors / MFA | Could offer |
| `docs/configuration.md` | Documents `APP_MFA_REQUIRED_ROLES=*` | As above | Returning visitors / MFA | Could offer |
| `backend/app/Services/VisitActionService.php` | Check-in and check-out lock the attendance row (transaction + `lockForUpdate`); checking in again after a sign-out first records the earlier times as `visit.attendance.reopened` | Two staff acting at once could both apply; the earlier sign-out time was lost | Review fixes | Could offer |
| `backend/app/Services/RecurringVisitService.php` | Monthly and yearly occurrences are counted from the series start (`addMonthsNoOverflow(n)`), not step by step | A series starting 31 Jan drifted to the 28th for the rest of the year | Review fixes | Could offer |
| `backend/composer.json`, `backend/composer.lock` | Laravel 13 (`laravel/framework ^13.0`, `laravel/tinker ^3.0`, `phpunit/phpunit ^12.0`); `facile-it/php-openid-client ^1.0` (0.4 pinned `web-token/jwt-library` 3, which cannot coexist with Laravel 13's `brick/math`) | Laravel 12 security support ends ~Feb 2027 | Laravel 13 | Could offer |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | `VerifyCsrfToken` → `PreventRequestForgery` (Laravel 13 name; old one is a deprecated alias) | Laravel 13 | Laravel 13 | Could offer |
| `backend/config/app.php`, `docker-compose.prod.yml`, `docker-compose.demo.yml`, `backend/.env.example`, `backend/.env.production.example` | Default timezone `UTC` (was `Europe/Berlin`) and default locale `en` (was `de`); `.env.production.example` `APP_NAME` is "Edu Visitor Portal" | English-language release; installs set `APP_TIMEZONE` for their own region | Release setup | No |
| `.github/workflows/docker-image.yml`, `.env.demo.example`, `docker-compose.demo.yml`, `docs/deployment.md`, `docs/release-artifacts.md`, `.github/ISSUE_TEMPLATE/config.yml`, `start/stop/update` scripts | Image `ghcr.io/alta1college/eduvisitorportal`, release packages `EduVisitorPortal-demo-<tag>.zip` and `eduvisitorportal-<tag>.zip`, repository links and script messages use the project name. Internal container names are unchanged | Releases are published under this project, not upstream's | Release setup | No |
| `backend/pint.json` | `header_comment` rule removed | It enforces one fixed copyright header, which fork files cannot carry (CLAUDE.md rule 8); CI now checks the SPDX licence line instead | Release setup | No |
| `.github/workflows/laravel.yml` | Licence header check step after Pint | Replaces the removed Pint rule | Release setup | No |
| `.github/workflows/sso-e2e.yml` | Frees runner disk space before building; cancels superseded runs | The Gotenberg pull ran out of disk on GitHub's runner | Release setup | Could offer |
