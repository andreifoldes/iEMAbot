#!/usr/bin/env python3
"""A study that only lets listed participant ids in, end to end: the server over its own HTTP API, and the participant's
app in a browser.

  - the server refuses an upload for an id that is not on the list, and stores nothing for it;
  - a participant who joined while the study was open keeps working when it gets a list;
  - a link with a listed id goes straight on; a link with an unlisted id, or none, asks for the id, says so when it is
    not recognised, and stores nothing until the server has let an id in; a typed id in another letter case becomes the
    listed spelling;
  - with the link's id switched off, the id is always typed;
  - the researcher's side: the Participant IDs page of the study editor (list, random ids, save, download of links);
  - one device at a time per id: the first device holds it, another is refused as a whole (error 409), a transfer, a release;
  - the same in the participant's app, on two phones: the second is offered the transfer, the first one's waiting answers are
    kept while it is refused and are sent once it takes the id back, and a study that allows several devices never asks.

Usage: restricted-ids-browser.py BASE_URL --user NAME --password SECRET [--insecure]
Needs: pip install playwright && playwright install chromium
Every study it makes is its own and is deleted afterwards.
"""
import argparse
import importlib.util
import json
import random
import sys
import time
import uuid
from pathlib import Path

from playwright.sync_api import TimeoutError as PlaywrightTimeout, sync_playwright

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
ANDROID = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36"

# The API client of the other journey: one cookie jar, JSON in and out.
_spec = importlib.util.spec_from_file_location("lab_journey", HERE / "lab-journey.py")
lab = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(lab)
check = lab.check
failures = lab.failures


def make_study(admin, args, label: str, mode: str, from_url: bool = True, devices: str = "multi") -> dict:
    """A published one-questionnaire study of its own."""
    study = json.loads((ROOT / "demo" / "demo-study.json").read_text())["_"]
    study_id = random.randint(10_000_000, 99_999_999)
    key = f"ids{uuid.uuid4().hex[:10]}"
    study["questionnaires"] = study["questionnaires"][:1]
    study["questionnaires"][0]["internalId"] = study_id * 100
    study.update({"id": study_id, "accessKeys": [key], "title": f"Ids {label} {key[-5:]}", "published": True, "publishedWeb": True,
                  "pidMode": mode, "pidFromUrl": from_url, "pidDevices": devices, "serverVersion": lab.SERVER_VERSION, "packageVersion": "0", "version": 1})
    saved = admin.admin("SaveStudy", f"&study_id={study_id}&lastChanged=9999999999", data={"_": study})
    check(f"the admin can create the {label} study", saved.get("success") is True, str(saved)[:300])
    return {"id": study_id, "key": key, "title": study["title"], "json": study, "questionnaire": study["questionnaires"][0]}


def set_list(admin, study: dict, ids: list[str]) -> None:
    done = admin.admin("SetAllowedPids", f"&study_id={study['id']}", data={"ids": ids})
    check(f"the admin can save a list of {len(ids)} ids", done.get("success") is True and done["dataset"]["saved"] == len(ids), str(done)[:200])


def upload(client, study: dict, user: str, token: str | None = None) -> dict:
    """One questionnaire answer as the app sends it; the first dataset's verdict (or the whole answer when the request failed)."""
    now = int(time.time() * 1000)
    q = study["questionnaire"]
    answers = {item["name"]: "x" for page in q["pages"] for item in page["inputs"] if item.get("responseType", "text_input") == "text_input"}
    body = {"userId": user, "appVersion": "restricted-ids", "appType": "Web", "serverVersion": lab.SERVER_VERSION, "dataset": [{
        "dataSetId": now, "studyId": study["id"], "studyVersion": 1, "studySubVersion": 0, "studyLang": "en", "accessKey": study["key"],
        "eventType": "questionnaire", "questionnaireName": q["title"], "questionnaireInternalId": q["internalId"], "responseTime": now,
        "timezone": "Etc/UTC", "group": 0, "entryTime": now, "entryId": now, "responses": answers}]}
    if token:
        body["deviceToken"] = token
    status, text = client.request("/esmira/api/datasets.php", json.dumps(body).encode(), "application/json")
    try:
        verdict = json.loads(text)
        return (verdict.get("dataset", {}).get("states") or [verdict])[0]
    except ValueError:
        return {"success": False, "error": f"HTTP {status}: {text[:150]}"}


