#!/usr/bin/env python3
"""
Builds web-pwa/src/data/task-manifests.json by loading every task a study launches in a real browser
and recording each /webapp/ file it fetches (path, size, sha256). Works from the public site, so it
needs no access to the host's disk.

The apps use these as a fallback when a task folder publishes no task-manifest.json of its own
(see build-task-manifest.mjs), which makes offline task copies work without any server-side step.
Re-run after a task is updated (a changed file size just makes the offline copy fall back to the network).

  python3 tools/cognitive-tasks/crawl-task-manifests.py https://iemabot.surrey.ac.uk ssrc [more-keys ...]
"""
from __future__ import annotations
import hashlib, json, os, sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

OUT = Path(__file__).resolve().parents[2] / 'web-pwa' / 'src' / 'data' / 'task-manifests.json'

def task_urls(study: dict) -> set[str]:
    out = set()
    for q in study.get('questionnaires', []):
        for p in q.get('pages', []):
            for i in p.get('inputs', []):
                u = i.get('url')
                if u and '/webapp/' in u: out.add(u)
    return out

def main() -> int:
    if len(sys.argv) < 3: print(__doc__); return 2
    base, keys = sys.argv[1].rstrip('/'), sys.argv[2:]
    host = urlparse(base).netloc
    manifests: dict[str, dict] = {}
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=['--mute-audio'])
        urls: set[str] = set()
        for k in keys:
            r = b.new_context(ignore_https_errors=True).request.get(f'{base}/esmira/api/studies.php?access_key={k}')
            for s in r.json().get('dataset', []): urls |= task_urls(s)
        for url in sorted(urls):
            u = urlparse(url)
            if u.netloc != host: print('skip (other host):', url); continue
            ctx = b.new_context(viewport={'width': 412, 'height': 860}, ignore_https_errors=True); page = ctx.new_page()
            got: dict[str, bytes] = {}
            def on_resp(r):
                p = urlparse(r.url)
                if p.netloc == host and p.path.startswith('/webapp/') and r.status == 200:
                    try: got[p.path] = r.body()
                    except Exception: pass
            page.on('response', on_resp)
            page.goto(url + ('&' if '?' in url else '?') + 'embed=1&v=3', timeout=45000)
            page.wait_for_timeout(8000)
            for _ in range(3): page.mouse.click(206, 700); page.wait_for_timeout(1500)  # nudge past intro screens
            ctx.close()
            task_dir = os.path.dirname(u.path) + '/'
            # the folder the task's relative imports resolve in = deepest common ancestor of everything it loaded
            root = os.path.commonpath([os.path.dirname(p) for p in got] + [task_dir.rstrip('/')]) + '/'
            rel_up = '../' * (len(task_dir.strip('/').split('/')) - len(root.strip('/').split('/'))) or './'
            files = [{'path': p[len(root):], 'size': len(body), 'sha256': hashlib.sha256(body).hexdigest()} for p, body in sorted(got.items())]
            version = hashlib.sha256('\n'.join(f"{f['path']}:{f['sha256']}" for f in files).encode()).hexdigest()[:16]
            manifests[task_dir] = {'version': version, 'root': rel_up, 'files': files}
            print(f'{task_dir}: {len(files)} files, {sum(f["size"] for f in files) // 1000} KB, root {rel_up}')
        b.close()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(manifests, separators=(',', ':'), sort_keys=True))
    print('wrote', OUT)
    return 0

if __name__ == '__main__':
    sys.exit(main())
