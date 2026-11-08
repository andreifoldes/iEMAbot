#!/usr/bin/env python3
"""
Browser check that cognitive tasks still work inside the sandboxed task iframe, and that the
service worker's task route only serves /webapp/.

Serves the built PWA (dist/pwa) from a local origin together with a fixture study and a /webapp/
tree. The tree is either a local mirror of the host's /webapp folder (WEBAPP_DIR) or fetched on
demand from a live server (WEBAPP_UPSTREAM, e.g. https://iemabot.surrey.ac.uk; read-only GETs). A
built-in probe task is always available at /webapp/_probe/index.html: it writes localStorage and
IndexedDB, posts `m2c2:complete` to the app, and tries the things the sandbox must stop
(navigating the app away, window.open, alert).

  cd web-pwa && npm run build && cd ..
  WEBAPP_UPSTREAM=https://iemabot.surrey.ac.uk python3 tools/e2e/task_sandbox_e2e.py [task-path ...]
  e.g. ... m2c2/assessments/pvt-ba/index.html novel-assessments/affective-slider/index.html

Needs `pip install playwright` and a Chromium (`playwright install chromium`, or CHROMIUM_PATH=<binary>).
"""
from __future__ import annotations
import json, mimetypes, os, sys, threading, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
DIST = ROOT / 'dist' / 'pwa'
WEBAPP_DIR = os.environ.get('WEBAPP_DIR')
UPSTREAM = (os.environ.get('WEBAPP_UPSTREAM') or '').rstrip('/')
SANDBOX_EXPECTED = os.environ.get('SANDBOX_EXPECTED', 'allow-scripts allow-same-origin')

PROBE = b"""<!doctype html><meta charset="utf-8"><title>probe</title><body>probe task<script>
const out = {};
try { localStorage.setItem('probe', '1'); out.localStorage = localStorage.getItem('probe') === '1'; } catch (e) { out.localStorage = false; }
try { out.indexedDB = false; const r = indexedDB.open('probe', 1);
  r.onupgradeneeded = () => r.result.createObjectStore('s');
  r.onsuccess = () => { out.indexedDB = true; r.result.close(); finish(); };
  r.onerror = () => finish(); } catch (e) { finish(); }
function finish() {
  try { window.top.location.href = 'https://example.invalid/'; out.topNav = 'allowed'; } catch (e) { out.topNav = 'blocked'; }
  try { out.popup = window.open('about:blank') ? 'allowed' : 'blocked'; } catch (e) { out.popup = 'blocked'; }
  out.fetchApi = 'untested';
  fetch('/esmira/api/studies.php').then(r => { out.fetchApi = r.status; }).catch(() => { out.fetchApi = 'failed'; }).then(() => {
    document.title = JSON.stringify(out);
    parent.postMessage({ type: 'm2c2:complete', summary: { session: 'probe', n_trials: 1, duration_s: 1, correct_count: 1 }, data: [], probe: out }, '*');
  });
}
</script>"""

def study(origin: str, tasks: list[str]) -> dict:
    inputs = [{'name': f'cog{i}', 'responseType': 'webapp', 'text': f'Task {i}', 'url': f'{origin}/webapp/{t}', 'webappDescription': 'd'} for i, t in enumerate(tasks)]
    return {'success': True, 'serverVersion': 12, 'dataset': [{'id': 4242, 'version': 1, 'subVersion': 1, 'lang': 'en', 'new_changes': True, 'published': True,
            'title': 'Sandbox check', 'studyDescription': '<p>e</p>', 'informedConsentForm': 'Consent', 'accessKeys': ['k'], 'langCodes': ['en'], 'webPushEnabled': False,
            'questionnaires': [{'internalId': n + 1, 'title': f'QT{n}', 'pages': [{'inputs': [i]}]} for n, i in enumerate(inputs)],
            # every task is open all day, every day (without a schedule the app lists no questionnaires)
            'schedule': {'checkins': [{'questionnaireId': n + 1, 'parallel': True, 'startType': 'fixed', 'startTime': 0, 'endType': 'fixed', 'endTime': 0,
                                       'responseOverride': True, 'responseMinutes': 1439, 'dayFrom': 0, 'dayUntil': 365, 'text': f'QT{n}'} for n in range(len(inputs))]}}]}