def ask(client, study: dict, user: str, key: str | None = None) -> dict:
    status, text = client.request("/esmira/api/check_pid.php", json.dumps({
        "studyId": study["id"], "userId": user, "accessKey": study["key"] if key is None else key, "serverVersion": lab.SERVER_VERSION}).encode(), "application/json")
    try:
        return json.loads(text)
    except ValueError:
        return {"success": False, "error": f"HTTP {status}: {text[:150]}"}


def server_checks(admin, participant, args) -> None:
    study = make_study(admin, args, "list", "list")
    set_list(admin, study, ["P001", "P002", "P003"])
    ok = upload(participant, study, "P001")
    check("the server takes an upload for a listed id", ok.get("success") is True, str(ok))
    no = upload(participant, study, "stranger")
    check("the server refuses an upload for an unlisted id", no.get("success") is False and "not allowed" in no.get("error", ""), str(no))
    listed = admin.admin("GetAllowedPids", f"&study_id={study['id']}")["dataset"]
    joined = {row["id"]: row["joined"] for row in listed["ids"]}
    check("the dashboard can tell which listed ids have joined", joined == {"P001": True, "P002": False, "P003": False}, str(joined))
    check("and nothing was stored for the refused id", "stranger" not in json.dumps(listed) and listed["unlistedJoinedCount"] == 0, str(listed)[:200])
    answer = ask(participant, study, "p002")
    check("the app is told a listed id is allowed, in the study's spelling", answer.get("dataset") == {"allowed": True, "userId": "P002"}, str(answer))
    check("and that an unlisted id is not", ask(participant, study, "P999").get("dataset", {}).get("allowed") is False)
    check("the question needs the study's access key", ask(participant, study, "P001", key="wrong").get("success") is False)
    admin.admin("DeleteStudy", f"&study_id={study['id']}", form={"study_id": study["id"], "pass": args.password})

    # A study that was open: its participants keep working when it gets a list.
    early = make_study(admin, args, "open-then-list", "open")
    check("an open study takes any id", upload(participant, early, "earlybird").get("success") is True)
    early["json"].update({"pidMode": "list"})
    resaved = admin.admin("SaveStudy", f"&study_id={early['id']}&lastChanged=9999999999", data={"_": early["json"]})
    check("the admin can switch it to a list", resaved.get("success") is True, str(resaved)[:200])
    set_list(admin, early, ["P001"])
    time.sleep(1.2)  # The server takes one dataset a second from a participant.
    check("a participant who joined while it was open keeps working", upload(participant, early, "earlybird").get("success") is True)
    check("a new id that is not listed does not get in", upload(participant, early, "latecomer").get("success") is False)
    shown = admin.admin("GetAllowedPids", f"&study_id={early['id']}")["dataset"]
    check("the dashboard counts joined participants who are not listed", shown["unlistedJoinedCount"] == 1 and shown["unlistedJoined"] == ["earlybird"], str(shown)[:200])
    admin.admin("DeleteStudy", f"&study_id={early['id']}", form={"study_id": early["id"], "pass": args.password})


TOKEN_A = "aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa"
TOKEN_B = "bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb"


def lock(client, study: dict, user: str, token: str, **extra) -> dict:
    status, text = client.request("/esmira/api/pid_lock.php", json.dumps({
        "studyId": study["id"], "userId": user, "deviceToken": token, "accessKey": study["key"], "serverVersion": lab.SERVER_VERSION, **extra}).encode(), "application/json")
    try:
        return json.loads(text)
    except ValueError:
        return {"success": False, "error": f"HTTP {status}: {text[:150]}"}


