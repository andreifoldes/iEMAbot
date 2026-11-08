#!/usr/bin/env python3
"""A participant answers every question type the web app shows, through each type's own controls, on a phone. The admin
then downloads the answers. lab-journey.py already proves the server keeps an answer to every type; this proves that the
app can give one. esmiraAdapter's demoStudyTypes.test.mts says which types the web app shows at all.

Usage: all-types-browser.py BASE_URL --user NAME --password SECRET [--key demo] [--insecure] [--screenshots DIR]
The demo study must be on the server (scripts/demo/bootstrap.sh). Needs: pip install playwright && playwright install chromium
"""
import argparse
import csv
import io
import sys
import uuid

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeout, sync_playwright

STUDY_ID = 4242
QUESTIONNAIRE_ID = 424202
QUESTIONNAIRE = "All question types"
ANDROID = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36"
WAIT = 30000  # The app reveals its messages at reading speed.
MARK = uuid.uuid4().hex[:8]
KEYSTROKES = "I had toast and a walk this morning."
FALLBACK = "Today was quiet and I got a lot of reading done."
# Stands in for the real task page, which needs a person to tap at the right moments.
TASK = """<!doctype html><script>
  parent.postMessage({type: 'm2c2:complete', assessment: 'pvt-ba', summary: {n: 1}, data: {trials: [{response_time_ms: 300, trial_index: 0}]}}, '*');
</script>"""

failures: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok or not detail else f"   [{detail}]"))
    if not ok:
        failures.append(name)


def tap(page: Page, name: str) -> None:
    page.get_by_role("button", name=name, exact=True).last.click(timeout=WAIT)


def choose(page: Page, text: str) -> None:
    page.get_by_text(text, exact=True).last.click(timeout=WAIT)


def confirm(page: Page) -> None:
    page.get_by_role("button", name="Confirm").last.click(timeout=WAIT)


def number(page: Page, value: str) -> None:
    field = page.locator("input[type=number]").last
    field.wait_for(timeout=WAIT)
    field.fill(value)
    confirm(page)


def selects(page: Page, *values: str) -> None:
    fields = page.locator("select")
    fields.first.wait_for(timeout=WAIT)
    total = fields.count()
    for index, value in enumerate(values):
        fields.nth(total - len(values) + index).select_option(value)
    confirm(page)


def date(page: Page, value: str) -> None:
    field = page.locator("input[type=date]").last
    field.wait_for(timeout=WAIT)
    field.fill(value)
    confirm(page)


def scale(page: Page, value: str) -> None:
    field = page.locator("input[type=range]").last
    field.wait_for(timeout=WAIT)
    field.fill(value)
    confirm(page)


def write(page: Page, text: str) -> None:
    box = page.get_by_role("textbox", name="Write your answer")
    box.click(timeout=WAIT)
    page.keyboard.type(text, delay=15)  # One key at a time: the timing of the keys is what gets logged.
    page.get_by_role("button", name="Send").last.click(timeout=WAIT)


def type_answer(page: Page, text: str) -> None:
    box = page.get_by_role("textbox", name="Type a response")
    page.wait_for_function("el => !el.disabled", arg=box.element_handle(timeout=WAIT), timeout=WAIT)
    box.fill(text)
    page.get_by_role("button", name="Send").click(timeout=WAIT)


def see(page: Page, text: str) -> None:
    page.get_by_text(text).last.wait_for(timeout=WAIT)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--key", default="demo")
    parser.add_argument("--insecure", action="store_true")
    parser.add_argument("--screenshots")
    parser.add_argument("--skip-voice", action="store_true", help="skip the voice memo, which offers the typed fallback instead")
    args = parser.parse_args()
    base = args.base.rstrip("/")

    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--use-fake-ui-for-media-stream", "--use-fake-device-for-media-stream"] + (["--ignore-certificate-errors"] if args.insecure else []))
        context = browser.new_context(viewport={"width": 390, "height": 844}, user_agent=ANDROID, is_mobile=True, has_touch=True,
                                      ignore_https_errors=args.insecure, permissions=["microphone"])
        page = context.new_page()
        errors: list[str] = []
        page.on("pageerror", lambda e: errors.append(str(e)))
        uploads: list = []
        page.on("response", lambda r: uploads.append(r) if "datasets.php" in r.url or "file_uploads.php" in r.url else None)

        context.route("**/m2c2-assessments/**", lambda route: route.fulfill(status=200, content_type="text/html", body=TASK))
        current = ""
        steps = STEPS_SKIP_VOICE if args.skip_voice else STEPS
        try:
            page.goto(f"{base}/pwa/?key={args.key}")
            tap(page, "I consent")
            tap(page, QUESTIONNAIRE)
            for current, act in steps:
                act(page)
                check(current, True)
                if args.screenshots:
                    page.screenshot(path=f"{args.screenshots}/{current.split(':')[0].strip().replace(' ', '_')}.png")
            page.get_by_text("your responses have been recorded").wait_for(timeout=WAIT)
            check("the app says the answers are recorded", True)
        except PlaywrightTimeout as e:
            check(current or "the questionnaire opens", False, str(e).split("\n")[0][:200])
            if args.screenshots:
                page.screenshot(path=f"{args.screenshots}/stopped.png")
            print("      on screen: " + " | ".join(x for x in page.inner_text("body").split("\n") if x.strip())[-300:])
        check("the app has no errors", not errors, "; ".join(errors)[:300])
        check("the app does not claim to be offline", "appear to be offline" not in page.inner_text("body"))

        # "Recorded" means saved on the device: the upload comes after it, and media after that. The server keeps one
        # dataset a second from a participant, so right after the task's own upload the app may have to send again.
        def accepted_answers():
            return [r for r in uploads if "datasets.php" in r.url and MARK in (r.request.post_data or "")
                    and all(state.get("success") for state in r.json().get("dataset", {}).get("states", [{}]))]
        media_files = 1 if args.skip_voice else 2  # The typed fallback's log, or the memo; and the writing box's log.
        def media_stored():
            return sum(1 for r in uploads if "file_uploads.php" in r.url and '"success":true' in r.text())
        for _ in range(45):
            if accepted_answers() and media_stored() >= media_files:
                break
            page.wait_for_timeout(1000)
        check("the app uploads the answers until the server has kept them", bool(accepted_answers()),
              "; ".join(r.text()[:120] for r in uploads if "datasets.php" in r.url))
        check("the app uploads the media files", media_stored() >= media_files, f"{media_stored()} accepted")
        admin = browser.new_context(ignore_https_errors=args.insecure)
        admin.request.post(f"{base}/esmira/api/admin.php?type=login", form={"accountName": args.user, "pass": args.password})
        page.wait_for_timeout(4000)  # Lets the upload finish.
        response = admin.request.get(f"{base}/esmira/api/admin.php?type=GetData&study_id={STUDY_ID}&q_id={QUESTIONNAIRE_ID}")
        rows = list(csv.DictReader(io.StringIO(response.text()), delimiter=";")) if response.ok else []
        row = next((r for r in reversed(rows) if MARK in (r.get("t_text") or "")), None)
        check("the admin can download this participant's answers", row is not None, f"HTTP {response.status}, {len(rows)} rows: {response.text()[:200]}")
        if row:
            expected = dict(EXPECTED)
            if args.skip_voice:
                del expected["m_audio"]
                expected["m_audio_typed~text"] = FALLBACK
            for column, wanted in expected.items():
                actual = row.get(column, "")
                check(f"{column} is stored as {wanted or 'something'}", actual == wanted if wanted else bool(actual), f"it is '{actual}'")
        browser.close()
    print("\nALL QUESTION TYPES IN A BROWSER " + ("FAILED" if failures else "OK") + f" ({base})")
    return 1 if failures else 0


