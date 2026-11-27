<!--
SPDX-FileCopyrightText: 2026 Simon Grant
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Emergency SMS and e-mail broadcast

When a roll call starts for a **real emergency**, everyone signed in at that site is messaged
straight away: an **SMS** to everyone with a mobile number and an **e-mail** to everyone with an
e-mail address (both if they gave both). There is no extra confirmation: the existing "Start roll
call" prompt is the only one. The roll call board then shows each person's SMS outcome.

Builds on the roll calls in [phase-9-emergency.md](phase-9-emergency.md).

## What is sent

Recipients are the people on the roll call's **snapshot** (everyone signed in at that site when it
started, nobody from other sites, nobody already signed out). Late arrivals and people added by
hand are not messaged. A second warden pressing start opens the same roll call and sends nothing
again.

| | Text (English; translated into the organisation's default language) |
|---|---|
| Emergency SMS | EMERGENCY at {site}: please leave now and follow the emergency signs to the muster point. Do not return until told it is safe. |
| Drill SMS | DRILL - no real emergency. {site}: please leave now and follow the emergency signs to the muster point. |
| E-mail subject | Emergency at {site} / Drill at {site} |
| E-mail body | The same text, no links. |

Visitors' language is not stored, so messages use the organisation's default language
(Organisation settings → Region → Default language).

SMS are kept to **one segment** (160 GSM characters): typographic dashes and quotes are replaced
with plain ones and a long site name is shortened. Czech texts need Unicode and are sent as
several parts.

## Drills

"Start drill" asks for confirmation with one switch, **Send drill messages to everyone signed
in**, **off by default**:

- Off: a training drill. Nothing is sent (as before).
- On: the same SMS and e-mail, starting with "DRILL - no real emergency".

The switch only appears when emergency messages are switched on.

**A real emergency during a drill.** While a drill is open, the on-site page and the drill's
board show **Real emergency**. It ends the drill and starts an emergency roll call in its place:
a fresh list of everyone signed in now, the marks wardens already made in the drill (and people
they added by hand), and the emergency SMS and e-mail to everyone on the new list, sent once, even
to people who already had the drill message. Pressing "Start roll call" for an emergency while a
drill is open does the same. Wardens still on the drill's board get a button to the emergency.
The audit log records `roll_call.escalated`; no separate drill report is sent.

## Roll call board

Next to each name: **SMS sending**, **SMS delivered**, **SMS failed** or **No mobile**, updated from
MessagePort's delivery receipts. Above the list a one-line summary, e.g. "SMS: 12 delivered, 0
sending, 1 failed, 2 no mobile · Email: 14 sent, 0 pending, 0 failed". The statuses are one small
query on the board's 5-second refresh.

Sending never blocks or breaks the roll call: the start only queues a job (after the roll call is
saved). The job is tried up to **five times** (after 5, 15, 30 and 60 seconds). Each run reuses
the rows already created and sends only messages still pending, so a crash part-way finishes on
the retry without skipping anyone or re-sending messages already recorded (a crash in the instant
between MessagePort accepting a text and the row being updated may repeat that one text). A
lock stops two workers sending the same roll call at once. Problems are logged and shown as
"SMS failed", never as an error to the warden. Each e-mail is its own queued job.

- **Temporary problems are retried.** If MessagePort cannot be reached, times out, or answers
  408/425/429 or 5xx, the texts stay "sending" and the job tries again after the next wait. Only
  after the last try are they shown as failed (with the reason, e.g. `HTTP_503`). A refusal that
  would not change on a retry (bad credentials, invalid or opted-out number) is final at once.
  A timeout may mean MessagePort did take the text, so a retry can repeat it: in an emergency a
  duplicate is better than nothing.
- **Status updates cannot go backwards.** The send worker only moves a text out of "pending",
  and a delivery receipt only changes a status that is not final, both as conditional database
  updates. A receipt that arrives while the worker is still waiting for MessagePort is kept.
- **Each e-mail is sent once.** A retry re-queues every e-mail still pending; each e-mail job
  claims its row (a lock per message) and re-reads it first, so a duplicate job sends nothing.

Audit: `roll_call.started` now records `messages` (true/false); `roll_call.broadcast` records the
kind, SMS accepted / failed / without mobile, e-mails queued and whether dry run was on, with the
user who started the roll call.

Stored per person and channel in `emergency_notifications`: masked address (last three digits /
first letter and domain), our reference, MessagePort's message id, status and error code. Deleted
with the roll call (roll call retention).

## Phone numbers

Numbers are converted to E.164. Australian formats work without a country code (`0412 345 678`,
`412 345 678`, `61412345678`, `+61 (0)412 345 678`, `0061…`). Australian landlines and anything
that is not plausibly a mobile show "No mobile". Numbers from other countries need `+` and the
country code.

## Settings

All in the server environment (`.env`, or `app.env` with docker-compose.prod.yml). The admin
settings (Organisation settings → Notifications → **Emergency messages to visitors**) show whether
SMS and e-mail are on and what is missing; no secrets are shown.

| Variable | Default | Meaning |
|---|---|---|
| `EMERGENCY_SMS_ENABLED` | `false` | Send emergency SMS (needs the MessagePort settings below) |
| `EMERGENCY_EMAIL_ENABLED` | `false` | Send emergency e-mails (uses the normal mail settings) |
| `MESSAGEPORT_ACCESS_TOKEN` | | API key: Access Token (HTTP Basic user name) |
| `MESSAGEPORT_TOKEN_ID` | | API key: Token ID (HTTP Basic password) |
| `MESSAGEPORT_SENDER_ID` | | Approved sender ID, e.g. an alphanumeric name or a dedicated number |
| `MESSAGEPORT_WEBHOOK_SECRET` | | Secret of the delivery-receipt webhook |
| `MESSAGEPORT_BASE_URL` | `https://app.messageport.com.au/api` | API base URL |
| `MESSAGEPORT_DRY_RUN` | `false` | `true`: send to `/v2/sms/dry`, which validates and sends nothing |

The queue worker must be running (it is in docker-compose.prod.yml): messages go out from the
queue.

## MessagePort setup

1. **API key.** In MessagePort, create an API key. Put its Access Token in
   `MESSAGEPORT_ACCESS_TOKEN` and its Token ID in `MESSAGEPORT_TOKEN_ID`.
2. **Sender ID.** Use a sender ID that MessagePort has approved for the account (an alphanumeric
   name up to 11 characters or a number) and set `MESSAGEPORT_SENDER_ID`. Alphanumeric senders
   cannot receive replies.
3. **Delivery receipts.** Settings → Webhooks → add a webhook:
   - Event: `message_dlr`
   - URL: `https://<your portal host>/webhooks/messageport`
   - Method: `POST`, format: JSON
   - Copy the webhook's secret into `MESSAGEPORT_WEBHOOK_SECRET`.

   The endpoint must be reachable from the internet. It accepts only requests whose `Signature`
   header is the HMAC-SHA256 of the body with that secret, only changes messages it sent, and
   handles MessagePort's retries safely (a delivered message never goes back to "sending").
4. Set `EMERGENCY_SMS_ENABLED=true` (and `EMERGENCY_EMAIL_ENABLED=true` for e-mail), restart the
   web and queue containers, and check the admin settings.

## Testing

1. Set `MESSAGEPORT_DRY_RUN=true`. Requests are validated by MessagePort (key, sender, numbers) but
   nothing is sent and no receipts arrive; the board stays on "SMS sending".
2. Sign in a test visitor with MessagePort's test number `0491 578 888` (accepted, never
   delivered). `0491 570 156` is rejected as an invalid recipient and `0491 570 157` as opted out:
   both should show "SMS failed".