def device_checks(admin, participant, args) -> None:
    """One device at a time per participant id, over the server's own HTTP API."""
    study = make_study(admin, args, "one-device", "open", devices="single")
    refused = lambda r: r.get("success") is False and r.get("errorCode") == 409
    check("the first device takes an id with its first upload", upload(participant, study, "P1", TOKEN_A).get("success") is True)
    time.sleep(1.2)  # The server takes one dataset a second from a participant.
    other = upload(participant, study, "P1", TOKEN_B)
    check("an upload from another device is refused as a whole, with its own error code", refused(other), str(other))
    check("an upload from an app that sends no token is not restricted", upload(participant, study, "P1").get("success") is True)
    check("another id is free for another device", upload(participant, study, "P2", TOKEN_B).get("success") is True)
    check("the question says who has the id", lock(participant, study, "P1", TOKEN_B)["dataset"]["status"] == "conflict")
    check("the holder is told it is theirs", lock(participant, study, "P1", TOKEN_A)["dataset"]["status"] == "already_yours")
    check("the question needs the access key", lock(participant, {**study, "key": "wrong"}, "P1", TOKEN_B).get("success") is False)
    check("the person holding the id can transfer it", lock(participant, study, "P1", TOKEN_B, force=True)["dataset"]["status"] == "claimed")
    time.sleep(1.2)
    check("then the old device is refused", refused(upload(participant, study, "P1", TOKEN_A)))
    check("and the new one works", upload(participant, study, "P1", TOKEN_B).get("success") is True)
    check("only the holder can give the id up", lock(participant, study, "P1", TOKEN_A, release=True)["dataset"]["status"] == "released"
          and lock(participant, study, "P1", TOKEN_A)["dataset"]["status"] == "conflict")
    lock(participant, study, "P1", TOKEN_B, release=True)
    check("a released id can be taken by any device", lock(participant, study, "P1", TOKEN_A)["dataset"]["status"] == "claimed")
    admin.admin("DeleteStudy", f"&study_id={study['id']}", form={"study_id": study["id"], "pass": args.password})

    several = make_study(admin, args, "several-devices", "open")
    check("a study that allows several devices takes the same id from all of them", upload(participant, several, "P1", TOKEN_A).get("success") is True)
    time.sleep(1.2)
    check("...whatever their tokens", upload(participant, several, "P1", TOKEN_B).get("success") is True)
    check("...and holds nothing", lock(participant, several, "P1", TOKEN_B)["dataset"]["status"] == "claimed")
    admin.admin("DeleteStudy", f"&study_id={several['id']}", form={"study_id": several["id"], "pass": args.password})


class Phone:
    def __init__(self, browser, args) -> None:
        self.browser, self.args = browser, args

    def open(self, url: str):
        context = self.browser.new_context(viewport={"width": 390, "height": 844}, user_agent=ANDROID, is_mobile=True, has_touch=True,
                                           ignore_https_errors=self.args.insecure)
        page = context.new_page()
        page.posts = []
        page.on("request", lambda r: page.posts.append(r.url) if r.method == "POST" and ("datasets.php" in r.url or "push_" in r.url) else None)
        page.goto(url)
        return page

    @staticmethod
    def stored(page, study) -> str:
        return page.evaluate("id => localStorage.getItem('esmira_userid_' + id)", study["id"]) or ""

    @staticmethod
    def asks_for_id(page) -> bool:
        try:
            page.get_by_role("textbox", name="Participant ID").wait_for(timeout=15000)
            return True
        except PlaywrightTimeout:
            return False

    @staticmethod
    def went_on(page) -> bool:
        try:
            page.get_by_role("button", name="I consent").wait_for(timeout=15000)
            return True
        except PlaywrightTimeout:
            return False


