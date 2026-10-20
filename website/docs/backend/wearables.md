---
title: "Wearables"
sidebar_label: "Wearables"
description: "Withings and Google Health linking over OAuth 2.0, token storage, sync schedule, stored data, and the limits of what the data is used for."
---

# Wearables

<span className="status status--shipped">Shipped</span> ingestion and export · fork-only
<span className="status status--partial">Partial</span> webhook-triggered prompts for Withings and Google Health (untested against live providers)
<span className="status status--tbc">TBC</span> tailoring prompts from measurement values

Participants can link a consumer wearable to a study. The server pulls their data on a schedule and stores it
for the researcher, and can use provider webhooks to trigger prompts.

:::caution[What this feature does *not* do]
Measurement data is **stored and exported only**. The hourly sync fetches *completed days only*, so it is
typically a day old, and no code reads the values to decide anything. Prompts can be triggered by a webhook
saying *new data of a type arrived*, see [Webhooks and sensor triggers](#webhooks-and-sensor-triggers). There
is no value threshold and no real wake-up detection. See [Current status](../current-status.md).
:::

## Providers and data

| Provider | Data types fetched by default |
| --- | --- |
| **Google Health** (Fitbit / Pixel Watch) | steps, heart rate, sleep, weight, SpO₂, HRV, respiratory rate (sleep summary). One row per UTC day, value = that day's data points verbatim |
| **Withings** | weight, blood pressure, activity, sleep (summary), ECG |

`Study.wearablesDataTypes` can narrow the list, but **no designer UI sets it**; use the study source. If it is
empty, or does not intersect the provider's list, the provider defaults apply.

## Server configuration

Credentials are server-wide, set with the CLI:

```bash
php cli/wearables_setup.php genkey                                  # token-encryption key
php cli/wearables_setup.php <withings|googlehealth> <client_id> <client_secret> [redirect_uri]
```

| Config key | Purpose |
| --- | --- |
| `wearables_<provider>_client_id` / `_client_secret` | OAuth application credentials. |
| `wearables_token_key` | 32-byte base64 key for token encryption. |
| `wearables_redirect_uri` | Optional; default `scheme://host/<api dir>/wearables_oauth.php`. |
| `wearables_pwa_path` | Optional; where to send the browser back (default `/pwa`). |

There is **no environment-variable support** for these keys. The researcher panel shows the exact redirect
URI to register with each provider.

## Setting up provider apps {/* #provider-setup */}

Each provider needs its own developer application, registered once per server. Withings and Google Health differ in where
the app lives, what has to be registered besides the redirect URI, and how webhooks are subscribed (see
[Webhooks](#webhooks-and-sensor-triggers)). Fitbit is no longer a provider; its data comes through Google Health.

**Steps common to every provider**

1. Run `php cli/wearables_setup.php genkey` once, before the first provider, to create the token-encryption key.
2. Decide the **redirect URI**: `https://<your-server>/<path>/api/wearables_oauth.php`. The provider compares it with
   the one ESMira sends **character for character**, so register exactly the string the researcher panel shows.
3. Behind a reverse proxy or Docker the automatically derived URL is often wrong (plain `http`, or without the path
   prefix), and the provider answers with a redirect-URI mismatch. Pass the public URL explicitly as the last argument of
   the CLI call (it is stored as `wearables_redirect_uri`). If webhooks are used behind such a proxy, also set
   `wearables_webhook_uri`.
4. Store the credentials with the CLI, then enable the provider in the study's Wearables panel. The provider appears in
   the participant app only when the server has credentials for it.

| | **Withings** | **Google Health** |
| --- | --- | --- |
| Where the app lives | Withings developer dashboard | A Google Cloud project with the Google Health API enabled for it |
| Credentials | Client ID and secret | OAuth client of type *Web application*: client ID and secret |
| Redirect URI field | "Callback URL" of the app | "Authorized redirect URIs" of the OAuth client |
| Scopes (fixed in code) | `user.info`, `user.metrics`, `user.activity`, `user.sleepevents` | `sleep.readonly`, `activity_and_fitness.readonly`, `health_metrics_and_measurements.readonly` (all under `googlehealth.`) |
| Consent-screen step | None | Configure the OAuth consent screen and add the scopes; in *Testing* mode only listed test users can link and refresh tokens expire after 7 days, and an unverified app is capped at 100 users |
| Webhook registration | **None by hand.** The server subscribes each data type for each participant when they link | **Once per server**, by sending a "subscriber" request to Google (`googlehealth-webhook`) |
| Webhook authenticity | Key in the callback URL, derived from the client secret | Secret in the `Authorization` header plus Google's signature |
| What the callback URL must be | Reachable by Withings; carries the key, so keep query strings out of access logs | Public HTTPS (TLS 1.2+); Google verifies it during registration |
| Accounts | One grant per Withings user per app: linking the same account under a second participant ID kills the first link | The participant needs a Google Health profile (a Google account that has their Fitbit or Pixel Watch data) |
| Tokens | Refresh tokens are single use | 1 h access tokens, refresh token requested with `access_type=offline` |

### Withings {/* #withings-setup */}

1. In the Withings developer dashboard, create an application and set its **Callback URL** to the redirect
   URI above. The dashboard's "Test" button for the callback may report an HTTP 302; that is expected, because a bare
   request carries no authorization code.
2. Store the credentials:

   ```bash
   php cli/wearables_setup.php withings <client_id> <client_secret> https://<your-server>/<path>/api/wearables_oauth.php
   ```

3. Nothing else to register. When a participant links, the server calls Withings `notify` once per event type, with a
   callback of the form `…/api/wearables_webhook.php?provider=withings&k=<key>`. If subscribing fails, linking still
   succeeds; the failure goes to the server error log and the trigger's time fallback covers the prompt.
4. **Do not rotate the client secret casually.** The webhook key is derived from it, so existing subscriptions stop working
   until each participant links again.
5. Most event types need a specific device (for example *out of bed* needs a Sleep Analyzer or sleep mat). Participants who
   linked before an event type was added to ESMira have no subscription for it until they re-link.

### Google Health {/* #google-health-setup */}

1. **Project.** Use a Google Cloud project for which Google has enabled the Google Health API (when last checked, Google
   was not onboarding new projects). Note the project **number**, not the project ID.
2. **Consent screen and OAuth client.** Configure the consent screen, add the three scopes above, and create an OAuth
   client of type *Web application* with the redirect URI as an authorized redirect URI. Publish the consent screen before
   a real study; in *Testing* mode refresh tokens expire after 7 days and only test users can link.
3. **Store the credentials:**

   ```bash
   php cli/wearables_setup.php googlehealth <client_id> <client_secret> https://<your-server>/<path>/api/wearables_oauth.php
   ```

4. **Register the webhook subscriber, once per server.** Run

   ```bash
   php cli/wearables_setup.php googlehealth-webhook
   ```

   It creates the webhook secret (a `Bearer …` value stored as `wearables_googlehealth_webhook_secret`) and prints the
   request to send to `https://health.googleapis.com/v4beta/projects/<PROJECT NUMBER>/subscribers?subscriberId=esmira`,
   with the subscribed data types and `AUTOMATIC` subscriptions. Send it as a Google identity that holds
   `health.subscribers.create`. Google calls the endpoint during registration, so it must already be publicly reachable
   over HTTPS with the secret in the `Authorization` header (Apache with PHP-FPM or CGI needs
   `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
5. After this, nothing happens at link time: Google starts sending notifications for every participant who has consented.
   Without step 4, linking and the daily download still work, but sensor triggers fall back to their time fallback.

Without webhook registration, both providers still **link and sync**; only sensor-triggered prompts depend on the steps
above.

## OAuth 2.0 flow

```mermaid
sequenceDiagram
  participant P as PWA
  participant S as ESMira server
  participant W as Provider (Withings / Google Health)
  P->>S: POST wearables_connect.php (userId, studyId, provider)
  S->>S: validate study, provider credentials, wearablesEnabled
  S->>S: create single-use state (10 min TTL) in .wearables_states/
  S-->>P: authUrl
  P->>W: browser navigates to authUrl
  W->>S: GET wearables_oauth.php?code&state
  S->>S: consume state (delete, reject if expired)
  S->>W: exchange code for tokens
  S->>S: save encrypted token
  S-->>P: redirect /pwa/?wearable=<p>&status=connected|error
```

- The `state` is `bin2hex(random_bytes(24))`, stored server-side with study, user and provider, and is
  single-use. It is **not bound to a browser cookie** and **PKCE is not used**.
- Scopes are requested together, not per data type: Withings `user.info,user.metrics,user.activity,user.sleepevents`;
  Google Health `sleep.readonly`, `activity_and_fitness.readonly`, `health_metrics_and_measurements.readonly`.
- Both authorization URLs send `prompt=login`.

## Token storage

One file per study, participant and provider:
`studies/<id>/.wearables_tokens/<userId>.<provider>`.

- Payload: JSON encrypted with libsodium `secretbox`, stored as `v1:` + base64(nonce + ciphertext).
- **Fallback:** if the `sodium` extension or key is missing, tokens are stored as `p0:` + **plaintext JSON**.
  The Docker image installs `sodium`, and `genkey` creates the key.
- Tokens refresh when under 5 minutes remain; the new refresh token is written immediately because Withings
  refresh tokens are single-use.
- The **client secret** and the token key are stored in plaintext in the server config file.

## Sync

The image runs `cli/wearables_sync.php` **hourly** (`0 * * * *`).

| Behaviour | Value |
| --- | --- |
| What is fetched | Whole completed **UTC days up to yesterday** |
| First run | Backfills 1 day |
| Catch-up cap | 14 days |
| Skipped when | `wearablesEnabled` is false, or provider not in a non-empty `wearablesProviders` |

## Stored data

`studies/<id>/.wearables_data/<userId>.<provider>.csv` with a `.state` cursor beside it. Append-only,
all fields quoted, server delimiter (default `;`):

```text
userId ; provider ; measurement_time ; data_type ; value ; fetched_at
```

`value` is the **raw provider JSON, verbatim**. Wearable CSVs are not encrypted on disk.

## Webhooks and sensor triggers {/* #webhooks-and-sensor-triggers */}

<span className="status status--partial">Partial</span> Not yet verified against live provider accounts beyond a single Withings test participant.

```mermaid
flowchart LR
  W[Provider] -->|"webhook: new sleep data for user X"| E["api/wearables_webhook.php"]
  E -->|"every notification"| A[".wearables_event_archive/&lt;user&gt;.&lt;provider&gt;.events.csv"]
  E -->|"only if a sensor trigger wants it"| L[".wearables_events/&lt;user&gt;.jsonl"]
  L --> S["PushSender (every minute)<br/>SensorTriggerScheduler"]
  S -->|"Web Push"| P[Participant]
  S -.->|"fallback time reached,<br/>no event that day"| P
```

| Provider | Registration | Authentication of the callback | Types |
| --- | --- | --- | --- |
| **Withings** (Notify) | Automatic when the participant links: the server subscribes each type via `notify` with a URL carrying a key derived from the client secret. | Key in the URL (`k=`); Withings does not sign requests. | every data category: weight (1), temperature (2), blood pressure (4), activity (16), sleep (44), bed in/out (50/51), sleep sensor ready (52), ECG (54, 55), glucose (58), stethoscope (61), HRV (62), urine (63), bed status (98–100) |

Optional config key `wearables_webhook_uri` overrides the auto-derived public callback URL (needed behind a
proxy that rewrites the host or path). Participants who linked **before** this feature was deployed have no
webhook subscription until they re-link.

**Only these providers have webhooks.** The designer offers *Any connected wearable*, *Withings* and *Google Health*. The data types below are
the complete set the designer offers, each checked against the provider's own documentation (2026-10-09):

| Designer type | Withings `appli` | Google Health data types |
| --- | --- | --- |
| Sleep | 44 (sleep summary) | `sleep` |
| Activity | 16 (steps, distance, workouts) | `steps`, `distance`, `floors`, `active-minutes`, `active-zone-minutes`, `exercise` |
| Weight / body | 1 (weight and body composition) | `weight`, `body-fat` |
| Out of bed | 51 (Sleep Analyzer / sleep mat bed-out event) | not available |
| Other Withings-only events | 2, 50, 52, 55, 58, 61, 62, 63, 98–100 (see the designer list) | not available |
| Blood pressure | 4 (blood pressure and heart rate) | not available |
| ECG | 54 (ECG measurement) | not available (ECG data exists, but has no webhook) |

Sources: [Google Health webhooks](https://developers.google.com/health/webhooks) and [Withings notification content](https://oauth.withings.com/developer-guide/v3/data-api/notifications/notification-content/).
Withings account events (46 profile changed, 53 device set up, 60 survey answered) carry no data and are not offered. Most Withings categories need a specific device and a participant without it never receives that event.
*Out of bed* (51) is a closer wake-up signal than "sleep summary synced", but only for owners of a Withings Sleep Analyzer.
It fires on every bed exit, including night-time ones, so pair it with the daily window and the per-day cap. The 10-minute
bed status events (98–100) need Live Sync enabled on the device. Participants who linked **before** an event was added
have no subscription for it until they re-link.

:::note[Fitbit is no longer a provider]
Fitbit's legacy Web API is turned off on 2026-10-30, so ESMira dropped its Fitbit provider (OAuth linking, hourly sync
and webhooks). Fitbit and Pixel Watch data reach ESMira through **Google Health**. A study imported from an older
version may still name `fitbit` as its trigger provider or in `wearablesProviders`; the designer flags such a
trigger as never firing, and the leftover entries are otherwise ignored. Token files and data written earlier stay on
disk but are no longer read or synced.
:::

### Google Health API

<span className="status status--partial">Partial</span> Implemented, untested against a live project.

:::caution[Not yet verified against a live project]
Written from Google's documentation. Google caps an unverified OAuth app at 100 users (more needs a third-party
security review), and while the consent screen is in *Testing* each user must be a listed test user and refresh
tokens expire after 7 days: publish the app before a study. Measurement download (below) and the webhook path
have not been run against a real Google account.
:::

Triggers and measurement download. Data points are read from `users/me/dataTypes/<id>/dataPoints` with a per-day UTC filter
(`steps`, `heart-rate`, `heart-rate-variability`, `oxygen-saturation`, `respiratory-rate-sleep-summary`, `sleep`, `weight`);
a type whose scope was not granted (HTTP 403) is skipped.

- **Sign-in:** Google OAuth 2.0; credentials in the body; `access_type=offline` + `prompt=consent` for a refresh token; 1 h access tokens
- **User id:** Not in it; `GET /v4/users/me/identity` right after sign-in (`healthUserId`). `ACCOUNT_NOT_LINKED` (no Google Health profile) aborts the link.
- **Webhook registration:** **Once per server**, as a "subscriber" with `AUTOMATIC` subscriptions; nothing happens at link time
- **Callback check:** Two POSTs `{"type":"verification"}`: with the secret expect 2xx, without it 401/403
- **Authenticity:** Secret in `Authorization` **and** an ECDSA P-256 signature (`GOOGLE-HEALTH-API-SIGNATURE`, Tink prefix) against Google's published keyset
- **Delivery:** Batches of up to 99; must answer 204 at once; retried for 7 days, so events can repeat (harmless: the cap and ledger absorb them)

Setup is described in [Setting up provider apps](#google-health-setup).

What is and is not done:

- Requested scopes: `sleep.readonly`, `activity_and_fitness.readonly`, `health_metrics_and_measurements.readonly`
  (the three Google's webhook page ties to sleep, activity and weight; its scope page gives no per-type mapping).
- **Measurement data is downloaded** by the hourly sync, as for the other providers (see the data types above), so a
  Google Health participant adds rows to `wearables.zip`.
- Unverified: whether Google's scopes count as sensitive/restricted (affecting app verification, and whether a
  project in *Testing* mode, whose refresh tokens expire, is enough), and the exact notification envelope. The
  parser accepts the plausible shapes. If genuine notifications are rejected with 403, set the server config key
  `wearables_googlehealth_skip_signature` to bypass the signature check (the secret stays required).
- The webhook route must receive the `Authorization` header. Under Apache with PHP-FPM/CGI that needs
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` or an equivalent rewrite.

- The endpoint answers 200/204 quickly (Google Health requires a 204 at once). A bad Withings key gets 403; a missing Google Health secret gets 401.
- The callback maps the provider's user id to participants by scanning the token files, then keeps the
  notification for every participant of a study with wearables enabled, **whether or not a trigger uses it**:
  a row in the event archive (`.wearables_event_archive/<user>.<provider>.events.csv`: provider, event kind, the
  time the webhook arrived, and the period it covers when the provider says so). It contains no measurement.
  The archive is in `wearables.zip` and is kept for the life of the study. Where a sensor trigger waits for
  the event it is additionally handed to the prompt scheduler through a short, three-day log. Providers retry,
  so a notification identical to a recent one (same kind and period) is stored once. Google Health `DELETE`
  notifications (a user deleting data) are not recorded.
- Subscription is best effort: if it fails, linking still succeeds and the trigger's time fallback is what
  fires. An incomplete subscription is written to the server error log.
- Disconnecting a provider deletes that participant's archived notifications for it, like the data already fetched; disconnecting the last one also drops the scheduler's event log.
- The Withings callback URL carries one key shared by all participants and appears in web server access logs;
  anyone who learns it can forge events for a Withings user id. Keep query strings out of access logs, and note
  that rotating the Withings client secret invalidates existing subscriptions (participants must re-link).
- Ceilings: the provider decides when to call the webhook (devices must sync first), so latency is not under
  ESMira's control.

Designer settings and exact semantics: [Scheduling](./scheduling.md#sensor-triggers).

## Researcher panel

Source: `sections/wearables.tsx`.

- Enable toggle (`wearablesEnabled`).
- Provider checkboxes, disabled when the server has no credentials, each with a connected-participant count.
- A **participant table** (`WearablesParticipantOverview`): every participant who has sent data or linked a device, with the
  providers they connected, the last sync date per provider and whether data arrived. Participants who have not
  connected are listed first, with a "N of M participants have connected a wearable" summary. It is built from file
  names and sync-state files, so no token is decrypted. "Last sync" is the last completed UTC day fetched, not the
  device's own upload time. A link the provider has rejected is shown as "link no longer valid, participant must
  reconnect" and no longer counts as connected (see *Revoked links* below).
- A **last sensor event** column in that table: the newest stored webhook notification (kind, provider, time). It
  proves the provider reaches this server for that participant. Every notification is kept, so "none yet" means
  none has arrived since the participant linked.
- A provider whose credentials are set but whose notifications this server cannot receive yet (for Google Health:
  no webhook secret from `wearables_setup.php googlehealth-webhook`) is flagged "sensor triggers unavailable".
  Whether Google has the subscriber registered in the Cloud project cannot be seen from here.
- A read-only box with the **redirect URI** to register.
- A **`wearables.zip`** download (read permission); the same link appears in the data list.

There is no data preview or data-type selector. Sensor triggers are
configured per questionnaire in the trigger editor, not here.

## Revoked links

A link stops working when the participant revokes access at the provider, or when the same provider account is linked
from a second participant ID (Withings, for one, allows a single active authorization per account and app, so linking
again silently invalidates the first participant's access and refresh tokens). The sync recognises a rejected token
or refresh token (`WearablesAuthException::matches`), writes a `<participant>.<provider>.revoked` marker next to the
sync state, and stops retrying that link instead of logging an error every hour. The marker is returned as
`needsReconnect` by `wearables_status.php` and the researcher panel; the participant app then offers **Reconnect**.
Saving a new token (any successful link) removes the marker, and the sync cursor was left in place, so the missed days
are fetched afterwards (up to the 14-day catch-up cap). Transient failures such as timeouts or provider outages never
set the marker.

## Participant flow and disconnect

The PWA shows a *Wearables* tile only when the study has wearables enabled **and** at least one of its
providers is configured on the server. *Connect* navigates to the provider; *Disconnect* calls
`wearables_disconnect.php`, which deletes the local token, data CSV and cursor for that provider.

## Known limitations

- **No provider-side revocation:** disconnect does not revoke the token at the provider.
- **Weak endpoint authentication:** `wearables_status.php` and `wearables_disconnect.php` check only
  `studyId` + `userId`.
- **Empty provider list mismatch:** the server treats an empty `wearablesProviders` as "all allowed"; the PWA
  treats it as "none offered".
- **Reset study does not clear wearable or push folders** (`emptyStudy`).
- **Delete behaviour of wearable folders on study deletion** was not verified.
