---
title: "Help development"
sidebar_label: "Help development"
description: "How to contribute to iEMAbot: translations, plugins, running and testing the server, the participant web app and the Android app, the commit and pull request conventions, and how an accepted change reaches the public repository."
---

# Help development

iEMAbot is open source (AGPL-3.0) and built for community extension. Fixes, features, tests, translations and
documentation all help. This page is the map: how to get involved, where things live, how to run and test each
part, and what a good pull request looks like. It follows the shape of ESMira's own
[Help development](https://github.com/KL-Psychological-Methodology/ESMira/wiki/Help-development) page, because
iEMAbot is a fork and the two share an engine; what differs is spelled out here.

## Talk to us first {/* #talk-first */}

- **Questions, ideas and feedback** are welcome in [Discussions](https://github.com/andreifoldes/iEMAbot/discussions).
  Not sure whether something is a bug? Start a discussion and we will work it out together.
- **A short draft pull request** to talk an idea through is fine.
- **Security.** If you think you have found a vulnerability, do not post it publicly: open a discussion asking for a
  private way to share it, without including the details.
- **Never include private details** in code, docs, issues or discussions: credentials, server addresses, study
  access keys or participant data. A scan checks every change for them, see [Security audit](./deployment/security-audit.md).

## Translations {/* #translations */}

The researcher dashboard's text lives in `src/locales/`, one JSON file per language (27 today). This part comes from
upstream ESMira, which manages those translations itself; to improve an existing language or add a new one, follow
upstream's [Translating ESMira](https://github.com/KL-Psychological-Methodology/ESMira/wiki/Translating-ESMira)
page, or send a pull request against the JSON file. Not every language file has every string, for example the German
file is shorter than `en.json`, so a pull request that fills the gaps is welcome.

The participant web app's own text is kept **apart**, in `src/locales/pwa/<code>.json`: every key starts with `pwa_`,
and `en.json` there is the source. This keeps the fork's strings out of the files upstream manages, so merging
upstream's changes does not conflict with them, and only our strings are translated for our project. English is the
fallback everywhere: a language that has not translated a string yet shows it in English, and a language with no
file shows the app in English. A study's own content (questions, descriptions) is not part of these files: it comes
from the study definition. The server's fixed notification wording (default body, "Complete by", the study-complete and
welcome push) comes from the same files, see [Web Push](./backend/channels/web-push.md#notification-language).

To translate the app into a language, add or edit `src/locales/pwa/<code>.json` (keep the placeholders `%s`, `%d`,
`{name}` and `**bold**` markers in the same order as English; a test checks this), or use the Crowdin project (the
maintainers invite translators for now). Crowdin never writes to our repositories. The maintainers move text between
the two with `node scripts/crowdin-sync.mjs`: `push` uploads the English source (`push --translations` also seeds
the languages already in the repository), and `pull` downloads translations into the files. `pull` leaves out strings
nobody has translated yet, so English shows through, and drops any string whose placeholders differ from English.
`machine <code>...` fills a language nobody has translated yet with Crowdin's machine translation (strings whose
placeholders did not survive stay English) and adds it to `src/locales/meta/pwa-machine-translated.json`, which makes
the dashboard label it "(machine-translated)", the app say so on its About screen, and the app stop picking it from
the browser's language. After people have reviewed a language, remove it from that list. The result is a normal pull
request, so the review stays with us. The script needs a Crowdin token in `CROWDIN_API_KEY`
(a gitignored `.env` file works).

## Plugins {/* #plugins */}

For specialised features you can write a plugin. The plugin API is inherited unchanged from upstream ESMira (see
`src/backend/PluginHelper.php` and `src/api/plugin_api.php`) and is described on upstream's
[ESMira Plugins](https://github.com/KL-Psychological-Methodology/ESMira/wiki/ESMira-Plugins) page. The fork has no
stable extension API of its own yet ([Current status](./current-status.md), row 17).

## Developing iEMAbot {/* #developing */}

iEMAbot has three parts, and you can work on each without the others:

| Part | What it is | Language and tools |
| --- | --- | --- |
| **The server** | Researcher designer and dashboard, API, schedule engine, push, chat channels, wearables | TypeScript front end built by webpack into `dist/`, PHP back end. No external database: data is files in `esmira_data/` plus an embedded SQLite file, see [SQLite store](./backend/sqlite-store.md). |
| **The participant web app** | The chat-style PWA that participants use | React and Vite in `web-pwa/`, built into `dist/pwa/`. See the [PWA overview](./pwa/overview.md). |
| **The Android app (Beta)** | The PWA in a Capacitor shell, plus native code for reminders, server nudges, phone sensing and keyboard timing | Java and Gradle in `android-app/`. See [Android app](./backend/channels/android-app.md). |

There is no native iOS app: iPhone participants install the web app to the Home Screen.

### Where things live {/* #layout */}

| Path | What it is |
| --- | --- |
| `src/backend/`, `src/api/`, `src/cli/` | The PHP engine, the HTTP endpoints, command-line tools. See the [backend overview](./backend/overview.md) and [API reference](./backend/api-reference.md). |
| `src/frontend/`, `src/locales/` | The researcher designer and its translations. |
| `build_configs/` | The webpack configuration (development and production). |
| `dist/` | **Build output. Never edit it:** the next build overwrites it. |
| `web-pwa/` | The participant web app. |
| `android-app/` | The Android app. Native code is in `android-app/android/app/src/main/java/org/iemabot/app/`. |
| `website/` | This documentation site (Docusaurus). |
| `test/` | PHP unit tests (`test/backend`) and fixtures shared with the PWA tests. |
| `scripts/` | The one-command demo (`scripts/demo/`) and the private-detail scanner (`scripts/security/`). |
| `tools/` | Maintainer helpers, such as the cognitive-task manifest crawler. |

[Fork divergence](./fork-divergence.md) shows what the fork changes relative to upstream ESMira, and
[Platform architecture](./architecture.md) shows how the pieces fit together.

### The fast path: shell commands only {/* #fast-path */}

Prerequisites: Git, Node.js 22 (it brings npm) and Docker. The server image bundles PHP and Apache, so you do not
need a local web server.

```bash
git clone https://github.com/andreifoldes/iEMAbot.git && cd iEMAbot

scripts/demo/demo-up.sh          # build, start, create an admin and a demo study, run the checks
                                 # -> http://localhost:8080
scripts/demo/demo-up.sh --down   # stop and delete the demo containers AND volumes
```

The demo builds this repository's own image (the root `docker-compose.yml` pulls upstream's), so it is also the
quickest check that your change works end to end. Details and what it verifies:
[Demo and verification](./deployment/demo-and-verification.md).

To iterate on a part:

```bash
npm ci                           # once, and again when dependencies change
npm run dev                      # researcher UI: builds into dist/ and rebuilds when src/ changes
npm run build:all                # production build of the researcher UI and the PWA
cd web-pwa && npm run dev        # the PWA's Vite dev server
cd website && npm ci && npm start  # this documentation site
```

To see backend or UI changes in the running demo, re-run it with `DEMO_REBUILD=1 scripts/demo/demo-up.sh`, which
rebuilds `dist/` and the image.

### The Android app {/* #android */}

You need JDK 21, the Android SDK (command-line tools or Android Studio) and Node.js 22. The build script's defaults
assume Homebrew paths, so set `JAVA_HOME` and `ANDROID_HOME` for your machine.

```bash
android-app/scripts/build.sh debug      # PWA in native mode -> Capacitor sync -> Gradle; prints the APK path
ESMIRA_DEFAULT_SERVER=https://your-server android-app/scripts/build.sh debug   # pre-fill your own server
android-app/scripts/emulator.sh <avd-name>   # boot an emulator you created (headless; EMU_WINDOW=1 for a window)
```

The script rebuilds the PWA first, so the APK is never assembled from a stale web bundle. The app is generic: it
asks which server to use on first run. Changes to the screens are changes to `web-pwa/` and reach the app with the
next build.

## Test it {/* #test */}

Run the checks that cover what you touched before you open a pull request. The maintainers' CI runs the PWA unit
tests, the backend tests and the build on every change, and the accessibility audit before every deploy; the docs build
and the private-detail scan have their own workflows.

| Check | Command | Covers |
| --- | --- | --- |
| PWA unit tests | `cd web-pwa && npm test` | Availability, relevance, keystrokes, other-specify, and the PWA half of the fixtures shared with the PHP backend |
| PWA type check | `cd web-pwa && npm run lint` | TypeScript for the app and its service worker |
| Backend tests | `phpunit test/backend` | PHP 8.3 and PHPUnit 9.6, with the extensions `gd`, `zip`, `mbstring`, `pdo_sqlite` and `sqlite3`. No Composer packages are needed. |
| Build | `npm run build:all` | Both front ends compile |
| Accessibility audit | `npm run a11y:setup` once, then `npm run a11y` | WCAG 2.2 audit of the participant PWA. **Run it for any change to the participant interface**, it gates every release. See [Accessibility](./pwa/accessibility.md). |
| Documentation build | `cd website && npm run build` | Fails on broken links and anchors |
| Private-detail scan | `node scripts/security/scan-private-details.mjs --tracked --paths` | No credentials, server addresses, study keys or participant data in the code or docs |

The full list of workflows is on [CI and release](./deployment/ci-and-release.md#workflows).

## Commits and pull requests {/* #pull-requests */}

1. **Fork** the repository and branch from `main`.
2. **Try your change** with the demo above, and run the checks from the table that apply.
3. **Use [Conventional Commits](https://www.conventionalcommits.org/)** for your messages, for example
   `fix(pwa): ...`, `feat(study): ...` or `docs(website): ...`. Common scopes are `pwa`, `push`, `study`, `invite`,
   `deploy`, `website`.
4. **Open a pull request** against `main`. Say what changed, why, and how you tested it. Keep it to one change,
   and leave the version number in `package.json` alone, the maintainers bump it.
5. **Keep the interface accessible.** Changes to the participant app are checked against WCAG 2.2 AA.

### How an accepted change reaches the public repository {/* #how-it-ships */}

The `main` branch of the public repository is updated only by a maintainer-run *promote* step, which publishes a
reviewed snapshot of the maintainers' private staging repository after a private-detail scan. So a pull request is
not merged with the merge button: once it is accepted, a maintainer applies it to the staging repository, where it
is tested and deployed, and it appears in the public repository with the next promote. See
[CI and release](./deployment/ci-and-release.md) for how that pipeline works.

## Good to know {/* #good-to-know */}

- **Study data is plain files.** Everything a study collects sits under `esmira_data/` (and is mirrored in an
  embedded SQLite file). In the demo it lives in a Docker volume, which `demo-up.sh --down` deletes.
- **Line endings.** `src/frontend/ts/constants/urls.ts` uses Windows (CRLF) line endings, inherited from upstream.
  Keep them, or your editor will turn a one-line change into a whole-file diff.
- **CI installs with `npm ci --ignore-scripts`.** The `canvas` package has no build here and webpack stubs it
  (`build_configs/canvas-stub.js`). Use the same flag if an install step fails on your machine.
- **Cognitive tasks are cached by version.** A changed task needs its `v=N` bumped *and* the PWA rebuilt and
  redeployed before participants see it, see [Cognitive tasks](./backend/question-types/cognitive-tasks.md#cache-busting-rule).
- **The numbers on [Fork divergence](./fork-divergence.md) are computed from git** when this site is built. Do not
  edit them by hand.
- **Appearance of the data.** Responses are stored with the channel they came from (`appType` `Web`, `AndroidApp`
  or `Telegram`), so you can tell which front end produced a row.

## Where help is most useful {/* #where-to-help */}

The [Current status](./current-status.md) page lists, claim by claim, what is shipped, partial or not yet built.
Gaps that this site already records:

- **Manual screen-reader passes** (VoiceOver and TalkBack) of the participant app. The automated audit is in place,
  the manual pass is not done ([Accessibility](./pwa/accessibility.md)).
- **Phone sensing on real devices.** It is implemented end to end but not yet verified on them
  ([Phone sensing](./backend/channels/phone-sensing.md)).
- **Telegram in a live study.** The channel is covered by tests against a fake Bot API but has not yet run with a
  live bot ([Chat channels](./backend/channels/chat-channels.md)).
- **More delivery channels and wearable providers.** The channel layer is built to be extended to WhatsApp and SMS,
  and a wearable provider is one class behind a common interface ([Wearables](./backend/wearables.md)).
- **A translation layer for the participant app**, see [Translations](#translations).
- **Dyadic EMA** (participant plus caregiver) does not exist yet.