def dashboard_checks(browser, admin, args) -> None:
    """The researcher's side: the Participant IDs page of the study editor."""
    base = args.base.rstrip("/")
    study = make_study(admin, args, "dashboard", "open")
    context = browser.new_context(viewport={"width": 1100, "height": 1600}, ignore_https_errors=args.insecure, accept_downloads=True)
    page = context.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("dialog", lambda d: d.accept())
    page.goto(f"{base}/esmira/#admin")
    page.wait_for_load_state("networkidle")
    page.get_by_role("textbox", name="Username").wait_for(timeout=15000)
    page.wait_for_timeout(500)
    page.get_by_role("textbox", name="Username").fill(args.user)
    page.get_by_role("textbox", name="Password").fill(args.password)
    page.get_by_role("button", name="Login").click()
    page.get_by_role("link", name="Show data / statistics").wait_for(timeout=15000)
    page.goto(f"{base}/esmira/#admin/allStudies:edit/studyEdit,id:{study['id']}")
    page.get_by_text("Participant IDs", exact=True).first.click(timeout=15000)
    page.get_by_text("Only participants on my list can join").click(timeout=15000)
    box = page.get_by_role("textbox", name="One ID per line")
    box.fill("P001\nP002\nbad/id")
    page.wait_for_timeout(300)
    check("the dashboard names an ID the server would refuse, and does not save it",
          "not valid IDs" in page.inner_text("body") and page.get_by_role("button", name="Save list").is_disabled())
    box.fill("P001\nP002\np001")
    page.get_by_role("spinbutton").fill("3")
    page.get_by_role("button", name="Make random IDs").click()
    page.wait_for_timeout(300)
    check("the researcher can paste IDs and make random ones; a repeat in another letter case is dropped", len(box.input_value().split("\n")) == 5, box.input_value())
    page.get_by_role("button", name="Save list").click()
    page.wait_for_function("document.body.innerText.includes('0 of 5 listed IDs have joined')", timeout=15000)
    check("the list is saved and shows who has joined", True)
    page.get_by_text("Save", exact=True).last.click()
    page.wait_for_timeout(2500)
    saved = admin.admin("GetAllowedPids", f"&study_id={study['id']}")["dataset"]
    check("saving the study switches the server to the list", saved["enforced"] is True and len(saved["ids"]) == 5, str(saved)[:200])
    with page.expect_download() as download:
        page.get_by_role("button", name="Download personal links (CSV)").click()
    rows = open(download.value.path()).read().splitlines()
    check("the researcher can download a personal link for every listed ID",
          rows[0] == "participant_id;link" and len(rows) == 6 and f"key={study['key']}&pid=P001" in rows[1], "; ".join(rows[:2]))
    page.get_by_text("One device at a time per ID").click()
    page.get_by_text("Save", exact=True).last.click()
    page.wait_for_timeout(2500)
    status, text = lab.Client(args.base, args.insecure).request(f"/esmira/api/studies.php?access_key={study['key']}")
    check("the researcher can choose one device at a time, and it is saved with the study", '"pidDevices":"single"' in text.replace(" ", ""), text[:200])
    check("the page has no errors", not errors, "; ".join(errors)[:300])
    admin.admin("DeleteStudy", f"&study_id={study['id']}", form={"study_id": study["id"], "pass": args.password})


