# Phase 11: Security validation

Brief §31, Phase 11: before production rollout of the anonymous endpoints, do a code and security
review, vulnerability and dependency scans, a threat-model review and an independent penetration
test. This document records what was done (November 2026), what was fixed, what is still open, and
the scope for the independent test.

## 1. What was reviewed

| Area | How |
|---|---|
| Public surface | `route:list` on production: 151 routes, 34 without login. |
| Anonymous visitor pages (`/kiosk`, `/kiosk/enrol`, `/visit/{token}`, `/leave/{token}`, their Livewire components) | Code review: tokens, device trust, property tampering, data exposure, uploads, XSS, rate limits |
| File serving (`/storage/{path}` GET/PUT, Livewire upload/preview, Filament downloads) | Code review including the framework code that checks signatures |
| Sign-in (local, password reset, two-factor, Microsoft OIDC), sessions, headers | Code review |
| PHP dependencies | `composer audit` against the shipped `composer.lock` |
| JavaScript dependencies | `npm audit` (shipped code and build tools) |
| Container image | Trivy (HIGH/CRITICAL, fixable) on the production image |
| Live site | Response headers, TLS versions, HTTP→HTTPS, listening ports |

## 2. Findings

### Fixed in this phase

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | Critical (image) | Apache 2.4.69 in the image; fixed in 2.4.71. The pinned base tag lags Debian security updates. | `docker/app/Dockerfile` runs `apt-get upgrade` in the base layer. |
| 2 | Medium | Deactivated users (by an admin or the Entra host sync) kept their session and "remember me" cookie. | `LogOutInactiveUsers` middleware on `web` and the admin panel; deactivation rotates the remember token and deletes database sessions (`UserSessions`). |
| 3 | Medium | Password reset said "We can't find a user with that email address", and its rate limiter was never attached. | Always the same "link sent" answer; limited per address and IP in the controller. |
| 4 | Medium | Login response time showed whether an address had an account (no password hash for unknown addresses). | `TimeboxLoginAttempt`: failed logins take at least 1.5 s (`AUTH_LOGIN_MIN_FAILURE_MICROSECONDS`). |
| 5 | Medium | Visitor-typed names went into Markdown e-mails unescaped: `[text](https://…)` became a link in mail from the organisation's address (sign-out link to the visitor, arrival/visit mail to hosts). | `MarkdownText::escape` on visitor fields in the three mail views. |
| 6 | Medium | The visitor sign-in turned every data-URL answer into a temp file of up to 5 MB, for any key, and never deleted them (disk fill). | Only photo and signature questions are decoded; temp files are deleted at the end of the request (`RequestTempFiles`). |
| 7 | Low | Admin panel (`/admin`) responses had no security headers. | `SecurityHeaders` added to the panel middleware. |
| 8 | Low | A password reset did not end the account's other sessions. | `ResetUserPassword` ends all sessions. |
| 9 | Low | Two-factor guesses were limited per account **and IP** only. | Extra limit per account: 10 per 15 minutes. |
| 10 | Low | A deactivated account with the right password got "This account is deactivated". | Generic "credentials do not match" message. |
| 11 | Low | Livewire temporary uploads (reachable from the public pages) allowed 60 per minute per IP. | 15 per minute (`UPLOAD_TEMPORARY_PER_MINUTE`). |
| 12 | Low | E-mail links (password reset, sign-out) could take their host from a forged `Host` / `X-Forwarded-Host` if the app were reached directly. | In production, URLs are generated from `APP_URL` only. |
| 13 | Low | `laravel/framework` 12.70.1 advisory (Concurrency; feature not used). | Updated to 12.73. |
| 14 | Info | `follow-redirects` (build tool only) advisory. | Updated in `package-lock.json`. |

### Open: deferred to risk and compliance (must be resolved before final ship)

| # | Severity | Finding |
|---|---|---|
| A | **High** | **Returning-visitor takeover on the QR page.** `/visit/{token}` is reachable from the internet. Anyone who knows a past visitor's e-mail or mobile number can choose "That's me", type their own details and submit. The victim's record is overwritten (`RegisterArrival` fills typed details), the victim's open sign-in at that site is closed (roll call then shows them as gone), and the victim's saved photo and checked WWCC are reused on the attacker's sign-in and badge. Options: turn returning-visitor recognition off on the QR channel (keep it on the kiosk), or require a one-time code e-mailed to the saved address. |
| B | Medium | The QR lookup reveals whether an e-mail or mobile number has visited, and the first name. Rate-limited per QR link and IP only. Resolved by either option for A. |
| C | Low | Anyone with the QR link can print badges at reception (10 per minute per IP). Options: a per-site limit, or no automatic badge for QR sign-ins. |

