#!/usr/bin/env python3
"""
Regression test for the "stale PVT / no animated tutorial" bug.

Why the earlier probe (pvt_instruction_probe.py) was not enough: it loaded the
task in a FRESH browser with no service worker, so it could never reproduce the
real failure. On affected phones the retiring iEMAbot app left a ROOT-scoped
('/') service worker that serves everything under /webapp/m2c2/* CACHE-FIRST —
it shadows the cognitive-task iframe (even inside the ESMira PWA) and pins a
stale copy of the assessment that no ?v= bump or PWA reinstall can dislodge.

This test reproduces exactly that on localhost (a secure context for service
workers) with a real Chromium + a real service worker, then verifies the fix:

  PART A — local SW regression (deterministic, no network):
    1. register a faithful clone of the iEMAbot root SW (cache-first /webapp/m2c2/*)
    2. load the PVT while the task server serves the OLD ("STATIC") build  -> cached
    3. flip the server to the NEW ("TUTORIAL") build
    4. reload the PVT  -> SW STILL serves STATIC        (== the bug, reproduced)
    5. run the ESMira PWA cleanup (mirrors web-pwa/src/main.tsx):
         unregister root-scoped SWs + purge m2c2-* caches
    6. reload the PVT  -> now serves TUTORIAL           (== the fix, verified)

  PART B — live smoke (best-effort; SKIPs if the server is unreachable):
    * the deployed /pwa/ bundle contains the root-SW cleanup
    * the live pvt-ba.js is the tutorial build and defaults show_tutorial=true

Run:  python3 tools/pvt/pvt_sw_regression_test.py
Exit code 0 = all core (Part A) assertions passed.
"""
from __future__ import annotations

import ssl
import sys
import threading
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from playwright.sync_api import sync_playwright

# ── Mutable server state: which task build the origin currently serves ──────────
STATE = {"task": "STATIC"}  # flipped to "TUTORIAL" mid-test

# Faithful minimal clone of iema-bot/src/static/sw.js: root scope, cache-first for
# /webapp/m2c2/*, cache name 'm2c2-v1', skipWaiting + clients.claim().
ROGUE_SW = """
const M2C2_CACHE = 'm2c2-v1';
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (url.pathname.startsWith('/webapp/m2c2/')) {
    e.respondWith(
      caches.match(e.request).then(function (cached) {
        if (cached) return cached;
        return fetch(e.request).then(function (resp) {
          if (resp.ok) { var c = resp.clone(); caches.open(M2C2_CACHE).then(function (cache) { cache.put(e.request, c); }); }
          return resp;
        });
      })
    );
  }
});
"""

# Mirrors the root-SW cleanup shipped in web-pwa/src/main.tsx (v3.6.15).
PWA_CLEANUP_PAGE = """<!doctype html><html><head><meta charset=utf-8><title>pwa</title></head>
<body><div id=status>cleanup...</div><script type=module>
(async () => {
  try {
    const regs = await navigator.serviceWorker.getRegistrations();
    await Promise.all(regs
      .filter(r => { try { return new URL(r.scope).pathname === '/'; } catch { return false; } })
      .map(r => r.unregister()));
  } catch (e) {}
  try {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k.startsWith('m2c2')).map(k => caches.delete(k)));
  } catch (e) {}
  document.getElementById('status').textContent = 'cleanup-done';
})();
</script></body></html>"""

PVT_INDEX = """<!doctype html><html><head><meta charset=utf-8><title>PVT</title></head>
<body><div id=screen>loading</div><script src="./task.js"></script></body></html>"""


def _task_js() -> str:
    v = STATE["task"]
    return f'document.getElementById("screen").textContent = {v!r} + " screen";'


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):  # silence per-request logging
        pass

    def _send(self, body: str, ctype: str, extra: dict | None = None) -> None:
        data = body.encode()
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        # Mirror prod: assets are no-cache. The whole point is that a cache-first
        # SW ignores this — so it must NOT save us in the reproduction.
        self.send_header("Cache-Control", "no-cache")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):  # noqa: N802
        path = self.path.split("?", 1)[0]
        if path == "/sw.js":
            self._send(ROGUE_SW, "application/javascript", {"Service-Worker-Allowed": "/"})
        elif path in ("/", "/index.html"):
            self._send("<!doctype html><title>root</title><body>root</body>", "text/html")
        elif path in ("/pwa/", "/pwa/index.html"):
            self._send(PWA_CLEANUP_PAGE, "text/html")
        elif path == "/webapp/m2c2/assessments/pvt-ba/index.html":
            self._send(PVT_INDEX, "text/html")
        elif path == "/webapp/m2c2/assessments/pvt-ba/task.js":
            self._send(_task_js(), "application/javascript")
        elif path.startswith("/webapp/m2c2/"):
            self._send("/* stub */", "application/javascript")  # keep any addAll happy
        else:
            self.send_error(404)


def _screen_text(page) -> str:
    return page.eval_on_selector("#screen", "el => el.textContent") or ""


