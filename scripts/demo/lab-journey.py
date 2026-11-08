#!/usr/bin/env python3
"""What a new lab needs to work, over the server's own HTTP API: fast, and with nothing to install.

  an admin logs in and creates a study -> a participant finds it by its access key -> uploads an answer
  to every question type -> the admin downloads the data, and every answer is in its column.

The dashboard and the participant app use these same endpoints. What only a browser can see (the link the dashboard
offers, what a participant sees on opening it) is left to lab-journey-browser.py.

Usage: lab-journey.py BASE_URL --user NAME --password SECRET [--insecure] [--keep]
  BASE_URL is the site's address, e.g. https://ema.example.org. --insecure accepts a private certificate.
  The study is deleted afterwards unless --keep is given. Nothing but this one study is touched.
"""
import argparse
import base64
import csv
import http.cookiejar
import io
import json
import random
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
STUDY_FILE = ROOT / "demo" / "demo-study.json"
TYPES_FILE = ROOT / "src" / "frontend" / "ts" / "data" / "study" / "Input.ts"
SERVER_VERSION = 12

failures: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> bool:
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok or not detail else f"   [{detail}]"))
    if not ok:
        failures.append(name)
    return ok


class Client:
    """A browser's worth of HTTP: one cookie jar, against one site."""

    def __init__(self, base: str, insecure: bool) -> None:
        self.base = base.rstrip("/")
        handlers: list[urllib.request.BaseHandler] = [urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())]
        if insecure:
            context = ssl.create_default_context()
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
            handlers.append(urllib.request.HTTPSHandler(context=context))
        self.opener = urllib.request.build_opener(*handlers)

    def request(self, path: str, body: bytes | None = None, content_type: str | None = None) -> tuple[int, str]:
        request = urllib.request.Request(self.base + path, data=body)
        if content_type:
            request.add_header("Content-Type", content_type)
        try:
            with self.opener.open(request, timeout=60) as response:
                return response.status, response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace")

    def admin(self, kind: str, query: str = "", form: dict | None = None, data: dict | None = None) -> dict:
        path = f"/esmira/api/admin.php?type={kind}{query}"
        if data is not None:
            status, text = self.request(path, json.dumps(data).encode(), "application/json")
        else:
            body = urllib.parse.urlencode(form).encode() if form is not None else None
            status, text = self.request(path, body)
        try:
            return json.loads(text)
        except ValueError:
            return {"success": False, "serverSaid": f"HTTP {status}: {text[:200]}"}


def walk_inputs(study: dict) -> list[dict]:
    return [item for q in study["questionnaires"] for page in q["pages"] for item in page["inputs"]]


def check_every_type_is_in_the_demo(study: dict) -> None:
    """A question type added to the code but not to the demo study would go untested, so that fails here."""
    if not TYPES_FILE.exists():
        return
    block = re.search(r"export type InputResponseType =(.*?)\n\n", TYPES_FILE.read_text(), re.S)
    known = set(re.findall(r'"(\w+)"', block.group(1) if block else ""))
    present = {item.get("responseType", "text_input") for item in walk_inputs(study)}
    check(f"the demo study holds all {len(known)} question types", bool(known) and known <= present, f"missing: {sorted(known - present)}")


# The smallest files the server accepts for each kind of media, as the app would upload them.
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==")
WEBM = bytes.fromhex("1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810242858102")
MEDIA = {"photo": ("Image", PNG), "file_upload": ("Image", PNG), "record_audio": ("Audio", WEBM),
         "record_keystrokes": ("Keystrokes", b"t,key\n0,down\n40,up\n")}
# Extra columns the server keeps for a question type (backend/ResponsesIndex.php).
SIBLINGS = {
    "app_usage": ["usageCountYesterday", "usageCountToday", "usageTimeYesterday", "usageTimeToday", "usageProtocolYesterday", "usageProtocolToday"],
    "battery_level": ["charging"], "bluetooth_devices": ["devices"], "dynamic_input": ["index"], "noise_level": ["min", "max"],
    "record_keystrokes": ["text", "capture_mode"],
}


def answers_for(questionnaire: dict, marker: str, media: dict) -> dict:
    """One answer per column the questionnaire should have, each one unique so that it can be found again.
    Media answers are an identifier, with the file uploaded separately: those go into `media`, by identifier."""
    answers: dict[str, str] = {}
    for page in questionnaire["pages"]:
        for item in page["inputs"]:
            name, kind = item["name"], item.get("responseType", "text_input")
            if kind == "text":  # Only shows something: there is no answer to keep.
                continue
            if kind in MEDIA:
                identifier = random.randint(1_000_000, 2_000_000_000)
                answers[name] = str(identifier)
                media[identifier] = MEDIA[kind]
            else:
                answers[name] = f"{marker}-{name}"
            for sibling in SIBLINGS.get(kind, []):
                answers[f"{name}~{sibling}"] = f"{marker}-{name}-{sibling}"
            if kind == "list_multiple":
                for index in range(1, len(item["listChoices"]) + 1):
                    answers[f"{name}~{index}"] = f"{marker}-{name}-{index}"
            if item.get("other"):
                answers[f"{name}~other"] = f"{marker}-{name}-other"
    return answers


