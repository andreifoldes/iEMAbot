#!/usr/bin/env python3
"""The promise that matters most to a study: an answer survives the app being closed while it is being uploaded.

A participant answers, the server gets the upload but its reply is lost, and the app is closed. On reopening, the app sends it
again, as it must, since it cannot know. The server must recognise it: the answer is stored exactly once.

Usage: upload-failsafe-browser.py BASE_URL --user NAME --password SECRET [--key demo] [--insecure]
The demo study must be on the server (scripts/demo/bootstrap.sh). Takes about 3 minutes: an attempt that was cut off is only
retried once it cannot still be running. Needs: pip install playwright && playwright install chromium
"""
import argparse
import csv
import io
import sys
import uuid

from playwright.sync_api import TimeoutError as PlaywrightTimeout, sync_playwright

STUDY_ID = 4242
QUESTIONNAIRE_ID = 424201
ANDROID = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36"
WAIT = 30000
MARK = f"survives-{uuid.uuid4().hex[:8]}"
RETRY_WITHIN_SECONDS = 210  # SENDING_EXPIRES_MS, plus the minute until the app next looks at its queue.

failures: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok or not detail else f"   [{detail}]"))
    if not ok:
        failures.append(name)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--key", default="demo")
    parser.add_argument("--insecure", action="store_true")
    args = parser.parse_args()
    base = args.base.rstrip("/")

    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--use-fake-ui-for-media-stream", "--use-fake-device-for-media-stream"] + (["--ignore-certificate-errors"] if args.insecure else []))
        context = browser.new_context(viewport={"width": 390, "height": 844}, user_agent=ANDROID, is_mobile=True, has_touch=True,
                                      ignore_https_errors=args.insecure, permissions=["microphone"])
        page = context.new_page()
        try:
            page.goto(f"{base}/pwa/?key={args.key}")
            page.get_by_role("button", name="I consent").click(timeout=WAIT)
            page.get_by_role("button", name="Daily check-in").click(timeout=WAIT)
            page.get_by_role("button", name="4", exact=True).click(timeout=WAIT)
            box = page.get_by_role("textbox", name="Type a response")
            page.wait_for_function("el => !el.disabled", arg=box.element_handle(timeout=WAIT), timeout=WAIT)
            box.fill(MARK)

            # The upload reaches the server, and its reply never comes back: the worst moment for the app to be closed.
            hung: list = []
            def hang(route):
                if MARK in (route.request.post_data or ""):
                    route.fetch()
                    hung.append(route)  # The app is never told.
                else:
                    route.continue_()
            context.route("**/datasets.php", hang)
            page.wait_for_timeout(3000)
            page.get_by_role("button", name="Send").click(timeout=WAIT)
            for _ in range(60):
                if hung:
                    break
                page.wait_for_timeout(500)
            check("the upload reached the server, and the reply is lost", bool(hung))
            saved = page.evaluate("localStorage.getItem('esmira_submit_queue') || ''")
            check("the answer is already saved on the device while it is being sent", MARK in saved)
            page.close()
            check("the app is closed with the upload unfinished", True)

            context.unroute_all(behavior="ignoreErrors")
            uploads: list = []
            page = context.new_page()
            page.on("response", lambda r: uploads.append(r) if "datasets.php" in r.url and MARK in (r.request.post_data or "") else None)
            page.goto(f"{base}/pwa/?key={args.key}")
            for _ in range(RETRY_WITHIN_SECONDS):
                if uploads:
                    break
                page.wait_for_timeout(1000)
            states = uploads[-1].json().get("dataset", {}).get("states", []) if uploads else []
            check("reopened, the app sends it again, and the server says it has it", bool(states) and all(s.get("success") for s in states), f"{states or 'no upload seen'}")
            page.wait_for_timeout(2000)
            left = page.evaluate("localStorage.getItem('esmira_submit_queue') || ''")
            check("then it leaves the device's queue", MARK not in left)
        except PlaywrightTimeout as e:
            check("the journey got to the end", False, str(e).split("\n")[0][:200])

        admin = browser.new_context(ignore_https_errors=args.insecure)
        admin.request.post(f"{base}/esmira/api/admin.php?type=login", form={"accountName": args.user, "pass": args.password})
        response = admin.request.get(f"{base}/esmira/api/admin.php?type=GetData&study_id={STUDY_ID}&q_id={QUESTIONNAIRE_ID}")
        rows = list(csv.DictReader(io.StringIO(response.text()), delimiter=";")) if response.ok else []
        stored = [r for r in rows if r.get("note") == MARK]
        check("the server holds the answer exactly once", len(stored) == 1, f"{len(stored)} rows: {response.text()[:160] if not rows else ''}")
        browser.close()
    print("\nUPLOAD FAILSAFE " + ("FAILED" if failures else "OK") + f" ({base})")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
