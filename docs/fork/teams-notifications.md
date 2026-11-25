# Microsoft Teams notifications for hosts

Hosts can get a Teams notification as well as, or instead of, e-mail. The server decides how
(`TEAMS_DELIVERY`): an alert in the Teams **activity feed** (the bell, default) or a **chat
message from the app's bot** with the visitor's details (see
[Chat messages from the bot](#chat-messages-from-the-bot)). Both cover:

| Event | Notification | Activity type | Alert text (template) |
|---|---|---|---|
| Their visitor signed in | `Host\GuestCheckedInMailNotification` | `visitorArrived` | `{visitor} has arrived at {site}` |
| Someone else booked a visit for them in the portal | `Host\VisitCreated` (now sent from `Portal\VisitController::store`) | `visitCreated` | `New visit booked for you: {visitor} at {site}` |
| Daily reminder of today's visits | `Host\VisitReminderDaily` | `visitReminder` | `Visits today: {count}` |

The alert title and preview are in the host's language. Only the visitor's display name, the
site name and a count are sent to Microsoft; never e-mail addresses, phone numbers, companies,
answers or visit titles. Clicking the alert opens the app's **Visits** tab, which offers one
button that opens the visit in the portal in the browser (sign-in and access checks as usual).
The tab page (`/teams/tab`) shows no visit data and is the only page Teams may frame.

## Who gets what

- Organisation defaults: **Organisation settings → Notifications** — "Notify hosts by email"
  (default Yes) and "Notify hosts in Microsoft Teams" (default No). Not per site.
- Each host can override both in **Profile → Notifications**. The Teams checkbox only appears
  once Teams is set up. Turning both off means no host notifications at all (the in-app
  notification on check-in is unaffected).
- Teams is only used while `TEAMS_NOTIFICATIONS_ENABLED=true` and `TEAMS_APP_ID` is set.

Code: `App\Notifications\HostNotificationChannels` (channel choice; "Teams" means chat or
activity alert according to `TEAMS_DELIVERY`),
`App\Teams\TeamsActivityChannel` (Graph `POST /v1.0/users/{id}/teamwork/sendActivityNotification`),
`App\Teams\HostActivities` (alert content), `App\Microsoft\GraphAppToken` (client-credentials
token shared with the Entra host sync), `App\Teams\TeamsAppManifest` and
`visitorportal:teams:package` (app package), `App\Teams\Http\TeamsTabController` (tab page),
`App\Teams\TeamsBotMessenger`, `App\Teams\TeamsChatMessage` and `App\Teams\Http\TeamsBot*`
(bot chat messages).

The recipient is the host's Entra object id when the Entra host sync linked the account,
otherwise their e-mail address (Graph accepts it as the user principal name, so it must match
the UPN).

## Microsoft 365 setup (administrator)

1. **Graph permission.** In the Entra app registration already used for mail and the host sync
   (`MICROSOFT_GRAPH_*`): **API permissions → Add → Microsoft Graph → Application permissions →
   TeamsActivity.Send**, then **Grant admin consent**. (A separate app can be used with
   `TEAMS_TENANT_ID`, `TEAMS_CLIENT_ID` and `TEAMS_CLIENT_SECRET`.)
2. **Configuration.** Generate a GUID once for the Teams app and keep it for good (it identifies
   the app; changing it means a new app), e.g.
   `docker compose -f docker-compose.prod.yml exec app php -r "echo Illuminate\Support\Str::uuid(), PHP_EOL;"` or
   `[guid]::NewGuid()` in PowerShell. In `app.env` set:

   ```dotenv
   TEAMS_NOTIFICATIONS_ENABLED=true
   TEAMS_APP_ID=<the GUID>
   ```

   Restart the app, queue and scheduler containers.
3. **Build the app package.**

   ```sh
   docker compose -f docker-compose.prod.yml exec app php artisan visitorportal:teams:package
   ```

   It writes `storage/app/teams/teams-app.zip` (manifest schema 1.17, icons in the
   organisation's primary colour and initials, `APP_URL` as the domain) and prints the path.
   Use `--output=/path/app.zip` to write elsewhere. After changing the name, colour or address,
   build again with a higher `--app-version` (e.g. `--app-version=1.0.1`) and upload the update.
4. **Upload the app.** Teams admin centre → **Teams apps → Manage apps → Upload new app** →
   choose the zip. Make sure the app is **Allowed** (and that org-wide app settings allow custom
   apps).
5. **Install it for the hosts.** Teams admin centre → **Teams apps → Setup policies**: create or
   edit a policy, add the app under **Installed apps** (optionally pin it), and assign the policy
   to the host users, e.g. the groups used for the Entra host sync (group policy assignment).
   **Alerts only arrive for users who have the app installed**; Graph refuses the rest. Policy
   changes can take several hours (up to a day) to reach users.
6. **Turn it on.** Admin panel → **Organisation settings → Notifications** → "Notify hosts in
   Microsoft Teams" = Yes (and decide whether e-mail stays on). Hosts can still change their own
   choice in their profile.

## Behaviour on failure

Notifications are queued, so Teams never delays or undoes a sign-in or booking.

| Graph answer | Meaning | What happens |
|---|---|---|
| 403 `Forbidden` | App not installed for that user, or TeamsActivity.Send not consented | Warning logged (user id, visit id, Graph error code), not retried |
| 404 `NotFound` | No such user (e-mail is not their UPN, account deleted) | Warning logged, not retried |
| 401 | Token revoked or rotated | New token fetched and the request retried once |
| 429, 5xx, no connection | Throttled or Microsoft unavailable | Job fails and the queue retries it (`--tries=3`) |
| Token request refused | Wrong or expired client secret | Job fails; the log names Entra's error code only |

Logs never contain tokens or secrets.

## Troubleshooting

- **Nothing arrives, log says 403:** check the app is installed for that user (Teams → Apps →
  Manage your apps), the setup policy applies to them, and admin consent was granted for
  TeamsActivity.Send. Also check the uploaded package's app id matches `TEAMS_APP_ID`.
- **404:** the host's e-mail in the portal differs from their Entra UPN. Link them with the Entra
  host sync (uses the object id) or correct the e-mail.
- **No log entry at all:** the host (or the organisation default) has Teams off, Teams is not
  enabled in `app.env`, or the queue worker is not running.
- **The tab shows "refused to connect":** `APP_URL` must be the https address Teams loads
  (`/teams/tab` allows framing by Teams only on that route).

## Chat messages from the bot

With `TEAMS_DELIVERY=chat` the host gets a 1:1 chat message from the Teams app (its bot) instead of
the activity alert, with an Adaptive Card:

| Event | Heading | Details |
|---|---|---|
| Visitor arrived | "Your visitor has arrived" | Visitor, company, visitor type, site, arrival time; optionally the sign-in photo |
| Visit booked for you | "A visit was booked for you" | Visitor(s), company, visitor type, site, date, time |
| Daily reminder | "Your visits today" | One line per visit (time, visitors, site), at most 10 |

Each card has an **Open in portal** button (the visit, or the overview for several visits; normal
sign-in and access checks apply). The card is in the host's language. Visitor-typed values are
Markdown-escaped so they cannot become links or formatting.

**Privacy.** Unlike the activity alert, the chat message sends the visitor's company and visitor
type to Microsoft, and they stay in the host's chat history. The photo is only included with
`TEAMS_BOT_INCLUDE_PHOTO=true`: the card then links to `/teams/visitor-photo` with a temporary
signed URL (valid 24 hours, file path encrypted, `no-store`); after that the photo no longer
loads. Teams fetches the image from the portal, so `APP_URL` must be reachable by Teams for
photos to show.

**How it works.** The portal gets a token for `https://api.botframework.com/.default` from the
bot's tenant (client credentials), creates or reopens the 1:1 chat
(`POST {TEAMS_BOT_SERVICE_URL}v3/conversations` with the host's Entra object id) and posts the
message (`.../v3/conversations/{id}/activities`). The chat id is cached for 30 days per host
(creating it again is harmless: Teams returns the same chat). The bot addresses hosts by **Entra
object id only**, so hosts not linked by the Entra host sync get the activity alert instead.

**Fallback.** If the bot cannot reach a host (403/404, e.g. the bot is not installed for them or
the Teams channel is off) or Entra refuses the bot's credentials (400/401), a warning is logged
(user id, visit id, step, status, error code) and the activity alert is sent instead, once. 429, 5xx and connection errors fail the job so the queue
retries it. Sign-in and booking are never affected.

**Messaging endpoint.** Azure requires one: `POST /teams/bot/messages`. The bot is
notification-only (`isNotificationOnly` in the manifest), so the endpoint answers every request
with an empty 200 and never reads, stores or acts on what is posted (and therefore does not
validate the Bot Framework token). It has no session, cookies or CSRF check and is rate limited.

### Azure setup (administrator)

Do the [Microsoft 365 setup](#microsoft-365-setup-administrator) above first (the activity alert
remains the fallback, so keep TeamsActivity.Send consented).

1. **Create the Azure Bot.** Azure portal → **Create a resource** → **Azure Bot**. Bot handle:
   any unique name; pricing tier **F0 (free)**; **Type of App: Single Tenant**; **Creation type:
   Use existing app registration**, with the **App ID** and **tenant id** of the app registration
   the portal already uses for mail and the host sync (`MICROSOFT_GRAPH_CLIENT_ID`,
   `MICROSOFT_GRAPH_TENANT_ID`), or of a separate app registration in the same tenant. The bot
   needs no extra Graph permissions; it authenticates with the app's client secret.
2. **Messaging endpoint.** In the bot resource → **Settings → Configuration** → Messaging
   endpoint `https://<portal host>/teams/bot/messages` → Apply.
3. **Teams channel.** **Settings → Channels** → **Microsoft Teams** → accept the terms → Apply.
4. **Configuration.** In `app.env` set `TEAMS_DELIVERY=chat`. Only if the bot uses a different app
   registration than the portal's Graph app, also set `TEAMS_BOT_APP_ID` and
   `TEAMS_BOT_APP_SECRET` (and `TEAMS_BOT_TENANT_ID` if it differs). Optional:
   `TEAMS_BOT_INCLUDE_PHOTO=true`, `TEAMS_BOT_SERVICE_URL` (default
   `https://smba.trafficmanager.net/teams/`). Restart the app, queue and scheduler containers.
   **Organisation settings → Notifications** then shows the active delivery under "Notify hosts
   in Microsoft Teams".
5. **Rebuild and upload the Teams app.** With `TEAMS_DELIVERY=chat` the package contains a `bots`
   section (bot id = the app registration id, personal scope, notification-only). Build it with a
   higher version:

   ```sh
   docker compose -f docker-compose.prod.yml exec app php artisan visitorportal:teams:package --app-version=1.1.0
   ```

   Teams admin centre → **Teams apps → Manage apps** → the app → **Upload file** (new version) →
   the new zip. Keep the same `TEAMS_APP_ID`.
6. **Installation.** Nothing new: the setup policy that installs the app for hosts also installs
   the bot. Teams can take several hours (up to a day) to roll a new app version out to users;
   until then hosts get the activity alert (fallback).
7. **Troubleshooting.**
   - Log "Teams bot message not delivered" with **403**: the app version with the bot is not
     installed for that host yet, or the Teams channel is not enabled on the Azure Bot (step 3).
     Check Teams → Apps → Manage your apps for the host.
   - **404**: unknown user or chat (e.g. the account was deleted); the cached chat id is dropped.
   - **401** (logged as "not delivered") or a failed token request: wrong bot app id, secret or
     tenant (`TEAMS_BOT_*`, otherwise `TEAMS_CLIENT_*` / `MICROSOFT_GRAPH_*`), or the Azure Bot
     uses a different app registration.
   - Photo missing: `TEAMS_BOT_INCLUDE_PHOTO` is off, no photo was taken at that sign-in, the link
     is older than 24 hours, or Teams cannot reach `APP_URL`.
   - To go back to activity alerts, set `TEAMS_DELIVERY=activity`. The bot can stay in the
     package; to remove it, build again (without chat delivery) with a higher version and upload.