def multipart(fields: dict, file_name: str, content: bytes) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="upload"; filename="{file_name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b"\r\n")
    parts.append(f"--{boundary}--\r\n".encode())
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--insecure", action="store_true")
    parser.add_argument("--keep", action="store_true")
    args = parser.parse_args()

    study = json.loads(STUDY_FILE.read_text())["_"]
    check_every_type_is_in_the_demo(study)

    # A study of its own, so that this can run on a server in use and never touch anything else.
    study_id = random.randint(10_000_000, 99_999_999)
    access_key = f"journey{uuid.uuid4().hex[:10]}"
    study.update({"id": study_id, "accessKeys": [access_key], "title": f"Journey test {access_key}", "published": True})
    marker = uuid.uuid4().hex[:10]
    internal_ids = {}
    for index, questionnaire in enumerate(study["questionnaires"]):
        internal_ids[questionnaire["internalId"]] = questionnaire["internalId"] = study_id * 100 + index

    admin = Client(args.base, args.insecure)
    login = admin.admin("login", form={"accountName": args.user, "pass": args.password})
    if not check("the admin can log in", login.get("success") is True, str(login)[:200]):
        return 1
    study["serverVersion"] = login["dataset"].get("serverVersion", SERVER_VERSION) if isinstance(login.get("dataset"), dict) else login.get("serverVersion", SERVER_VERSION)
    study["packageVersion"] = "0"
    version_status, version = admin.request("/esmira/api/app_version.php")
    check("the API answers", version_status == 200, version[:100])

    created = admin.admin("SaveStudy", f"&study_id={study_id}&lastChanged=9999999999", data={"_": study})
    if not check("the admin can create a study", created.get("success") is True, str(created)[:300]):
        return 1

    try:
        participant = Client(args.base, args.insecure)
        status, page = participant.request(f"/pwa/?key={access_key}")
        check("the participant link opens the app", status == 200 and "<html" in page.lower(), f"HTTP {status}")
        status, listed = participant.request(f"/esmira/api/studies.php?access_key={access_key}")
        check("a participant finds the study by its access key", status == 200 and f'"id":{study_id}' in listed.replace(" ", ""), listed[:200])

        # Every questionnaire in one upload, the way the app sends what it has queued.
        now = int(time.time() * 1000)
        user = f"journey-{marker}"
        media: dict[int, tuple[str, bytes]] = {}
        expected = {q["internalId"]: answers_for(q, marker, media) for q in study["questionnaires"]}
        datasets = [{
            "dataSetId": now + index, "studyId": study_id, "studyVersion": 1, "studySubVersion": 0, "studyLang": "en",
            "accessKey": access_key, "eventType": "questionnaire", "questionnaireName": q["title"],
            "questionnaireInternalId": q["internalId"], "responseTime": now, "timezone": "Etc/UTC", "group": 0,
            "entryTime": now, "entryId": now + index, "responses": expected[q["internalId"]],
        } for index, q in enumerate(study["questionnaires"])]
        status, text = participant.request("/esmira/api/datasets.php", json.dumps({
            "userId": user, "appVersion": "journey", "appType": "Web", "serverVersion": study["serverVersion"], "dataset": datasets,
        }).encode(), "application/json")
        verdict = json.loads(text) if text.startswith("{") else {}
        states = verdict.get("dataset", {}).get("states", [])
        check("the server accepts answers to every questionnaire",
              verdict.get("success") is True and len(states) == len(datasets) and all(s.get("success") for s in states), text[:300])

        for identifier, (data_type, content) in media.items():
            body, content_type = multipart({"studyId": study_id, "userId": user, "dataType": data_type}, str(identifier), content)
            status, text = participant.request("/esmira/api/file_uploads.php", body, content_type)
            check(f"the server accepts an uploaded {data_type} file", status == 200 and '"success":true' in text, text[:200])

        for q in study["questionnaires"]:
            status, data = admin.request(f"/esmira/api/admin.php?type=GetData&study_id={study_id}&q_id={q['internalId']}")
            rows = list(csv.DictReader(io.StringIO(data), delimiter=";")) if status == 200 else []
            row = next((r for r in rows if r.get("userId") == user), None)
            if not check(f"the admin can download \"{q['title']}\"", row is not None, f"HTTP {status}: {data[:200]}"):
                continue
            wanted = expected[q["internalId"]]
            media_columns = {column for column, value in wanted.items() if value.isdigit() and int(value) in media}
            lost = [c for c, value in wanted.items() if (not row.get(c) if c in media_columns else row.get(c) != value)]
            check(f"all {len(wanted)} answers to \"{q['title']}\" are in their columns", not lost, f"lost: {lost[:12]}")

        # Two steps, like the dashboard's download button: the archive is built on request, then fetched.
        admin.request(f"/esmira/api/admin.php?type=CreateMediaZip&study_id={study_id}")
        request = urllib.request.Request(f"{admin.base}/esmira/api/admin.php?type=GetMediaZip&study_id={study_id}")
        with admin.opener.open(request, timeout=120) as response:
            archive = zipfile.ZipFile(io.BytesIO(response.read()))
        stored = [archive.read(name) for name in archive.namelist() if not name.endswith("/")]
        missing = [identifier for identifier, (_, content) in media.items() if content not in stored]
        check(f"the admin can download all {len(media)} media files intact", not missing and len(stored) >= len(media), f"{len(stored)} files stored, missing: {missing}")
    finally:
        if not args.keep:
            removed = admin.admin("DeleteStudy", f"&study_id={study_id}", form={"study_id": study_id, "pass": args.password})
            check("the test study can be deleted again", removed.get("success") is True, str(removed)[:200])

    print("\nLAB JOURNEY " + ("FAILED" if failures else "OK") + f" ({args.base})")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
