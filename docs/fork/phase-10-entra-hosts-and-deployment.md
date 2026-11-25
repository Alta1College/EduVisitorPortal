# Phase 10: Entra hosts, staff SSO and production deployment

Phase 10 configures the generic platform for a real deployment. The only new core code is
optional and organisation-neutral:

- **Entra host sync**: members of each site's Microsoft Entra group(s) become local host accounts.
- **Email-verified claims for OIDC**: lets Microsoft Entra ID's `xms_edov` claim count as a
  verified email, so staff SSO can link to those accounts.

An organisation's own values (sites, visitor types, policies, retention, printers, branding) are
entered in the admin panel. They are never committed to this repository (CLAUDE.md rule 2).

## 1. Entra host sync

Visitors still choose hosts from the local database, so sign-in keeps working when Microsoft is
unreachable. The sync only keeps that list current.

| What happens | When |
|---|---|
| A local account is created (no password login, role `ENTRA_HOST_SYNC_ROLE`, default `user`) | A group member has no account with that email |
| An existing account with the same email is linked, and its names are updated | A group member already has a local account, e.g. a local admin |
| The account is assigned to every site whose group the person is in | Every run |
| A synced site is removed from the account | The person leaves that site's group |
| An account **the sync created** is deactivated | The person leaves every group, or is disabled in Entra |
| Linked accounts are **never** deactivated; they keep their own primary site | — |

Safety rules:

- Every group is read before anything is written. Nothing changes when any of these happens:
  - a Graph error;
  - a group that is not found;
  - a group that comes back empty.
- Guest accounts (`#EXT#`) and members without an email are skipped.
- Each run is audited as `host_directory.synced` or `host_directory.sync_failed`.

### Set up

1. **App registration.** Add these **application** permissions to the Microsoft Graph app used
   for mail (or a separate app), then grant admin consent:
   - `GroupMember.Read.All`
   - `User.Read.All`

   Without a separate app, the sync uses the `MICROSOFT_GRAPH_*` credentials. A separate app goes
   in `ENTRA_HOST_SYNC_TENANT_ID`, `ENTRA_HOST_SYNC_CLIENT_ID` and
   `ENTRA_HOST_SYNC_CLIENT_SECRET`.
2. **One group per site.** Create a security group per site (nested groups are included),
   e.g. "Visitor hosts – Central Office". Copy each group's **Object ID**.
3. **Admin panel.** Go to Settings → Visitor experience → **Entra host groups** and enter the
   group's object ID on each site. For a site in more than one group, separate the IDs with
   commas. A value at organisation level applies to every site without its own.
4. **Environment.**
   ```
   ENTRA_HOST_SYNC_ENABLED=true
   ENTRA_HOST_SYNC_ROLE=user
   ENTRA_HOST_SYNC_CRON="15 * * * *"   # hourly
   ```
5. **Run it once by hand** and check the counts:
   `php artisan visitorportal:hosts:sync-entra`. After that, the scheduler runs it every hour.

## 2. Staff sign-in with Entra ID (OIDC)

This uses upstream's OIDC support (docs/sso.md), configured as follows.

1. **App registration.** Register a web app, e.g. "Edu Visitor Portal sign-in". It is separate
   from the Graph app, because they hold different permissions.
   - Redirect URI: `https://<host>/auth/oidc/callback`
   - Token configuration: add the optional claims `email` and **`xms_edov`** to the **ID** token.
   - Create a client secret.
2. **Environment.**
   ```
   SSO_ENABLED=true
   AUTH_MODE=local_and_sso                    # the default (local) hides the sign-in button
   OIDC_DISPLAY_NAME="Staff sign-in"
   LOGIN_EMAIL_PLACEHOLDER=first.last@<staff email domain>   # optional login form hint
   OIDC_ISSUER_URL=https://login.microsoftonline.com/<tenant-id>/v2.0
   OIDC_CLIENT_ID=<sign-in app id>
   OIDC_CLIENT_SECRET=<secret>
   OIDC_REDIRECT_URI="${APP_URL}/auth/oidc/callback"
   OIDC_SCOPES="openid profile email"
   OIDC_PROVISIONING_MODE=invited_only        # only people the host sync (or an admin) created
   OIDC_ALLOWED_DOMAINS=<staff email domain>
   OIDC_REQUIRE_VERIFIED_EMAIL=true
   OIDC_EMAIL_VERIFIED_CLAIMS=email_verified,xms_edov
   ```
   The issuer is the tenant-specific URL, so only that tenant's accounts are accepted.
3. **Break-glass account.** Keep at least one local admin with two-factor authentication
   (`local_login_allowed`) in case Entra is unavailable.
   - If Entra Conditional Access already requires MFA, set
     `APP_MFA_REQUIRED_FOR_ADMIN_PANEL_AUTH_METHODS=local`, so the portal's own two-factor is
     asked only for local passwords, not again after Microsoft sign-in.
   - Recovery codes can be saved as a .txt file from the setup and profile screens.
4. **Optional role mapping.** To make some staff receptionists or admins from Entra groups, see
   "Role Mapping" in docs/sso.md.

## 3. Production host (example: Linux VM in a co-location data centre)

- **Server.** Ubuntu LTS VM with Docker Engine and the compose plugin. Use
  `docker-compose.prod.yml` as described in docs/deployment.md. Docker on Linux avoids the slow
  file mounts of Docker Desktop on Windows.
- **HTTPS is required** for the kiosk and phones to use the in-page camera, and for SSO cookies:
  - Put a reverse proxy in front of the app (docs/deployment.md, "Reverse Proxy And TLS").
  - Use a certificate the iPads and phones trust: a public certificate, or an internal CA
    deployed to the devices through MDM.
  - Set `APP_URL=https://<host>`, `TRUSTED_PROXIES`, and the organisation's public URL setting.
- **Network.**
  - The VM must reach each site's label printer on TCP 9100 over the site-to-site VPN.
  - It also needs `login.microsoftonline.com` and `graph.microsoft.com` on 443.
  - Kiosks and phones must reach the HTTPS host. For phones on mobile data that means a public
    address; otherwise limit the QR links to the school Wi-Fi.
- **Regional settings.**
  - `APP_TIMEZONE=Australia/Perth` (or your zone) **before the first migration**, so new sites
    get it.
  - Set each site's timezone and the automatic sign-out time.
- **Services.** The scheduler and queue containers must run. They handle automatic sign-out, the
  host sync, badge printing and emails.
- **Backups.** Back up the database and `storage/app` (photos, signatures, branding). Test a
  restore before go-live.

## 4. Organisation values before go-live

An organisation can keep its values in a script outside the repository and run it against a
fresh install, then manage them in the admin panel after go-live. That covers:

- sites, timezones and automatic sign-out times;
- visitor types and questions (e.g. a Working with Children Check, with "remember for returning
  visitors");
- policies;
- retention periods;
- label printers and kiosks;
- branding.

The script should only create or update records through the same models and `SettingsRepository`
the admin panel uses, and never delete anything.
