#!/usr/bin/env python3
"""
Offline check of REAL task bundles in the browser PWA.

Point it at a local copy of the host's /webapp tree (m2c2/ and novel-assessments/ folders, e.g. an
rsync of the server's directory or a crawl of the tasks) and a list of task paths. It generates the
manifests, serves everything from a local origin, signs up in Chromium, goes offline and opens each
task, failing on any request that fails or any task that does not render.

  cd web-pwa && npx vite build && cd ..
  python3 tools/e2e/offline_real_tasks_e2e.py <webapp-dir> <task-path> [<task-path> ...]
  e.g. ... ~/mirror/webapp m2c2/assessments/pvt-ba/index.html?show_end_screen=true novel-assessments/dma/index.html
"""
from __future__ import annotations
import json, mimetypes, subprocess, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import quote
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
DIST = ROOT / 'dist' / 'pwa'
GEN = ROOT / 'tools' / 'cognitive-tasks' / 'build-task-manifest.mjs'

def main() -> int:
    if len(sys.argv) < 3: print(__doc__); return 2
    web = Path(sys.argv[1]).resolve(); tasks = sys.argv[2:]
    for sub in sorted(p for p in web.iterdir() if p.is_dir()):
        r = subprocess.run(['node', str(GEN), str(sub)], capture_output=True, text=True)
        print(f'manifests {sub.name}:', r.stdout.strip().replace('\n', '; ')[:300], r.stderr.strip()[:200])
    state = {'origin': '', 'failed': []}

    def study():
        inputs = [{'name': f'cog{i}', 'responseType': 'webapp', 'text': f'Task {i}', 'url': f"{state['origin']}/webapp/{t}", 'webappDescription': 'd'} for i, t in enumerate(tasks)]
        return {'success': True, 'serverVersion': 12, 'dataset': [{'id': 4242, 'version': 1, 'subVersion': 1, 'lang': 'en', 'new_changes': True, 'published': True,
                'title': 'Real tasks offline', 'studyDescription': '<p>e</p>', 'informedConsentForm': 'Consent', 'accessKeys': ['k'], 'langCodes': ['en'], 'webPushEnabled': False,
                'questionnaires': [{'internalId': n + 1, 'title': f'QT{n}', 'pages': [{'inputs': [i]}]} for n, i in enumerate(inputs)]}]}

    class H(BaseHTTPRequestHandler):
        def log_message(self, *a): pass
        def send(self, code, body: bytes, ctype='application/octet-stream'):
            self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        def do_POST(self):
            self.rfile.read(int(self.headers.get('Content-Length') or 0)); self.send(200, b'{"success":true}', 'application/json')
        def do_GET(self):
            p = self.path.split('?')[0]
            if p.startswith('/esmira/api/studies.php'): return self.send(200, json.dumps(study()).encode(), 'application/json')
            if p.startswith('/esmira/'): return self.send(404, b'{}', 'application/json')
            f = None
            if p.startswith('/webapp/'): f = web / p[len('/webapp/'):]
            elif p.startswith('/pwa/'):
                f = DIST / p[len('/pwa/'):]
                if not f.is_file(): f = DIST / 'index.html'
            if f and f.is_file():
                ct = mimetypes.guess_type(str(f))[0] or ('application/wasm' if f.suffix == '.wasm' else 'application/octet-stream')
                return self.send(200, f.read_bytes(), ct)
            self.send(404, b'not found', 'text/plain')

    srv = ThreadingHTTPServer(('127.0.0.1', 0), H); state['origin'] = f'http://localhost:{srv.server_port}'
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    bad = []
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=['--mute-audio'])
        ctx = b.new_context(viewport={'width': 412, 'height': 900}, service_workers='allow')
        page = ctx.new_page()
        page.goto(f"{state['origin']}/pwa/?key=k")
        page.wait_for_function('navigator.serviceWorker && navigator.serviceWorker.controller', timeout=20000)
        page.get_by_role('button', name='I consent').first.click(timeout=15000)
        page.get_by_placeholder('Enter your name').fill('Tester'); page.keyboard.press('Enter')
        page.wait_for_function("() => [...document.querySelectorAll('button')].some(b => b.textContent.includes('QT0'))", timeout=20000)
        want = len({t.split('?')[0].rsplit('/', 1)[0] for t in tasks})
        for _ in range(360):   # a set is only recorded once COMPLETE; big task sets (images, wasm) take a while
            done = page.evaluate("Object.keys(JSON.parse(localStorage.getItem('esmira_task_cache_web') || '{}').tasks || {}).length")
            if done >= want: break
            page.wait_for_timeout(500)
        print('cached task sets:', done, 'files:', page.evaluate("caches.open('esmira-task-files').then(c => c.keys()).then(k => k.length)"))
        ctx.set_offline(True)
        for i, t in enumerate(tasks):
            # relaunch the app OFFLINE for every task: shell from the service worker, study from its cache
            page.goto(f"{state['origin']}/pwa/?key=k")
            page.wait_for_function("(n) => [...document.querySelectorAll('button')].some(b => b.textContent.includes('QT' + n))", arg=i, timeout=20000)
            page.get_by_role('button', name=f'QT{i}').first.click()
            page.get_by_role('button', name=f'Start the Task {i}').first.click(timeout=15000)
            fl = page.frame_locator('iframe')
            fails = []
            on_fail = lambda r, fails=fails: fails.append(r.url) if r.frame and r.frame != page.main_frame else None  # the task iframe's own requests
            page.on('requestfailed', on_fail)
            try:
                fl.locator('canvas, h1, h2, button, p').first.wait_for(timeout=20000)
                page.wait_for_timeout(4000)  # let the task load its images/fonts/wasm
                rendered = page.evaluate("() => { const d = document.querySelector('iframe').contentDocument; return !!d && d.body && d.body.innerText.length + d.querySelectorAll('canvas').length > 0 }")
            except Exception as e:
                rendered = False; fails.append(f'no render: {e}'.splitlines()[0])
            page.remove_listener('requestfailed', on_fail)
            src = page.get_attribute('iframe', 'src')
            via = 'cache' if '/pwa/t/' in (src or '') else 'NETWORK'
            status = 'ok ' if rendered and not fails and via == 'cache' else 'BAD'
            print(f'{status} {t.split("?")[0]}  via={via} failed_requests={len(fails)}')
            for u in fails[:5]: print('     ', u)
            if status == 'BAD': bad.append(t)
        b.close()
    print('PASS' if not bad else f'FAIL: {len(bad)} task(s)')
    return 0 if not bad else 1

if __name__ == '__main__':
    sys.exit(main())
