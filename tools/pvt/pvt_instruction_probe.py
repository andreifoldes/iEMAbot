#!/usr/bin/env python3
"""
Diagnostic test for the PVT (pvt-ba) m2c2 cognitive task instruction screen.

Loads the task exactly as the ESMira PWA launches it (embed=1&v=3), in a FRESH
browser context (empty cache — simulates a reinstalled app) on a mobile viewport.
For three parameter variants it records:
  * the network chain actually fetched (index.html / index.js / pvt-ba.js) with
    HTTP status, byte size and Last-Modified — so we can see *which* build loads;
  * console output (the task ships debug logging) — to see the resolved params;
  * a screenshot of the first rendered screen — the ground truth of what a
    participant sees (animated tutorial vs static instructions).

Variants:
  default       -> as the PWA launches it (no tutorial override; task default)
  tutorial=true -> force the multi-screen tutorial
  tutorial=false-> force the single instruction screen

Run:  python3 tools/pvt/pvt_instruction_probe.py
Screenshots + summary land in /tmp/pvt-probe/.
"""
from __future__ import annotations

import json
import os
import re
from playwright.sync_api import sync_playwright

BASE = "https://iemabot.surrey.ac.uk/webapp/m2c2/assessments/pvt-ba/index.html"
COMMON = "show_end_screen=true&source=esmira&embed=1&v=3"
VARIANTS = {
    "default": f"{BASE}?{COMMON}",
    "tutorial-true": f"{BASE}?{COMMON}&tutorial=true",
    "tutorial-false": f"{BASE}?{COMMON}&tutorial=false",
}
OUT = "/tmp/pvt-probe"
ASSET_RE = re.compile(r"/pvt-ba/(index\.html|index\.js|pvt-ba\.js)")


def probe(pw, name: str, url: str) -> dict:
    # Fresh context => empty cache/storage, like a freshly (re)installed app.
    browser = pw.chromium.launch(headless=True)
    context = browser.new_context(
        viewport={"width": 390, "height": 844},
        device_scale_factor=3,
        is_mobile=True,
        has_touch=True,
        ignore_https_errors=True,  # internal box uses a self-signed cert over VPN
        bypass_csp=True,
    )
    page = context.new_page()

    net: list[dict] = []
    console: list[str] = []

    def on_response(resp):
        if ASSET_RE.search(resp.url):
            h = resp.headers
            net.append({
                "file": ASSET_RE.search(resp.url).group(1),
                "status": resp.status,
                "url": resp.url,
                "bytes": h.get("content-length", "?"),
                "last_modified": h.get("last-modified", "?"),
                "cache_control": h.get("cache-control", "?"),
            })

    page.on("response", on_response)
    page.on("console", lambda m: console.append(f"[{m.type}] {m.text}"))
    page.on("pageerror", lambda e: console.append(f"[pageerror] {e}"))

    try:
        page.goto(url, wait_until="load", timeout=45000)
    except Exception as e:  # noqa: BLE001 — record and continue for the other variants
        console.append(f"[goto-error] {e}")
    # Let the CanvasKit surface paint the first scene.
    page.wait_for_timeout(4000)

    shot = f"{OUT}/{name}.png"
    page.screenshot(path=shot, full_page=False)

    tutorial_logs = [c for c in console if re.search(r"tutorial|instruction|show_", c, re.I)]

    context.close()
    browser.close()
    return {
        "variant": name,
        "url": url,
        "network": net,
        "screenshot": shot,
        "console_lines": len(console),
        "tutorial_related_console": tutorial_logs[:20],
    }


def main() -> None:
    os.makedirs(OUT, exist_ok=True)
    results = []
    with sync_playwright() as pw:
        for name, url in VARIANTS.items():
            print(f"\n=== probing: {name} ===")
            r = probe(pw, name, url)
            for n in r["network"]:
                print(f"  {n['file']:12} {n['status']}  {n['bytes']} bytes  LM={n['last_modified']}  cc={n['cache_control']}")
            if r["tutorial_related_console"]:
                print("  console (tutorial-related):")
                for line in r["tutorial_related_console"]:
                    print(f"    {line}")
            print(f"  screenshot -> {r['screenshot']}")
            results.append(r)
    with open(f"{OUT}/summary.json", "w") as f:
        json.dump(results, f, indent=2)
    print(f"\nSummary written to {OUT}/summary.json")
    print(f"Screenshots in {OUT}/  (open them to compare instruction screens)")


if __name__ == "__main__":
    main()