def browser_checks(browser, admin, args) -> None:
    phone = Phone(browser, args)
    base = args.base.rstrip("/")
    study = make_study(admin, args, "browser", "list")
    set_list(admin, study, ["P001", "Ab-02"])
    link = f"{base}/pwa/?key={study['key']}"

    page = phone.open(f"{link}&pid=P001")
    check("a link with a listed id goes straight on", phone.went_on(page))
    check("and the app keeps that id", phone.stored(page, study) == "P001", phone.stored(page, study))

    page = phone.open(f"{link}&pid=P999")
    check("a link with an id that is not listed asks for the id", phone.asks_for_id(page))
    check("and says it is not recognised", "not recognised" in page.inner_text("body"), page.inner_text("body")[:200])
    check("and stores no id and sends nothing for it", phone.stored(page, study) == "" and not page.posts, f"{phone.stored(page, study)!r} {page.posts}")
    page.get_by_role("textbox", name="Participant ID").fill("nope")
    page.get_by_role("button", name="Continue", exact=True).click()
    page.wait_for_timeout(1500)
    check("a typed id that is not listed is refused too", "not recognised" in page.inner_text("body") and phone.stored(page, study) == "")
    page.get_by_role("textbox", name="Participant ID").fill("ab-02")
    page.get_by_role("button", name="Continue", exact=True).click()
    check("a listed id typed in another letter case lets the participant in", phone.went_on(page))
    check("as the study spells it", phone.stored(page, study) == "Ab-02", phone.stored(page, study))

    page = phone.open(link)
    check("a link without an id asks for it, without an error", phone.asks_for_id(page) and "not recognised" not in page.inner_text("body"))

    off = make_study(admin, args, "typed-only", "list", from_url=False)
    set_list(admin, off, ["P001"])
    page = phone.open(f"{base}/pwa/?key={off['key']}&pid=P001")
    check("with the link's id switched off, the id is typed even when the link has one", phone.asks_for_id(page))
    page.get_by_role("textbox", name="Participant ID").fill("P001")
    page.get_by_role("button", name="Continue", exact=True).click()
    check("and the typed id lets the participant in", phone.went_on(page))

    for s in (study, off):
        admin.admin("DeleteStudy", f"&study_id={s['id']}", form={"study_id": s["id"], "pass": args.password})


