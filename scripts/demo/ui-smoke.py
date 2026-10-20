#!/usr/bin/env python3
"""Browser smoke test of BOTH front-ends of a running ESMira demo (headless Chromium via Playwright).

  participant PWA  - invite code -> consent -> pick questionnaire -> answer -> "responses have been recorded"
  researcher admin - log in -> study list -> data table shows the answer the participant just submitted

Both pages must also finish without console/page errors (this is what catches a study the admin UI cannot parse,
or a PWA that cannot reach its API - neither is visible to the curl-based smoke.sh).

Usage:  DEMO_PASS=... ui-smoke.py BASE_URL [--user admin]
Needs:  pip install playwright && playwright install chromium
"""
import os
import sys
import uuid

from playwright.sync_api import sync_playwright

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080").rstrip("/")
USER = os.environ.get("DEMO_ADMIN", "admin")
PASSWORD = os.environ.get("DEMO_PASS")
MARKER = f"ui-smoke-{uuid.uuid4().hex[:8]}"
STUDY_ID = 4242
QUESTIONNAIRE_ID = 424201

# The PWA hides invite-code entry until it runs as an installed (standalone) app, so emulate that.
STANDALONE = """
const orig = window.matchMedia.bind(window);
window.matchMedia = q => /display-mode:\\s*standalone/.test(q)
  ? {matches: true, media: q, onchange: null, addListener(){}, removeListener(){},
     addEventListener(){}, removeEventListener(){}, dispatchEvent(){ return false }}
  : orig(q);
Object.defineProperty(navigator, 'standalone', {get: () => true});
"""

failures = []


def check(name, ok, detail=""):
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok else f"   [{detail}]"))
    if not ok:
        failures.append(name)


def watch(page, sink):
    page.on("console", lambda m: sink.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: sink.append(str(e)))


def participant_flow(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844})
    ctx.add_init_script(STANDALONE)
    page, errors = ctx.new_page(), []
    watch(page, errors)
    page.goto(f"{BASE}/pwa/")
    page.get_by_label("Study invite code").fill("demo")
    page.get_by_role("button", name="Continue").click()
    page.get_by_role("button", name="I consent").click(timeout=15000)
    bar = page.get_by_role("textbox", name="Type a response")
    page.wait_for_function("el => !el.disabled", arg=bar.element_handle(), timeout=15000)
    bar.fill("UI Smoke")
    page.get_by_role("button", name="Send").click()
    page.get_by_role("button", name="Daily check-in").click(timeout=15000)
    page.get_by_role("button", name="4", exact=True).click(timeout=15000)
    page.wait_for_function("el => !el.disabled", arg=bar.element_handle(), timeout=15000)
    bar.fill(MARKER)
    # The PWA is offline-first: "recorded" only means saved on the device. The upload to datasets.php happens
    # afterwards, so wait for it explicitly instead of closing the browser the moment the message appears.
    upload = []
    page.on("response", lambda r: upload.append(r) if "datasets.php" in r.url else None)
    # The server rate-limits uploads per participant ("Too many requests in succession") and the PWA has already
    # sent a "joined" event, so pace the final submit like a person would; the PWA shows "recorded" regardless.
    page.wait_for_timeout(3000)
    page.get_by_role("button", name="Send").click()
    try:
        page.get_by_text("your responses have been recorded").wait_for(timeout=15000)
        done = True
    except Exception:
        done = False
    for _ in range(40):
        if any(MARKER in (r.request.post_data or "") for r in upload):
            break
        page.wait_for_timeout(500)
    sent = [r for r in upload if MARKER in (r.request.post_data or "")]
    verdict = sent[-1].json() if sent else {}
    states = verdict.get("dataset", {}).get("states", [])
    check("PWA: answer uploaded and ACCEPTED by the server", bool(states) and all(st.get("success") for st in states),
          f"server said: {states or 'no upload seen'}")
    check("PWA: invite code -> consent -> questionnaire -> recorded", done, page.locator("[role=log]").inner_text()[-200:])
    check("PWA: no console errors", not errors, "; ".join(errors)[:300])
    ctx.close()


def researcher_flow(browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page, errors = ctx.new_page(), []
    watch(page, errors)
    page.goto(f"{BASE}/#admin")
    # The SPA re-renders the form once its initial GetPermissions call returns; filling earlier loses the input.
    page.wait_for_load_state("networkidle")
    page.get_by_role("textbox", name="Username").wait_for(timeout=15000)
    page.wait_for_timeout(500)
    page.get_by_role("textbox", name="Username").fill(USER)
    page.get_by_role("textbox", name="Password").fill(PASSWORD)
    page.get_by_role("button", name="Login").click()
    page.get_by_role("link", name="Show data / statistics").wait_for(timeout=15000)
    check("Admin: login shows researcher dashboard", True)

    page.goto(f"{BASE}/#admin/allStudies:data")
    page.get_by_role("link", name="ESMira demo study").first.wait_for(timeout=15000)
    check("Admin: demo study listed", True)

    # "Data table" is a download page: one CSV link per questionnaire. Check it is listed, then fetch the very
    # CSV that link points at (same session cookies) and look for the answer the participant just submitted.
    page.goto(f"{BASE}/#admin/allStudies:data/dataStatistics,id:{STUDY_ID}/dataList")
    try:
        page.get_by_text("Daily check-in").first.wait_for(timeout=20000)
        listed = True
    except Exception:
        listed = False
    check("Admin: data page lists the questionnaire", listed, page.inner_text("body")[-200:])
    csv = page.context.request.get(f"{BASE}/api/admin.php?type=GetData&study_id={STUDY_ID}&q_id={QUESTIONNAIRE_ID}")
    check("Admin: response CSV contains the participant's answer", csv.ok and MARKER in csv.text(), f"HTTP {csv.status}")
    check("Admin: no console errors", not errors, "; ".join(errors)[:300])
    ctx.close()


if not PASSWORD:
    sys.exit("Set DEMO_PASS to the admin password")

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    for flow in (participant_flow, researcher_flow):
        try:
            flow(browser)
        except Exception as exc:  # a timeout means the UI did not get where a user needs to be
            check(f"{flow.__name__} completed", False, repr(exc)[:300])
    browser.close()

print("\nUI SMOKE " + ("FAILED" if failures else "OK") + f" ({BASE})")
sys.exit(1 if failures else 0)