def make_handler(state: dict):
    class H(BaseHTTPRequestHandler):
        def log_message(self, *a): pass
        def send(self, code, body: bytes, ctype='application/octet-stream'):
            self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        def do_POST(self):
            self.rfile.read(int(self.headers.get('Content-Length') or 0)); self.send(200, b'{"success":true}', 'application/json')
        def do_GET(self):
            p = self.path.split('?')[0]
            state['hits'].append(p)
            if p.startswith('/esmira/api/studies.php'): return self.send(200, json.dumps(study(state['origin'], state['tasks'])).encode(), 'application/json')
            if p.startswith('/esmira/'): return self.send(200, b'{"success":false,"secret":"api"}', 'application/json')
            if p == '/webapp/_probe/index.html': return self.send(200, PROBE, 'text/html')
            f = None
            if p.startswith('/webapp/') and WEBAPP_DIR: f = Path(WEBAPP_DIR) / p[len('/webapp/'):]
            elif p.startswith('/pwa/'):
                f = DIST / p[len('/pwa/'):]
                if not f.is_file(): f = DIST / 'index.html'
            if f is not None and f.is_file():
                ct = mimetypes.guess_type(str(f))[0] or ('application/wasm' if f.suffix == '.wasm' else 'application/octet-stream')
                return self.send(200, f.read_bytes(), ct)
            if p.startswith('/webapp/') and UPSTREAM:
                try:
                    with urllib.request.urlopen(UPSTREAM + self.path, timeout=60) as r:
                        ct = r.headers.get('Content-Type') or mimetypes.guess_type(p)[0] or 'application/octet-stream'
                        return self.send(200, r.read(), ct)
                except Exception as e:
                    return self.send(404, str(e).encode(), 'text/plain')
            self.send(404, b'not found', 'text/plain')
    return H

def open_app(page, origin: str, i: int) -> None:
    page.goto(f'{origin}/pwa/?key=k')
    page.wait_for_function('navigator.serviceWorker && navigator.serviceWorker.controller', timeout=20000)
    page.get_by_role('button', name='I consent').first.click(timeout=15000)
    page.wait_for_function("(n) => [...document.querySelectorAll('button')].some(b => b.textContent.includes('QT' + n))", arg=i, timeout=20000)

def finish_task(page, task: str) -> bool | None:
    """Play a known task to its end (412x900 viewport). None when the task has no script here."""
    done = lambda: any(m['type'] == 'm2c2:complete' for m in page.evaluate('window.__msgs'))
    if task.startswith('novel-assessments/affective-slider/'):
        for y in (383, 583):  # drag both sliders, then Submit
            page.mouse.move(206, y); page.mouse.down(); page.mouse.move(260, y, steps=5); page.mouse.move(310, y, steps=5); page.mouse.up()
            page.wait_for_timeout(500)
        page.mouse.click(206, 788)
        page.wait_for_timeout(3000)
        return done()
    if task.startswith('m2c2/assessments/pvt-ba/'):
        page.mouse.click(206, 852)  # Skip tutorial
        page.wait_for_timeout(1500)
        for _ in range(120):  # about 3 minutes at most; tapping is all the test needs
            if done(): return True
            page.mouse.click(206, 450)
            page.wait_for_timeout(900)
        return done()
    return None

