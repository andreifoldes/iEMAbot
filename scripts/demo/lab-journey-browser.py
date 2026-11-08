#!/usr/bin/env python3
"""What only a browser can show: a new lab's admin makes a study by hand and sends out the link the dashboard offers, and
a participant signs up from that link on a phone. Nothing is prepared: no imported study, no typed-in link, no pretending
that the app is installed. lab-journey.py covers everything the API can reach, in far less time.

Usage: lab-journey-browser.py BASE_URL --user NAME --password SECRET [--insecure] [--screenshots DIR]
Needs: pip install playwright && playwright install chromium
"""
import argparse
import sys
import uuid

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeout, sync_playwright

ANDROID = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36"
STUDY = f"Journey {uuid.uuid4().hex[:6]}"
QUESTIONNAIRE = "Morning check-in"
ITEM = "sleep_quality"
QUESTION = "How did you sleep?"
ACCESS_KEY = f"journey{uuid.uuid4().hex[:8]}"
ANSWER = f"slept-well-{uuid.uuid4().hex[:8]}"
# The dashboard asks for names through the browser's prompt.
PROMPTS = {
    "Please select a study name:": STUDY,
    "Please select a questionnaire title": QUESTIONNAIRE,
    "Please select a variable name (it has to be unique over all questionnaires):": ITEM,
}

failures: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok or not detail else f"   [{detail}]"))
    if not ok:
        failures.append(name)


def answer_prompt(dialog) -> None:
    # Whatever else it asks for is the new access key, or a confirmation.
    dialog.accept(PROMPTS.get(dialog.message, ACCESS_KEY))


