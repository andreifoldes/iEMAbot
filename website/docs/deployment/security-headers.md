---
title: "Security headers"
sidebar_label: "Security headers"
description: "Which HTTP security headers the image sends, what the report-only Content-Security-Policy contains, how to watch it, and the HSTS line the reverse proxy has to add."
---

# Security headers

<span className="status status--shipped">Shipped</span> (headers) · <span className="status status--partial">Partial</span> (the Content-Security-Policy is report-only)

The image sends a small set of security headers on **every** response of the container: PHP pages, the API,
static files and the participant PWA under `/pwa/`. They are set in `src/.htaccess` (copied to the web root) and,
for one page, in `web-pwa/public/.htaccess`. The Dockerfile enables Apache's `mod_headers`, which the base image
does not. Without the module the `Header` lines are silently ignored (that includes the older "do not cache" rule
for the PWA's service worker, which is now active too).

| Header | Value | Effect |
| --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` | The browser does not guess a file's type, so an upload cannot be run as a script. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Other sites only learn the origin, never a study link with its key. |
| `Content-Security-Policy` | `frame-ancestors 'self'` | **Enforced.** Other sites cannot put the admin pages or the PWA in a frame (clickjacking). |
| `X-Frame-Options` | `SAMEORIGIN` | The same rule for old browsers. |
| `Content-Security-Policy-Report-Only` | see below | **Not enforced.** The browser logs what the policy would block. |

Only `frame-ancestors` is enforced, because a report-only policy ignores that directive and because nothing
legitimately frames these pages from another site. The exception is `/pwa/handoff.html`, the page a chat message
opens as a Telegram Mini App. Telegram's web clients show it in an iframe, so that one file allows
`https://web.telegram.org` and `https://*.telegram.org` and sends no `X-Frame-Options`. The cognitive tasks are
framed by the PWA (same origin), not the other way round.

## The report-only policy

```
default-src 'self';
script-src 'self' blob:;
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob: https:;
media-src 'self' data: blob:;
font-src 'self' data:;
connect-src 'self' https://raw.githubusercontent.com https://api.github.com https://kl-psychological-methodology.github.io;
worker-src 'self' blob:;
manifest-src 'self';
frame-src 'self' https:;
object-src 'none'; base-uri 'self'; form-action 'self'
```

`handoff.html` adds `https://telegram.org` to `script-src` (the Telegram Mini App script). Scripts may not be
inline and `eval` is not allowed. The start page used to start the app from an inline `onload` handler; it now
passes its arguments in a `data-esmira-init` attribute that the bundle reads (`src/frontend/ts/index.ts`).

Why the looser parts are there:

- `blob:` for scripts and workers: plugin code is loaded from an object URL (`Requests.ts`). Voice memos and
  recordings play from blobs.
- The three GitHub hosts: the admin's "about" text, the update check and the news list.
- `https:` for images: pictures in the news list and in study texts. `frame-src https:`: a study's cognitive task
  can be any https page the researcher names.
- `'unsafe-inline'` for styles: React and Mithril set style attributes, and `index_nojs.php` has a `<style>` block.
  Removing it needs a nonce or a rewrite of those styles, and styles are a much smaller risk than scripts.

### What is still inline

Pages that would produce reports (all expected): `pwa/invite-generator.html` (one inline `<script>` and an inline
`<style>`; a researcher tool, not for participants) and `index_nojs.php` / `AppInstall.php` (inline `style`
attributes, covered by `'unsafe-inline'` for styles). No page sets an inline event handler any more.

### Where the policy does not reach

The task pages under `/webapp/` come from a separate host behind the reverse proxy, so these headers never touch
them. Do **not** add this policy to that location at the proxy: m2c2kit tasks use inline scripts and WebAssembly and
need their own policy, worked out in a browser against the real tasks first.

## Watching the violations

A report-only policy only helps if somebody reads the reports. At present nothing collects them: there is no
`report-uri`, because an open endpoint that anyone can post to would need rate limiting and would receive study
links with access keys in them. Until a collector exists, load the admin pages and the PWA in a browser with the dev
tools open and look for console messages that start with "Content-Security-Policy". Only after a period without
unexplained violations should the header be renamed to `Content-Security-Policy` (tracker item 9 in
`plan/security-audit-tracker.md`).

## HSTS: set it on the reverse proxy

`Strict-Transport-Security` is deliberately **not** in the image. TLS ends at the reverse proxy, so Apache sees
plain HTTP and cannot tell a secure request from one a client faked with `X-Forwarded-Proto`; a wrong header on an
HTTP response is ignored by browsers at best. The proxy knows the connection is secure, so it sends the header.
Start with a short lifetime, because a browser that has seen the header refuses plain HTTP for that long:

```
# nginx (inside the server block that terminates TLS)
add_header Strict-Transport-Security "max-age=300" always;

# Caddy (inside the site block)
header Strict-Transport-Security "max-age=300"
```

If nothing breaks for a week, raise `max-age` to `31536000` (one year). Add `includeSubDomains` only when every
subdomain of the host name is served over https, and leave `preload` out. The proxy must not repeat the other
headers above, or the browser receives each twice.

## Checking a deployment

```bash
curl -sI https://<host>/ | grep -iE 'content-security|x-frame|x-content-type|referrer|strict-transport'
curl -sI https://<host>/pwa/handoff.html | grep -iE 'content-security|x-frame'
```

The first call shows five headers from Apache and, once the proxy is set up, HSTS. If none appear, the web server
ignores `.htaccess` (`AllowOverride` must include `FileInfo`) or `mod_headers` is off.