# Each question type's own controls, in the demo study's order.
STEPS = [
    ("text: shown, then Continue", lambda p: (see(p, "shows every question type"), tap(p, "Continue"))),
    ("image: shown, then Continue", lambda p: (see(p, "An image item"), tap(p, "Continue"))),
    ("likert: tap a point", lambda p: (see(p, "Likert"), tap(p, "3"))),
    ("binary: tap a side", lambda p: (see(p, "Binary"), tap(p, "No"))),
    ("list_multiple: appears because of that answer, tap two", lambda p: (see(p, "Multiple choice"), choose(p, "Family"), choose(p, "Friends"), tap(p, "Done"))),
    ("list_single: the last choice asks for text", lambda p: (see(p, "Single choice"), choose(p, "Somewhere else"), see(p, "Please describe"), p.locator("textarea, input[type=text]").last.fill("on a train"), tap(p, "Done"))),
    ("va_scale: move the slider", lambda p: (see(p, "Visual analogue"), scale(p, "70"))),
    ("text_input: type an answer", lambda p: (see(p, "Free text: anything"), type_answer(p, f"mark-{MARK}"))),
    ("text_input without a type, as the dashboard saves it", lambda p: (see(p, "without a type"), type_answer(p, "toast"))),
    ("number: a whole number", lambda p: (see(p, "Whole number"), number(p, "2"))),
    ("number: a decimal", lambda p: (see(p, "Decimal number"), number(p, "36.6"))),
    ("time: hour and minute", lambda p: (see(p, "Time: when"), selects(p, "23", "30"))),
    ("duration: hours and minutes", lambda p: (see(p, "Duration"), selects(p, "7", "30"))),
    ("date: pick a day", lambda p: (see(p, "Date: which"), date(p, "2026-11-05"))),
    ("record_audio: record, stop and save", lambda p: (see(p, "Voice memo"), tap(p, "Record voice memo"), see(p, "Tap Stop"), p.wait_for_timeout(1500), tap(p, "Stop"), tap(p, "Save"))),
    ("record_keystrokes: type in the writing box", lambda p: (see(p, "Keystroke-logged"), tap(p, "Write your answer"), write(p, KEYSTROKES))),
    ("webapp: the task opens and reports back", lambda p: (see(p, "Reaction-time game"), tap(p, "Start the Reaction-time game"), see(p, "That was every question type"))),
    ("text: the last message, then Continue", lambda p: tap(p, "Continue")),
]
VOICE = [i for i, step in enumerate(STEPS) if step[0].startswith("record_audio")][0]
STEPS_SKIP_VOICE = STEPS[:VOICE] + [
    ("record_audio: skip it", lambda p: (see(p, "Voice memo"), tap(p, "Skip"))),
    ("record_keystrokes: the typed fallback appears", lambda p: (tap(p, "Write your answer"), write(p, FALLBACK))),
] + STEPS[VOICE + 1:]
EXPECTED = {"b_likert": "3", "b_binary": "0", "b_multi~2": "1", "b_multi~3": "1", "b_single~other": "on a train", "b_vas": "70", "t_default": "toast", "t_int": "2",
            "t_dec": "36.6", "t_time": "23:30", "t_duration": "450", "t_date": "2026-11-05",
            "m_audio": "", "m_keystrokes": "", "m_keystrokes~text": KEYSTROKES, "c_pvt": ""}


if __name__ == "__main__":
    sys.exit(main())