class Journey:
    def __init__(self, browser, base: str, args) -> None:
        self.browser, self.site, self.args = browser, base.rstrip("/"), args
        self.admin_url = f"{self.site}/esmira"

    def shot(self, page: Page, name: str) -> None:
        if self.args.screenshots:
            page.screenshot(path=f"{self.args.screenshots}/{name}.png", full_page=True)

    def create_and_publish(self) -> tuple[str, Page]:
        """Returns the link the dashboard offers for participants."""
        context = self.browser.new_context(viewport={"width": 1280, "height": 1000}, ignore_https_errors=self.args.insecure)
        # What the copy button copies is what the lab would send out.
        context.add_init_script("""
          window.__copied = [];
          Object.defineProperty(navigator, 'clipboard', {value: {writeText: t => { window.__copied.push(t); return Promise.resolve(); }}});
        """)
        page = context.new_page()
        page.on("dialog", answer_prompt)
        errors: list[str] = []
        page.on("pageerror", lambda e: errors.append(str(e)))

        page.goto(f"{self.admin_url}/#admin")
        page.wait_for_load_state("networkidle")
        page.get_by_role("textbox", name="Username").wait_for(timeout=15000)
        page.wait_for_timeout(500)  # The form is drawn again once the first request returns.
        page.get_by_role("textbox", name="Username").fill(self.args.user)
        page.get_by_role("textbox", name="Password").fill(self.args.password)
        page.get_by_role("button", name="Login").click()
        page.get_by_role("link", name="Show data / statistics").wait_for(timeout=15000)
        check("the admin can log in", True)

        page.get_by_text("Create", exact=True).first.click()
        page.get_by_text("Empty study").first.click()
        page.get_by_text("Publication, Links").first.wait_for(timeout=15000)
        check("the admin can create a study", True)
        check("no Telegram panel without a bot set up", page.get_by_role("link", name="Telegram").count() == 0)
        page.wait_for_timeout(500)
        study_url = page.url.split("/qEdit")[0]

        page.get_by_text("Create", exact=True).nth(1).click()
        page.get_by_text("Empty questionnaire").first.click()
        page.get_by_text("Add item").first.wait_for(timeout=15000)
        page.wait_for_timeout(500)
        self.questionnaire_id = page.url.split("qEdit,q:")[1].split("/")[0]
        page.get_by_text("Add item").first.click()
        page.get_by_text("Must be filled out").first.wait_for(timeout=15000)
        # Everything else about the question stays at its default: that is how most questions get made.
        editor = page.locator("xpath=//*[contains(text(),'Shown text')]/following::*[@contenteditable='true'][1]").first
        editor.click()
        editor.type(QUESTION)
        check("the admin can add a questionnaire and a question", True)
        self.shot(page, "1-question")

        page.goto(f"{study_url}/publish")
        page.get_by_text("Study is published", exact=True).first.click(timeout=15000)
        page.get_by_text("Add access key").first.click(timeout=15000)
        page.get_by_text("Link for participants").first.wait_for(timeout=15000)
        page.get_by_text("Save", exact=True).first.click()
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(1000)
        check("the admin can publish it, and the dashboard offers a link for participants", True)
        self.shot(page, "2-publish")

        check("the page does not send participants to another project's app", "installation instructions" not in page.inner_text("body").lower())
        offered = page.locator("label", has_text="/pwa/").first
        text = offered.inner_text()
        offered.locator("svg").last.dispatch_event("click")  # A tooltip covers the button while the pointer is near.
        page.wait_for_timeout(200)
        copied = page.evaluate("window.__copied")
        check("the link can be copied", bool(copied), text[:200])
        check("the admin page has no errors", not errors, "; ".join(errors)[:300])
        return (copied[-1] if copied else ""), page

    def sign_up(self, link: str) -> None:
        context = self.browser.new_context(viewport={"width": 390, "height": 844}, user_agent=ANDROID, is_mobile=True, has_touch=True, ignore_https_errors=self.args.insecure)
        page = context.new_page()
        errors: list[str] = []
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        uploads: list = []
        page.on("response", lambda r: uploads.append(r) if "datasets.php" in r.url else None)
        page.goto(link)
        page.wait_for_timeout(1500)
        self.shot(page, "3-participant-opens-link")
        check("the link opens the study on a phone", STUDY in page.inner_text("body"), page.inner_text("body")[:200])
        # Consent comes first when the study has a consent form; a new study does not.
        consent = page.get_by_role("button", name="I consent")
        if consent.count():
            consent.click()
        page.get_by_role("button", name=QUESTIONNAIRE).click(timeout=20000)
        check("the participant can sign up and start the questionnaire", True)
        self.shot(page, "4-questionnaire")
        page.get_by_text(QUESTION).first.wait_for(timeout=15000)
        check("the participant sees the question", True)
        box = page.get_by_role("textbox", name="Type a response")
        page.wait_for_function("el => !el.disabled", arg=box.element_handle(timeout=15000), timeout=15000)
        box.fill(ANSWER)
        page.wait_for_timeout(3000)  # The server limits uploads per participant, and joining was one.
        page.get_by_role("button", name="Send").click()
        page.get_by_text("your responses have been recorded").wait_for(timeout=20000)
        check("the participant's answer is recorded", True)
        for _ in range(40):
            if any(ANSWER in (r.request.post_data or "") for r in uploads):
                break
            page.wait_for_timeout(500)
        sent = [r for r in uploads if ANSWER in (r.request.post_data or "")]
        states = sent[-1].json().get("dataset", {}).get("states", []) if sent else []
        check("and the server accepts it", bool(states) and all(s.get("success") for s in states), f"{states or 'no upload seen'}")
        check("the participant's page has no errors", not errors, "; ".join(errors)[:300])
        self.shot(page, "5-recorded")

    def see_the_answer(self, page: Page) -> None:
        study_id = page.url.split("studyEdit,id:")[1].split("/")[0]
        page.goto(f"{self.admin_url}/#admin/allStudies:data/dataStatistics,id:{study_id}/dataList")
        page.get_by_text(QUESTIONNAIRE).first.wait_for(timeout=20000)
        self.shot(page, "6-data")
        # What its download button fetches, with the admin's session.
        response = page.context.request.get(f"{self.admin_url}/api/admin.php?type=GetData&study_id={study_id}&q_id={self.questionnaire_id}")
        check("the admin can download the participant's answer", response.ok and ANSWER in response.text(), f"HTTP {response.status}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--insecure", action="store_true")
    parser.add_argument("--screenshots")
    args = parser.parse_args()
    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--ignore-certificate-errors"] if args.insecure else [])
        journey = Journey(browser, args.base, args)
        try:
            link, admin_page = journey.create_and_publish()
            print(f"      link offered: {link}")
            journey.sign_up(link)
            journey.see_the_answer(admin_page)
        except PlaywrightTimeout as e:
            check("the journey got to the end", False, str(e).split("\n")[0][:300])
        browser.close()
    print("\nLAB JOURNEY IN A BROWSER " + ("FAILED" if failures else "OK") + f" ({args.base})")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
