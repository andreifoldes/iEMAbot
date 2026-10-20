### 🚀 Added

- Added option to specify y-axis minimum for charts.
- Added sensor-triggered prompts: in the trigger editor, an event trigger can fire when a Withings or Fitbit webhook reports new sleep, activity, weight, blood-pressure or ECG data, with an optional daily window, daily cap, delay and a time-of-day fallback. Needs the new `api/wearables_webhook.php` endpoint (see the Wearables docs). Not yet verified against live provider accounts. Fitbit's legacy Web API is scheduled to shut down on 2026-10-30; an experimental, untested placeholder for its successor, the Google Health API (webhook triggers only), is included; Google is not onboarding new projects, so it has never been run.

- Sensor-trigger fallbacks can follow each participant's routine: set *Fallback time from* to **Participant variable + offset** and name a time item (for example `habitual_wake_time` in an intake questionnaire); the fallback fires at that participant's latest answer plus the offset, and at the fixed time (**If the variable is not set, send at**) while they have not answered it.

- Participant app install flow, ported from the Sleep Diary prototype: the browser's install prompt is now captured before the app loads (so the **Install app** button reliably appears); iOS Safari and Chrome (v113+, iOS 16.4+) get matching Add to Home Screen steps and older iOS / Chrome / other iOS browsers get a clear update-or-switch alert instead of steps that can't work; links opened inside WhatsApp, Instagram, Mail and similar in-app browsers are steered to a real browser; desktop visitors can scan a QR code to continue on their phone; after installing, the app says to open it from the Home Screen (with a "can't see the app?" hint) and ticks off the funnel step; and iOS participants who arrived by invite link are shown their code to write down before installing.

- Smartphone-only studies: a new study setting, **Only allow participation on a smartphone (web)**, makes the participant app show an invite page on computers and tablets instead of starting the study: what to do, the invite code and participant ID to write down, a centred QR code you can tap to enlarge, other ways to open the link (copy it, or *I'm on my phone — continue here* on touch devices), and a browser tip. Off by default; a device that already consented is never turned away. The check runs before consent and before the participant ID is claimed, so scanning the code on a phone starts clean. The hosted study page uses the same layout.

- iOS participants can enrol with the details from their link: the installed app's code-entry screen now takes an optional **Participant ID** next to the invite code (iOS gives the home-screen app empty storage, so a personalised link's participant ID used to be lost), and the "Write these down" card shows both.

### ✏️ Changed

- Server statistics and study statistics are for researchers: the public home page no longer links to them, `api/server_statistics.php` needs a researcher login, and `api/statistics.php` needs the study's access key (or a login, for a study that has none). Researchers keep both in the admin area.
- On a web-only server (Server settings, web-only mode) the About page no longer shows the Google Play and App Store badges.
- Fixed missing item subkeys for chart variables.