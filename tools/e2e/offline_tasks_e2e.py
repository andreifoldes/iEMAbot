#!/usr/bin/env python3
"""
End-to-end check that an interactive web task (webapp item) runs OFFLINE in the browser PWA.

Serves the built PWA (dist/pwa), a fixture task tree with task-manifest.json files and a stub
studies.php, drives Chromium through sign-up, goes offline and runs the task.

  cd web-pwa && npx vite build && cd .. && python3 tools/e2e/offline_tasks_e2e.py
"""
from __future__ import annotations
import json, mimetypes, subprocess, sys, tempfile, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
DIST = ROOT / 'dist' / 'pwa'
GEN = ROOT / 'tools' / 'cognitive-tasks' / 'build-task-manifest.mjs'

def make_tasks(tmp: Path) -> Path:
    w = tmp / 'webapp' / 'm2c2'
    t = w / 'assessments' / 'demo'; m = w / 'modules' / 'lib@1.0.0' / 'dist'
    t.mkdir(parents=True); m.mkdir(parents=True)
    (m / 'lib.js').write_text("export const done = () => parent.postMessage({ type: 'm2c2:complete', summary: { session: 'e2e', n_trials: 1, duration_s: 1, correct_count: 1 }, data: [] }, '*');")
    (t / 'index.html').write_text('<!DOCTYPE html><meta charset="utf-8"><script type="importmap">{"imports":{"lib":"../../modules/lib@1.0.0/dist/lib.js"}}</script><script type="module" src="index.js?v=1"></script><body><p id="m">TASK-LOADED</p><button id="b">Finish task</button></body>')
    (t / 'index.js').write_text("import { done } from 'lib'; document.getElementById('b').onclick = done;")
    subprocess.run(['node', str(GEN), str(w)], check=True, capture_output=True)
    return tmp

def study_json(origin: str) -> dict:
    inp = lambda n, rt, **k: {'name': n, 'responseType': rt, **k}
    return {'success': True, 'serverVersion': 12, 'dataset': [{
        'id': 4242, 'version': 1, 'subVersion': 1, 'lang': 'en', 'new_changes': True, 'published': True,
        'title': 'Offline e2e', 'studyDescription': '<p>e2e</p>', 'informedConsentForm': 'Consent', 'accessKeys': ['k'],
        'langCodes': ['en'], 'webPushEnabled': False,
        'questionnaires': [{'internalId': 1, 'title': 'Task check', 'pages': [{'inputs': [
            inp('cog', 'webapp', text='Offline demo task', url=f'{origin}/webapp/m2c2/assessments/demo/index.html', webappDescription='d')]}]}],
    }]}

def main() -> int:
    if not (DIST / 'index.html').exists():
        print('build the PWA first (cd web-pwa && npx vite build)'); return 2
    tmp = Path(tempfile.mkdtemp()); make_tasks(tmp)
    state = {'origin': '', 'posts': []}

    class H(BaseHTTPRequestHandler):
        def log_message(self, fmt, *a): pass
        def send(self, code, body: bytes, ctype='application/octet-stream'):
            self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        def do_POST(self):
            state['posts'].append(self.path.split('?')[0]); self.rfile.read(int(self.headers.get('Content-Length') or 0))
            self.send(200, b'{"success":true}', 'application/json')
        def do_GET(self):
            p = self.path.split('?')[0]
            if p.startswith('/esmira/api/studies.php'): return self.send(200, json.dumps(study_json(state['origin'])).encode(), 'application/json')
            if p.startswith('/esmira/'): return self.send(404, b'{}', 'application/json')
            f = None
            if p.startswith('/webapp/'): f = tmp / p.lstrip('/')
            elif p.startswith('/pwa/'):
                f = DIST / p[len('/pwa/'):]
                if not f.is_file(): f = DIST / 'index.html'
            if f and f.is_file():
                return self.send(200, f.read_bytes(), mimetypes.guess_type(str(f))[0] or 'application/octet-stream')
            self.send(404, b'not found', 'text/plain')

    srv = ThreadingHTTPServer(('127.0.0.1', 0), H); state['origin'] = f'http://localhost:{srv.server_port}'
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    ok = False
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=['--mute-audio'])
        ctx = b.new_context(viewport={'width': 412, 'height': 900}, service_workers='allow')
        page = ctx.new_page()
        page.on('console', lambda m: print('CONSOLE', m.text[:200]) if '[tasks]' in m.text else None)
        page.on('pageerror', lambda e: print('PAGEERROR', str(e)[:300]))
        page.goto(f"{state['origin']}/pwa/?key=k")
        page.wait_for_function('navigator.serviceWorker && navigator.serviceWorker.controller', timeout=20000)
        def body(): return page.inner_text('body')
        def click(text):
            page.get_by_role('button', name=text).first.click(timeout=15000)
        click('I consent')
        page.get_by_placeholder('Enter your name').fill('Tester'); page.keyboard.press('Enter')
        # wait until the questionnaire is offered, and for the task copy to land in the Cache API
        page.wait_for_function("() => [...document.querySelectorAll('button')].some(b => b.textContent.includes('Task check'))", timeout=20000)
        for _ in range(40):  # poll: the copy is downloaded in the background
            if any(k.startswith('esmira-task:') for k in page.evaluate("caches.keys()")): break
            page.wait_for_timeout(500)
        print('cached:', page.evaluate("caches.keys()"))
        ctx.set_offline(True)
        click('Task check')
        click('Start the Offline demo task')
        frame = page.frame_locator('iframe')
        frame.locator('#m').wait_for(timeout=15000)
        print('iframe src:', page.get_attribute('iframe', 'src'))
        assert 'TASK-LOADED' in frame.locator('#m').inner_text()
        frame.locator('#b').click()
        page.wait_for_function("() => document.body.innerText.includes('Completed')", timeout=15000)
        # the finished questionnaire was queued while offline; it must reach the server once back online
        n_off = sum(p.endswith('datasets.php') for p in state['posts'])
        ctx.set_offline(False); page.evaluate("window.dispatchEvent(new Event('online'))")
        for _ in range(40):
            if sum(p.endswith('datasets.php') for p in state['posts']) > n_off: break
            page.wait_for_timeout(500)
        assert sum(p.endswith('datasets.php') for p in state['posts']) > n_off, 'queued dataset never uploaded after reconnect'
        ok = True
        b.close()
    print('PASS: task ran offline in the browser PWA and the result uploaded after reconnect' if ok else 'FAIL')
    return 0 if ok else 1

if __name__ == '__main__':
    sys.exit(main())