def main() -> int:
    tasks = ['_probe/index.html'] + sys.argv[1:]
    state = {'origin': '', 'tasks': tasks, 'hits': []}
    srv = ThreadingHTTPServer(('127.0.0.1', 0), make_handler(state)); state['origin'] = f'http://localhost:{srv.server_port}'
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    problems: list[str] = []
    def check(ok: bool, msg: str):
        print(('ok   ' if ok else 'FAIL ') + msg)
        if not ok: problems.append(msg)
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=['--mute-audio'], executable_path=os.environ.get('CHROMIUM_PATH') or None)
        # --- the service worker's task route: only /webapp/ is served ---
        ctx = b.new_context(viewport={'width': 412, 'height': 900}, service_workers='allow')
        page = ctx.new_page()
        open_app(page, state['origin'], 0)
        sw = lambda path: page.evaluate("async (p) => { const r = await fetch(p); return [r.status, (await r.text()).slice(0, 80)]; }", path)
        status, body = sw('/pwa/t/webapp/_probe/index.html')
        check(status == 200 and 'probe task' in body, f'task route serves /webapp/ files ({status})')
        for bad in ['/pwa/t/esmira/api/studies.php', '/pwa/t/esmira/admin.php', '/pwa/t/webapp/../esmira/api/studies.php', '/pwa/t/webapp/%2e%2e/esmira/api/studies.php',
                    '/pwa/t/webapp/..%2fesmira/api/studies.php', '/pwa/t//example.invalid/x', '/pwa/t/pwa/index.html']:
            before = len([h for h in state['hits'] if h.startswith('/esmira/')])
            status, body = sw(bad)
            leaked = 'secret' in body or '"dataset"' in body
            fetched = len([h for h in state['hits'] if h.startswith('/esmira/')]) - before
            check(status in (403, 404) and not leaked and fetched == 0, f'task route refuses {bad} ({status}, server hits for /esmira/: {fetched})')
        ctx.close()

        # --- each task in a fresh browser profile (a fresh participant, nothing cached from before) ---
        for i, t in enumerate(tasks):
            ctx = b.new_context(viewport={'width': 412, 'height': 900}, service_workers='allow')
            page = ctx.new_page()
            console: list[str] = []
            fails: list[str] = []
            page.on('console', lambda m: console.append(m.text))
            page.on('requestfailed', lambda r: fails.append(r.url) if r.frame and r.frame != page.main_frame else None)
            page.add_init_script("window.__msgs = []; window.addEventListener('message', e => window.__msgs.push({ type: e.data && e.data.type, probe: e.data && e.data.probe, origin: e.origin }));")
            open_app(page, state['origin'], i)
            if i:  # wait for THIS task's offline copy (tasks with a manifest), like a participant who signed up a while ago
                task_dir = f"/webapp/{t.rsplit('/', 1)[0]}/"
                for n in range(240):
                    if page.evaluate("(d) => Object.keys(JSON.parse(localStorage.getItem('esmira_task_cache_web') || '{}').tasks || {}).some(k => k.endsWith(d))", task_dir): break
                    page.wait_for_timeout(500)
                print(f'     offline copy ready after {n * 0.5:.1f} s' if n < 239 else '     no offline copy was made (the app falls back to the network)')
            page.get_by_role('button', name=f'QT{i}').first.click()
            page.get_by_role('button', name=f'Start the Task {i}').first.click(timeout=15000)
            try:
                page.locator('iframe').first.wait_for(timeout=15000)
                sandbox = page.get_attribute('iframe', 'sandbox')
                src = page.get_attribute('iframe', 'src') or ''
                print(f'     {t}: loaded via {"service worker copy" if "/pwa/t/" in src else "network"}')
                check(sandbox == SANDBOX_EXPECTED, f'{t}: iframe sandbox="{sandbox}"')
                if i == 0:
                    page.wait_for_function('window.__msgs.length > 0', timeout=15000)
                else:
                    page.frame_locator('iframe').locator('canvas, h1, h2, button, p, body').first.wait_for(timeout=30000)
                    page.wait_for_timeout(5000)  # let the task load its images, fonts and wasm
                    content = page.evaluate("() => { const d = document.querySelector('iframe').contentDocument; return d ? d.body.innerText.length + d.querySelectorAll('canvas').length : -1 }")
                    check(content > 0, f'{t}: task page rendered inside the sandbox (content={content})')
                    if os.environ.get('E2E_SHOTS'): page.screenshot(path=f'{os.environ["E2E_SHOTS"]}/task_{i}.png')
                    finished = finish_task(page, t)
                    if finished is not None:
                        check(finished, f'{t}: played to the end, m2c2:complete reached the app from the sandboxed frame')
                        page.wait_for_timeout(500)
                        check('completed' in page.inner_text('body').lower(), f'{t}: the app recorded the result (shows completed)')
            except Exception as e:
                check(False, f'{t}: did not load: {str(e).splitlines()[0]}')
            if i == 0:
                msgs = page.evaluate('window.__msgs')
                got = next((m for m in msgs if m['type'] == 'm2c2:complete'), None)
                check(got is not None, 'probe task: m2c2:complete reached the app from the sandboxed frame')
                if got:
                    pr = got['probe']; print('     probe report:', pr)
                    check(pr.get('localStorage') is True and pr.get('indexedDB') is True, 'probe task: localStorage and IndexedDB work')
                    check(pr.get('topNav') == 'blocked' and pr.get('popup') == 'blocked', 'probe task: cannot navigate the app away or open popups')
                page.wait_for_timeout(500)
                check('completed' in page.inner_text('body').lower(), 'probe task: the app recorded the result (shows completed)')
                check(page.url.startswith(state['origin'] + '/pwa/'), f'app was not navigated away ({page.url})')
            else:
                blocked = [m for m in console if 'sandbox' in m.lower() and ('blocked' in m.lower() or 'not allowed' in m.lower())]
                check(not blocked, f'{t}: no sandbox violations in the console ({blocked[:2]})')
                check(not fails, f'{t}: no failed requests inside the task ({fails[:3]})')
            ctx.close()
        b.close()
    print('PASS' if not problems else f'FAIL: {len(problems)} problem(s)')
    return 0 if not problems else 1

if __name__ == '__main__':
    sys.exit(main())
