#!/usr/bin/env python3
"""
Smoke test: every cognitive task (webapp item) in a live study loads and starts.

Why this exists: in Oct 2026 the SSRC study's Color Shapes task showed a blank
screen. Its wrapper page's import map pointed at
`modules/@m2c2kit/assessment-color-shapes@0.8.33/dist/index.js` (and the patched
`addons@0.3.34/dist/index.js`), but the Surrey host only had the `.min.js`
builds. GitHub Pages had both, so the task looked fine everywhere except where
participants run it. A manual sync had copied `assessments/` and skipped
`modules/`, so the wrapper and the modules it imports no longer matched.

This test catches that kind of drift from the participant's side. It reads the
study the PWA reads (`api/studies.php?access_key=KEY`) and checks each distinct
task URL, with the same `embed=1&v=N` suffix the PWA adds
(web-pwa/src/lib/esmiraAdapter.ts):

  1. STATIC: fetch the wrapper HTML, resolve every import-map entry and
     <script src> against it, and require each one to return HTTP 200.
  2. BROWSER: load the task in headless Chromium (phone viewport) and require
     no uncaught page errors, no failed (>=400) subresource requests, and a
     started task: an m2c2kit canvas with non-zero size, or a visible button
     for plain-HTML tasks (e.g. DMA).

It does not play the task through to `m2c2:complete`; it guards against
"the task never loads", which is how a broken deployment looks on a phone.

Run:
  python3 tools/cognitive-tasks/webapp_tasks_smoke_test.py                # SSRC on Surrey
  python3 tools/cognitive-tasks/webapp_tasks_smoke_test.py --key demoall
  python3 tools/cognitive-tasks/webapp_tasks_smoke_test.py --url https://…/index.html
  python3 tools/cognitive-tasks/webapp_tasks_smoke_test.py --static-only  # no browser

Exit code 0 = every task passed; 1 = at least one failed; 2 = study unreadable.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser
from pathlib import Path

DEFAULT_API_ROOT = "https://iemabot.surrey.ac.uk/esmira/"
DEFAULT_KEY = "ssrc"
ADAPTER = Path(__file__).resolve().parents[2] / "web-pwa/src/lib/esmiraAdapter.ts"
FALLBACK_SUFFIX = "embed=1&v=3"
START_TIMEOUT_MS = 30_000
USER_AGENT = "iEMAbot-webapp-smoke/1.0"
# Legacy items carry the task as an anchor in their text (see extractCognitive()).
ANCHOR_RE = re.compile(r'href="([^"]*/webapp/[^"]+)"')


@dataclass
class TaskResult:
    url: str
    items: list[str]
    problems: list[str] = field(default_factory=list)


def embed_suffix() -> str:
    """Return the `embed=1&v=N` suffix the PWA currently appends to task URLs."""
    try:
        found = re.search(r"embed=1&v=\d+", ADAPTER.read_text(encoding="utf-8"))
    except OSError:
        found = None
    return found.group(0) if found else FALLBACK_SUFFIX


def with_suffix(url: str, suffix: str) -> str:
    return f"{url}{'&' if '?' in url else '?'}{suffix}"


def http_get(url: str, timeout: float = 20) -> tuple[int, bytes]:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Cache-Control": "no-cache"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, b""
    except (urllib.error.URLError, TimeoutError) as e:
        return 0, str(e).encode()


def study_tasks(api_root: str, key: str) -> dict[str, list[str]]:
    """Map each distinct task URL in the study to the item names that launch it."""
    api = f"{api_root.rstrip('/')}/api/studies.php?access_key={urllib.parse.quote(key)}"
    status, body = http_get(api)
    if status != 200:
        raise RuntimeError(f"{api} returned HTTP {status}")
    payload = json.loads(body)
    if not payload.get("success") or not payload.get("dataset"):
        raise RuntimeError(f"no study for access key {key!r}")
    tasks: dict[str, list[str]] = {}
    for study in payload["dataset"]:
        for q in study.get("questionnaires", []):
            for page in q.get("pages", []):
                for inp in page.get("inputs", []):
                    if inp.get("responseType") == "webapp" and inp.get("url"):
                        urls = [inp["url"]]
                    else:
                        urls = ANCHOR_RE.findall(inp.get("text") or "")
                    for url in urls:
                        tasks.setdefault(url, []).append(inp.get("name", "?"))
    return tasks


class _WrapperParser(HTMLParser):
    """Collect the import map JSON and <script src> values from a wrapper page."""

    def __init__(self) -> None:
        super().__init__()
        self.scripts: list[str] = []
        self.importmap = ""
        self._in_importmap = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "script":
            return
        a = dict(attrs)
        if a.get("type") == "importmap":
            self._in_importmap = True
        elif a.get("src"):
            self.scripts.append(a["src"])

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self._in_importmap = False

    def handle_data(self, data: str) -> None:
        if self._in_importmap:
            self.importmap += data


def check_static(page_url: str) -> list[str]:
    """Every import-map target and script the wrapper references must be served."""
    status, body = http_get(page_url)
    if status != 200:
        return [f"wrapper page returned HTTP {status}"]
    parser = _WrapperParser()
    parser.feed(body.decode("utf-8", errors="replace"))
    refs = list(parser.scripts)
    if parser.importmap.strip():
        try:
            refs += list(json.loads(parser.importmap).get("imports", {}).values())
        except json.JSONDecodeError as e:
            return [f"import map is not valid JSON: {e}"]
    problems = []
    for ref in refs:
        target = urllib.parse.urljoin(page_url, ref)
        code, _ = http_get(target)
        if code != 200:
            problems.append(f"HTTP {code}: {ref}  ->  {target}")
    return problems


# True once the task is visibly running: an m2c2kit canvas, or a visible button
# for plain-HTML tasks. Checked in the page; an empty <div id="m2c2kit"> is not enough.
STARTED_JS = """() => {
  const c = document.querySelector('canvas');
  if (c && c.width > 0 && c.height > 0) return true;
  return [...document.querySelectorAll('button, [role=button], input')]
    .some(el => el.offsetWidth > 0 && el.offsetHeight > 0);
}"""


def check_browser(page_url: str, browser) -> list[str]:
    """Load the task like a phone does and require it to start without errors."""
    from playwright.sync_api import TimeoutError as PwTimeout

    ctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True,
                              has_touch=True, device_scale_factor=2, service_workers="block")
    page = ctx.new_page()
    problems: list[str] = []
    page.on("pageerror", lambda err: problems.append(f"page error: {err}"))
    page.on("requestfailed", lambda req: problems.append(
        f"request failed ({req.failure}): {req.url}"))
    page.on("response", lambda resp: resp.status >= 400 and problems.append(
        f"HTTP {resp.status}: {resp.url}"))
    try:
        page.goto(page_url, wait_until="load", timeout=START_TIMEOUT_MS)
        page.wait_for_function(STARTED_JS, timeout=START_TIMEOUT_MS)
    except PwTimeout:
        problems.append(f"task did not start within {START_TIMEOUT_MS // 1000}s (blank screen)")
    finally:
        ctx.close()
    # Favicon misses are browser noise, not part of the task.
    return [p for p in problems if "favicon" not in p]


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--api-root", default=DEFAULT_API_ROOT, help="ESMira root that serves api/studies.php")
    ap.add_argument("--key", action="append", help="study access key (repeatable; default: ssrc)")
    ap.add_argument("--url", action="append", help="check this task URL instead of reading a study")
    ap.add_argument("--static-only", action="store_true", help="skip the headless-browser check")
    args = ap.parse_args()

    tasks: dict[str, list[str]] = {}
    if args.url:
        tasks = {u: ["--url"] for u in args.url}
    else:
        for key in args.key or [DEFAULT_KEY]:
            try:
                for url, names in study_tasks(args.api_root, key).items():
                    tasks.setdefault(url, []).extend(names)
            except (RuntimeError, ValueError) as e:
                print(f"ERROR reading study {key!r}: {e}", file=sys.stderr)
                return 2
    if not tasks:
        print("No webapp tasks found.")
        return 0

    suffix = embed_suffix()
    print(f"Checking {len(tasks)} task URL(s) with PWA suffix '{suffix}'\n")
    results = [TaskResult(url, names) for url, names in tasks.items()]
    for r in results:
        r.problems += check_static(with_suffix(r.url, suffix))

    if not args.static_only:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as pw:
            browser = pw.chromium.launch()
            try:
                for r in results:
                    r.problems += check_browser(with_suffix(r.url, suffix), browser)
            finally:
                browser.close()

    failed = 0
    for r in results:
        ok = not r.problems
        failed += not ok
        print(f"[{'PASS' if ok else 'FAIL'}] {r.url}")
        print(f"       items: {', '.join(r.items)}")
        for p in dict.fromkeys(r.problems):  # de-duplicate, keep order
            print(f"       - {p}")
    print(f"\n{len(results) - failed}/{len(results)} task(s) passed.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