### Accepted / informational

- **CSP allows `'unsafe-inline'` and `'unsafe-eval'` for scripts.** Livewire/Alpine and Filament need them; Blade escaping is the real XSS defence. A stricter CSP for the public visitor pages only is possible later.
- **The kiosk shows a returning visitor's details by e-mail or phone** (by design, at reception). The kiosk device cookie is not tied to the school network; a stolen cookie works from anywhere. Network restriction (below) reduces this.
- **`/lang` changes the saved language on a GET request.** Harmless (CSRF can only change a language preference).
- **Livewire photo previews are cached by the browser** (`Cache-Control: public`). Previews only exist on the kiosk while signing in.

### Checked and fine

- QR, sign-out and kiosk enrolment tokens: long random values, stored hashed, single use where they should be, expire, site-bound, checked server-side; Livewire identity properties are `#[Locked]` and option tokens are encrypted and re-checked.
- `/storage/{path}` (GET and PUT) needs a valid signature; no code creates such URLs for private files, so nothing is readable or writable anonymously. Path traversal is rejected.
- Livewire upload and preview URLs are signed; Filament export downloads check ownership.
- Microsoft OIDC: state, nonce and PKCE (S256), tenant-pinned issuer, audience, `email_verified`/`xms_edov`, allowed domains, inactive users rejected, session regenerated.
- No CSRF exemptions; no debug tools in the image; `APP_DEBUG=false`.
- Live site: TLS 1.2 and 1.3 only, HTTP→HTTPS (308), HSTS, CSP, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, cookies `Secure`, `HttpOnly`, `SameSite=Lax`, no `Server` header. VM listens on 22, 80 and 443 only; 8080 is closed. The app is reachable only through Caddy (`TRUSTED_PROXIES=*` is safe because of that).
- Dependencies after this phase: `composer audit` clean, `npm audit` clean; the remaining Trivy findings are kernel headers (`linux-libc-dev`), not used at run time.

### Fixed after phase 11: immutable visitor log

A test administrator reported that they could "edit logs". The audit log was already
append-only, but **visits** (the visitor log) could be edited and deleted in the admin panel and
the staff portal. Fixed (November 2026):

| Path | Before | Now |
|---|---|---|
| Admin panel: edit visit (title, times, status, host, substitute, site, confidentiality, visitors, notes) | Allowed for any visit (`EditAny`) | Forbidden once any visitor has signed in (`VisitPolicy::update`); the page refuses to open and refuses to save |
| Admin panel: delete visit / bulk delete | Allowed, cascading to attendance | Removed. Replaced by **Cancel** and **Cancel selected visits** (unattended visits only, audited `visit.canceled`) |
| Admin panel: cancel / restore actions | No authorisation check | `VisitPolicy::cancel` / `update`; audited |
| Admin panel: visitors on a visit (relation manager, form select) | Add/remove, removing deleted the attendance row | Read-only once the visit has attendance |
| Admin panel: delete visitor (single, bulk) | Cascaded to attendance rows | Refused for visitors with attendance |
| Portal: edit, update, reschedule, cancel, reopen (controller and Livewire page) | Hosts could change attended visits that were still open | Forbidden for attended visits (same policy) |
| Recurring series "this and future"/"all" edits | Rewrote or deleted past attended occurrences | Occurrences with attendance are skipped |
| Any code path (model level) | — | `Visit` updating/deleting guard (`App\VisitLog\VisitLock`): on an attended visit only an appended note, status `completed` and legal-hold fields may change; attended visits, attendance rows (detach) and visitors with attendance cannot be deleted |

**Still possible (live workflow, not history edits):** check-in/out at reception, kiosk and QR
sign-in, self sign-out, automatic sign-out, roll calls, badge printing, and finishing a visit
(status `completed`). These write the attendance pivot directly and are unaffected. The
**retention purge** (`visits:purge-expired`) still deletes old visits with their attendance; it
deletes in bulk without model events.

**Notes:** "Add note" (admin panel, view and edit pages) appends a dated, attributed line to the
visit's notes; existing text cannot be rewritten. Audited as `visit.note_added` with the note text.

**Visitor details** stay editable but every change is audited (`visitor.updated`) with the actor
and old/new values of each changed field. E-mail and phone are stored masked (as in the emergency
message log); free-text notes are recorded as changed without content. Changes made by a
returning visitor on the kiosk or QR page are audited too (no actor).

