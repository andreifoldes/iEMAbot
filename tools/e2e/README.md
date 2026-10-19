# End-to-end test: every question type on web and Telegram

A live test case for the participant channels. One demo study holds every ESMira input type
once; two drivers answer it like a participant, one in the web PWA and one in Telegram, and the
saved rows can then be compared column by column.

## 1. Create the demo study

`tools/live-study/create-demo-all-types.php` creates (or refreshes in place) the study
**"Demo: all question types"** with access key `demoall`. It is published for the web, has the
Telegram channel on, and every item's helper text says what each channel should do, so the study
doubles as a manual checklist. Run it on the server as the web user (dry run without `apply`):

```bash
docker compose exec -T -u www-data esmira php -- apply < tools/live-study/create-demo-all-types.php
```

It prints the study id. The Telegram invite link comes from
`php cli/telegram_setup.php invite <studyId> demoall`, and the web link is `<server>/pwa/?key=demoall`.

## 2. Web PWA: `pwa_demo.mjs`

```bash
(cd web-pwa/a11y && npm ci)          # once: Playwright, shared with the accessibility audit
node tools/e2e/pwa_demo.mjs https://<server>/pwa/ demoall [screenshot.png]
```

This emulates a phone and uses a fresh participant id. It consents, picks a name and answers every
item the PWA renders through its real controls: buttons, toggles, slider, pickers, typed answers and
the keystroke writer. A headless browser has no microphone and cannot do the reaction test, so the
voice memo and the cognitive task are skipped. The script exits non-zero unless the questionnaire
completes.

## 3. Telegram: `telegram_demo.py`

This needs a Telegram **user** account through Telethon, because bots cannot message bots. Get an
api_id and api_hash from <https://my.telegram.org> and log in once, which creates a session file.

```bash
export TELEGRAM_API_ID=… TELEGRAM_API_HASH=… TELEGRAM_SESSION=/path/to/session
uv run --with telethon --with python-dotenv tools/e2e/telegram_demo.py \
  <bot_username> s<studyId>_demoall https://<server>/esmira/
```

The driver joins through the deep link, consents, sets the time zone, opens the questionnaire with
`/checkin` and answers each item with the matching kind of reply: buttons, typed text, toggles, a
voice note, a photo, a document and a location (sample files are in `media/`). Mini App items
(slider, keystroke writer, cognitive task) are answered through the live handoff API, just as the
handoff page would answer them. The script exits non-zero unless the bot confirms the answers were
saved.

## 4. Compare

Both runs write to the same questionnaire file. For every type both channels support, the columns
and value formats must match: `~1..N` for multiple choice, `~other`, minutes for durations,
`HH:MM`, `YYYY-MM-DD`, and `~text` / `~capture_mode` for keystrokes. These differences are
expected:

| Only in Telegram | Only on the web |
| --- | --- |
| dynamic_input (`~index`), photo, file_upload, location, countdown, video/share links | none |

Phone-sensor items stay empty on both channels.