def device_browser_checks(browser, admin, participant, args) -> None:
    """One device at a time, in the participant's app on two phones (two browser contexts)."""
    phone = Phone(browser, args)
    base = args.base.rstrip("/")
    study = make_study(admin, args, "two-phones", "open", devices="single")
    link = f"{base}/pwa/?key={study['key']}&pid=P001"
    token = lambda page: page.evaluate("localStorage.getItem('esmira_device_token')") or ""
    queue_length = lambda page: page.evaluate("JSON.parse(localStorage.getItem('esmira_submit_queue') || '[]').length")
    conflict_text = "already active on another device"

    first = phone.open(link)
    check("the first phone goes straight on", phone.went_on(first))
    check("and holds the id", lock(participant, study, "P001", "someone-else-0001")["dataset"]["status"] == "conflict")

    second = phone.open(link)
    try:
        second.get_by_role("button", name="Transfer to this device").wait_for(timeout=15000)
        offered = True
    except PlaywrightTimeout:
        offered = False
    check("a second phone with the same id is offered the transfer, not the study", offered and conflict_text in second.inner_text("body"), second.inner_text("body")[:200])
    check("the two phones are different devices", token(first) != "" and token(first) != token(second))
    second.get_by_role("button", name="Transfer to this device").click()
    check("after the transfer the second phone goes on", phone.went_on(second))
    check("and holds the id", lock(participant, study, "P001", token(second))["dataset"]["status"] == "already_yours"
          and lock(participant, study, "P001", token(first))["dataset"]["status"] == "conflict")

    # The first phone has an answer waiting to be sent, and tries to send it.
    now = int(time.time() * 1000)
    q = study["questionnaire"]
    answers = {item["name"]: "x" for page in q["pages"] for item in page["inputs"] if item.get("responseType", "text_input") == "text_input"}
    payload = {"userId": "P001", "appVersion": "restricted-ids", "appType": "Web", "serverVersion": lab.SERVER_VERSION, "dataset": [{
        "dataSetId": now, "studyId": study["id"], "studyVersion": 1, "studySubVersion": 0, "studyLang": "en", "accessKey": study["key"],
        "eventType": "questionnaire", "questionnaireName": q["title"], "questionnaireInternalId": q["internalId"], "responseTime": now,
        "timezone": "Etc/UTC", "group": 0, "entryTime": now, "entryId": now, "responses": answers}]}
    first.evaluate("queue => localStorage.setItem('esmira_submit_queue', JSON.stringify(queue))", [{"id": "waiting-1", "payload": payload, "attempts": 0}])
    first.evaluate("window.dispatchEvent(new Event('online'))")
    try:
        first.get_by_role("button", name="Transfer to this device").wait_for(timeout=15000)
        told = True
    except PlaywrightTimeout:
        told = False
    check("the first phone is told when it tries to send", told and conflict_text in first.inner_text("body"))
    check("and keeps the answer", queue_length(first) == 1, str(queue_length(first)))
    first.get_by_role("button", name="Transfer to this device").click()
    try:
        first.wait_for_function("JSON.parse(localStorage.getItem('esmira_submit_queue') || '[]').length === 0", timeout=20000)
        sent = True
    except PlaywrightTimeout:
        sent = False
    check("taking the id back sends the waiting answer", sent)
    check("and the second phone is the one refused now", lock(participant, study, "P001", token(second))["dataset"]["status"] == "conflict"
          and lock(participant, study, "P001", token(first))["dataset"]["status"] == "already_yours")

    # An answer waiting under another id (one the participant has left) that is refused must not put the id in use now in doubt.
    lock(participant, study, "P9", "someone-else-0009")
    left_behind = {**payload, "userId": "P9", "dataset": [{**payload["dataset"][0], "entryId": now + 1, "dataSetId": now + 1}]}
    first.evaluate("queue => localStorage.setItem('esmira_submit_queue', JSON.stringify(queue))", [{"id": "waiting-2", "payload": left_behind, "attempts": 0}])
    tried_before = sum("datasets.php" in url for url in first.posts)
    first.evaluate("window.dispatchEvent(new Event('online'))")
    first.wait_for_timeout(4000)
    check("the app did try to send it (and was refused)", sum("datasets.php" in url for url in first.posts) > tried_before)
    check("a refusal for an id the participant has left does not take over the screen",
          not first.get_by_role("button", name="Transfer to this device").is_visible())
    check("and that answer is kept", queue_length(first) == 1, str(queue_length(first)))
    admin.admin("DeleteStudy", f"&study_id={study['id']}", form={"study_id": study["id"], "pass": args.password})

    several = make_study(admin, args, "two-phones-several", "open")
    link = f"{base}/pwa/?key={several['key']}&pid=P001"
    check("with several devices allowed, the first phone goes on", phone.went_on(phone.open(link)))
    other = phone.open(link)
    check("and so does the second, without being asked anything", phone.went_on(other) and conflict_text not in other.inner_text("body"))
    admin.admin("DeleteStudy", f"&study_id={several['id']}", form={"study_id": several["id"], "pass": args.password})


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--insecure", action="store_true")
    args = parser.parse_args()

    admin = lab.Client(args.base, args.insecure)
    login = admin.admin("login", form={"accountName": args.user, "pass": args.password})
    if not check("the admin can log in", login.get("success") is True, str(login)[:200]):
        return 1
    participant = lab.Client(args.base, args.insecure)
    server_checks(admin, participant, args)
    device_checks(admin, participant, args)
    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--ignore-certificate-errors"] if args.insecure else [])
        try:
            dashboard_checks(browser, admin, args)
            browser_checks(browser, admin, args)
            device_browser_checks(browser, admin, participant, args)
        except PlaywrightTimeout as e:
            check("the journey got to the end", False, str(e).split("\n")[0][:300])
        browser.close()
    print("\nRESTRICTED IDS " + ("FAILED" if failures else "OK") + f" ({args.base})")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