**Database protection of the audit log:** migration `fork_protect_audit_events_with_triggers`
adds MariaDB/MySQL triggers on `audit_events`: every `UPDATE` is rejected, and `DELETE` is
rejected unless the session variable `@visitorportal_audit_purge = 1` is set, which only
`visitorportal:audit:purge` does for its own deletes (and resets afterwards). Other database
drivers skip the migration.

Deployment requirements for the triggers:

- The database user that runs migrations needs the `TRIGGER` privilege on the app database. The
  official MariaDB image (`docker-compose.prod.yml`, `MARIADB_USER`) grants `ALL` on that
  database, which includes it.
- If **binary logging** is enabled on the database server, MariaDB also requires `SUPER` or
  `log_bin_trust_function_creators = 1` to create triggers. The default `mariadb:11.4` container
  has binary logging off.
- If the privilege is missing, the migration stops with a message explaining the `GRANT`
  needed; it never skips silently on MariaDB/MySQL.
- A database administrator (root) can still drop the triggers; they protect against the
  application account and SQL run through it, not against the database root user.

**Known limitation:** reception "check in again" after a sign-out (upstream behaviour) replaces
that visitor's sign-in time and clears the sign-out time. It is part of the live workflow and
was left unchanged; it is not available once the visit is completed.

## 3. Threat model

**Assets.** Visitor personal data (names, contact details, photos, signatures, WWCC numbers and
check status); who is on site now (emergency roll call); staff directory (hosts); admin and
reception accounts; the organisation's mail sender (Microsoft Graph) and label printers.

**Actors.**

| Actor | Reaches | Main risks |
|---|---|---|
| Anyone on the internet (or the allowed countries, with a geo filter) | Login, password reset, QR pages (if they have a poster link), public assets | Account enumeration, credential stuffing, QR abuse (open item A–C), spam through visitor mail, disk/CPU exhaustion |
| Visitor at reception | Kiosk | Looking up other visitors on the kiosk, tampering with the kiosk browser |
| Staff (host, receptionist) | `/portal`, `/reception` | Over-reach beyond their role and site (covered by permissions and site scoping) |
| Former staff | Old sessions | Fixed: deactivation now ends sessions |
| Administrator | Everything | Protected by two-factor (local login) or Entra Conditional Access (Microsoft sign-in) |

**Trust boundaries.** Internet → firewall (optional geo filter, port forwards) → Caddy (TLS, only
way in) → app container → database (internal network only). Outbound: Microsoft Graph (mail, host
sync), Entra (sign-in), site printers over the internal network or VPN. The kiosk is a
semi-trusted device enrolled with a one-time code.

**Network controls (recommended).**

- **Forward only ports 80 and 443** to the server; keep SSH and the database internal.
- **Geo-restriction (optional):** allow 443 only from the countries your visitors and staff use.
  Keep **port 80 open worldwide**: Let's Encrypt validates certificates from servers in several
  countries, and port 80 only answers those checks and redirects to HTTPS, so restricting it makes
  certificate renewal fail. Geo lists are approximate (satellite and mobile providers can
  geolocate elsewhere), so test from the networks your users rely on.

## 4. Independent penetration test: scope

**In scope** (test environment preferred, otherwise production by agreement, out of hours):

1. The production or test hostname's anonymous surface: `/login`, `/forgot-password`,
   `/reset-password/*`, `/two-factor-challenge`, `/auth/oidc/*`, `/visit/{token}` (a test QR link
   will be provided), `/leave/{token}`, `/kiosk/enrol`, Livewire endpoints used by those pages,
   `/storage/*`, `/brand.css`, `/up`.
2. Kiosk: an enrolled test kiosk (device cookie handling, data shown to someone at the kiosk).
3. Authenticated roles with test accounts: host (user), receptionist, admin — privilege and site
   separation, IDOR on visits, visitors, credentials and evidence files.
4. Mail content injection (visitor fields into host and visitor e-mails).
5. TLS and HTTP configuration, and the firewall's port forwards to the server.

**Out of scope:** Microsoft 365 / Entra itself, firewall management, the organisation's other systems, denial-of-service
load testing (unless agreed), social engineering.

**Known issues to tell the tester:** items A–C above (unless fixed first).

**Deliverables:** findings with severity and reproduction steps; a retest after fixes.

## 5. Deployment notes

- Rebuild the image (`docker build --pull`) so the Apache and Debian updates are included, then
  deploy as usual (docs/fork/production-deployment.md).
- New optional settings: `AUTH_LOGIN_MIN_FAILURE_MICROSECONDS` (default 1500000),
  `UPLOAD_TEMPORARY_PER_MINUTE` (default 15).
