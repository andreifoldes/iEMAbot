#!/usr/bin/env python3
"""Probe the PVT animated tutorial pointer per device type.

Serves the local m2c2-assessments working copy and loads the PVT tutorial in
two Playwright contexts:

  * desktop (non-touch) -> expects the mouse-cursor sprite + click ring
  * touch (is_mobile)   -> expects the right-thumb sprite (unchanged)

For each, it walks Screen 1 -> Screen 2 -> Screen 3 (never pressing BEGIN),
then captures screenshots and any console errors. Screenshots are written to
tools/pvt/_pvt_cursor_shots/ for visual confirmation.

Usage:
    python3 tools/pvt/pvt_desktop_cursor_probe.py
"""

import functools
import http.server
import socketserver
import threading
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

REPO_ROOT = Path.home() / "Documents" / "Dev" / "m2c2-assessments"
OUT_DIR = Path(__file__).resolve().parent / "_pvt_cursor_shots"
TASK_PATH = "/assessments/pvt-ba/index.html?tutorial=true&embed=1&v=5"

# Relative canvas coordinates of the NEXT / BEGIN button (ref design 400x800).
BTN_X_FRAC = 200 / 400
BTN_Y_FRAC = 670 / 800


def start_server(directory: Path) -> tuple[socketserver.TCPServer, int]:
    handler = functools.partial(
        http.server.SimpleHTTPRequestHandler, directory=str(directory)
    )
    httpd = socketserver.TCPServer(("127.0.0.1", 0), handler)
    port = httpd.server_address[1]
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd, port


def click_button(page, canvas_box) -> None:
    x = canvas_box["x"] + BTN_X_FRAC * canvas_box["width"]
    y = canvas_box["y"] + BTN_Y_FRAC * canvas_box["height"]
    page.mouse.click(x, y)


def run_variant(browser, base_url: str, *, touch: bool) -> dict:
    label = "touch" if touch else "desktop"
    ctx_kwargs = dict(viewport={"width": 400, "height": 800})
    if touch:
        ctx_kwargs.update(has_touch=True, is_mobile=True)
    context = browser.new_context(**ctx_kwargs)
    page = context.new_page()

    messages: list[str] = []
    page.on(
        "console",
        lambda m: messages.append(f"[{m.type}] {m.text}"),
    )
    page.on("pageerror", lambda e: messages.append(f"[pageerror] {e}"))
    failed: list[str] = []
    page.on(
        "requestfailed",
        lambda r: failed.append(f"{r.url} :: {r.failure}"),
    )

    page.goto(base_url + TASK_PATH, wait_until="load")
    page.wait_for_selector("canvas", timeout=15000)
    time.sleep(1.8)  # let CanvasKit initialise + first paint
    box = page.query_selector("canvas").bounding_box()

    OUT_DIR.mkdir(exist_ok=True)
    page.screenshot(path=str(OUT_DIR / f"{label}_1_welcome.png"))

    click_button(page, box)  # NEXT -> screen 2
    time.sleep(1.0)
    page.screenshot(path=str(OUT_DIR / f"{label}_2_position.png"))

    click_button(page, box)  # NEXT -> screen 3 (auto-animating demo)
    time.sleep(1.2)
    page.screenshot(path=str(OUT_DIR / f"{label}_3_howitworks.png"))

    # Burst to try to catch a demo "click" (ring only lasts ~400ms).
    for i in range(18):
        time.sleep(0.22)
        page.screenshot(path=str(OUT_DIR / f"{label}_3_burst_{i:02d}.png"))

    errors = [m for m in messages if m.startswith(("[error]", "[pageerror]"))]
    warnings = [m for m in messages if m.startswith("[warning]")]
    context.close()
    return {
        "label": label,
        "errors": errors,
        "warnings": warnings,
        "failed_requests": failed,
    }


def main() -> None:
    httpd, port = start_server(REPO_ROOT)
    base_url = f"http://127.0.0.1:{port}"
    print(f"Serving {REPO_ROOT} at {base_url}")
    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch()
            for touch in (False, True):
                res = run_variant(browser, base_url, touch=touch)
                print(f"\n=== {res['label']} ===")
                print(f"  errors:          {len(res['errors'])}")
                for e in res["errors"]:
                    print(f"    {e}")
                print(f"  failed requests: {len(res['failed_requests'])}")
                for f in res["failed_requests"]:
                    print(f"    {f}")
            browser.close()
    finally:
        httpd.shutdown()
    print(f"\nScreenshots in {OUT_DIR}")


if __name__ == "__main__":
    main()
