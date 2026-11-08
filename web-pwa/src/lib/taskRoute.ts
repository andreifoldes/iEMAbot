/**
 * Which server paths the offline task route (src/sw.ts, `<base>t/<path>`) may serve.
 *
 * Hosted cognitive tasks live under /webapp/ on the server. The service worker maps
 * `<base>t/webapp/...` back to `/webapp/...`; without this check it would fetch any same-origin
 * path for any page in its scope, including the API and admin pages.
 *
 * src/sw.ts keeps its own copy of isTaskPath between the `task-path-check` markers (the worker
 * imports nothing from the app, see its swOpenState comment); taskRoute.test.mts runs that copy
 * against the same cases, so the two cannot drift apart unnoticed.
 */

export const TASK_ROOT = '/webapp/';

/** True for a normal path below TASK_ROOT: no dot segments, no encoded slashes or backslashes, no empty segments. */
export function isTaskPath(pathname: string): boolean {
  if (!pathname.startsWith(TASK_ROOT) || pathname.length === TASK_ROOT.length) return false;
  if (pathname.includes('\\') || /%(2f|5c|00)/i.test(pathname)) return false;
  const segments = pathname.slice(TASK_ROOT.length).split('/');
  return segments.every((segment, i) => {
    if (segment === '') return i === segments.length - 1; // a trailing slash is a directory, "//" is not allowed
    try {
      const decoded = decodeURIComponent(segment);
      return decoded !== '.' && decoded !== '..' && !decoded.includes('/') && !decoded.includes('\\');
    } catch {
      return false;
    }
  });
}

/**
 * `sandbox` of the iframe that hosts a cognitive/m2c2 task.
 *
 * - allow-scripts: the task is a script-driven page.
 * - allow-same-origin: the task keeps its origin, which it needs for IndexedDB / localStorage (m2c2kit
 *   stores its session there) and for loading its own modules and assets from the server.
 * Left out on purpose: allow-top-navigation (a task cannot navigate the app away), allow-popups,
 * allow-modals, allow-downloads, allow-orientation-lock, allow-pointer-lock. The result goes back
 * to the app by postMessage, which a sandboxed frame can always send.
 */
export const TASK_IFRAME_SANDBOX = 'allow-scripts allow-same-origin';