3. Start a drill with **Send drill messages** switched on, look at the board and the audit log,
   then end the drill.
4. For a real end-to-end test, switch dry run off and use a staff member's mobile in a drill.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Everyone "SMS failed", log `emergency_sms_request_rejected` status 401 | Wrong Access Token or Token ID (error code `HTTP_401`) |
| "SMS failed", error code `UNAPPROVED_SENDER_ID` / `INVALID_SENDER_ID` | `MESSAGEPORT_SENDER_ID` is not approved for the account |
| "SMS failed", error code `NOT_CONFIGURED` | SMS switched on but a MessagePort setting is missing (see admin settings) |
| "SMS failed", `CONNECTION_FAILED` | The server cannot reach app.messageport.com.au (firewall, proxy, DNS) |
| Stays on "SMS sending" | Dry run is on, or receipts are not arriving: check the webhook URL and that the host is reachable |
| Log `messageport_webhook_rejected` `bad_signature` | `MESSAGEPORT_WEBHOOK_SECRET` does not match the webhook's secret, or a proxy changes the body |
| Log `messageport_webhook_rejected` `no_secret_configured` | `MESSAGEPORT_WEBHOOK_SECRET` is empty: receipts are refused |
| Nothing sent at all | `EMERGENCY_SMS_ENABLED` / `EMERGENCY_EMAIL_ENABLED` off, queue worker not running, or a drill with the switch off |

Logs never contain secrets or full phone numbers (last three digits only).

## Tests

`EmergencyBroadcastTest`: recipients (site, signed out), drill off/on, request (Basic auth,
sender, E.164, reference), dry-run URL, per-message errors, 401, missing settings, feature off,
webhook signature / unknown reference / no downgrade / idempotent retries / failed receipts, board
statuses and summary, phone normalisation, one-segment text, admin status, Markdown-safe e-mail.