def run_local_regression() -> list[tuple[str, bool, str]]:
    results: list[tuple[str, bool, str]] = []
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    port = server.server_address[1]
    base = f"http://127.0.0.1:{port}"
    threading.Thread(target=server.serve_forever, daemon=True).start()
    pvt = f"{base}/webapp/m2c2/assessments/pvt-ba/index.html"

    def check(name: str, ok: bool, detail: str = "") -> None:
        results.append((name, ok, detail))

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        ctx = browser.new_context()  # isolated storage; serviceWorkers allowed by default
        page = ctx.new_page()

        # 1. Register the rogue root-scoped SW and wait until it controls the origin.
        STATE["task"] = "STATIC"
        page.goto(f"{base}/", wait_until="load")
        page.evaluate(
            """async () => {
                await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                await navigator.serviceWorker.ready;
            }"""
        )

        # 2. Load the PVT (STATIC build). The SW claims + caches it.
        page.goto(pvt, wait_until="load")
        page.wait_for_timeout(600)  # let the async cache.put settle
        controlled = page.evaluate("() => !!navigator.serviceWorker.controller")
        base_screen = _screen_text(page)
        check("rogue SW controls the PVT iframe path", controlled,
              "controller present" if controlled else "NOT controlled — repro invalid")
        check("baseline shows the OLD (STATIC) build", base_screen == "STATIC screen", base_screen)

        # 3. Server now ships the NEW (TUTORIAL) build.
        STATE["task"] = "TUTORIAL"
        server_now = urllib.request.urlopen(f"{pvt.rsplit('/', 1)[0]}/task.js", timeout=5).read().decode()
        check("server itself now serves TUTORIAL (control)", "TUTORIAL" in server_now,
              "origin returns TUTORIAL task.js")

        # 4. Reload the PVT — cache-first SW keeps serving the stale STATIC build.
        page.goto(pvt, wait_until="load")
        stale_screen = _screen_text(page)
        check("BUG REPRODUCED: stale SW still serves STATIC after server updated",
              stale_screen == "STATIC screen", stale_screen)

        # 5. Apply the fix: the ESMira PWA startup cleanup.
        page.goto(f"{base}/pwa/", wait_until="load")
        page.wait_for_function("() => document.getElementById('status')?.textContent === 'cleanup-done'",
                               timeout=5000)

        # 6. Reload the PVT — root SW is gone + m2c2 cache purged -> live TUTORIAL.
        page.goto(pvt, wait_until="load")
        fixed_screen = _screen_text(page)
        check("FIX VERIFIED: after cleanup the PVT serves the TUTORIAL build",
              fixed_screen == "TUTORIAL screen", fixed_screen)

        ctx.close()
        browser.close()
    server.shutdown()
    return results


def run_live_smoke() -> list[tuple[str, bool, str]]:
    origin = "https://iemabot.surrey.ac.uk"
    out: list[tuple[str, bool, str]] = []
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE  # internal box uses a self-signed cert over VPN

    def get(url: str) -> str:
        return urllib.request.urlopen(url, timeout=20, context=ctx).read().decode("utf-8", "replace")

    try:
        pwa = get(f"{origin}/pwa/")
        import re
        m = re.search(r"/pwa/assets/index-[A-Za-z0-9_-]+\.js", pwa)
        bundle = get(f"{origin}{m.group(0)}") if m else ""
        out.append(("live PWA bundle contains root-SW cleanup",
                    "getRegistrations" in bundle and "pathname" in bundle,
                    m.group(0) if m else "bundle not found"))
    except Exception as e:  # noqa: BLE001
        out.append(("live PWA bundle check", None, f"SKIP ({e})"))

    try:
        js = get(f"{origin}/webapp/m2c2/assessments/pvt-ba/pvt-ba.js?v=3")
        ok = "TutorialScenes" in js and "show_tutorial" in js
        default_true = "show_tutorial: {" in js and js.split("show_tutorial: {", 1)[1][:60].find("default: true") != -1
        out.append(("live pvt-ba.js is the tutorial build (default on)", ok and default_true,
                    f"TutorialScenes={ 'TutorialScenes' in js } default_true={default_true}"))
    except Exception as e:  # noqa: BLE001
        out.append(("live pvt-ba.js check", None, f"SKIP ({e})"))
    return out


def _print(title: str, rows: list[tuple[str, bool, str]]) -> None:
    print(f"\n=== {title} ===")
    for name, ok, detail in rows:
        mark = "SKIP" if ok is None else ("PASS" if ok else "FAIL")
        print(f"  [{mark}] {name}" + (f"  — {detail}" if detail else ""))


def main() -> None:
    core = run_local_regression()
    _print("PART A — local service-worker regression (core)", core)
    live = run_live_smoke()
    _print("PART B — live smoke (best-effort)", live)

    failed = [n for n, ok, _ in core if ok is False]
    if failed:
        print(f"\nRESULT: FAIL — {len(failed)} core assertion(s) failed: {failed}")
        sys.exit(1)
    print("\nRESULT: PASS — the rogue-SW bug reproduces and the ESMira cleanup fixes it.")


if __name__ == "__main__":
    main()
