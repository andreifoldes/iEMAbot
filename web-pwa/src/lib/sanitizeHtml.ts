import DOMPurify from 'dompurify';

/**
 * Study content (question text, subtext, instructions, researcher messages, ...) is HTML written by researchers in
 * ESMira's rich-text editor and is rendered with dangerouslySetInnerHTML. A study can come from another researcher or
 * from a server this app was pointed at, so it is passed through this allowlist first: nothing in it can run script or
 * restyle the app.
 *
 * Allowed: what the editor can produce plus a few harmless text containers. Everything else (script, style, iframe,
 * form controls, svg, event handlers, classes, ids, javascript: URLs, ...) is dropped. Keep in sync with
 * src/frontend/ts/helpers/sanitizeHtml.ts in the admin UI.
 */
const ALLOWED_TAGS = [
  'p', 'br', 'div', 'span', 'hr',
  'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'mark', 'small', 'sub', 'sup', 'code', 'pre', 'blockquote',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'ul', 'ol', 'li',
  'a', 'img',
];
const ALLOWED_ATTR = ['href', 'target', 'rel', 'src', 'alt', 'title', 'width', 'height', 'style'];
const TEXT_ALIGN_ONLY = /^\s*text-align\s*:\s*(left|right|center|justify)\s*;?\s*$/i;

let purifier: ReturnType<typeof DOMPurify> | null = null;

function getPurifier(): ReturnType<typeof DOMPurify> | null {
  if (purifier) return purifier;
  if (typeof window === 'undefined') return null;
  const instance = DOMPurify(window);
  if (!instance.isSupported) return null;

  instance.addHook('afterSanitizeAttributes', (node: Element) => {
    if (node.hasAttribute('style') && !TEXT_ALIGN_ONLY.test(node.getAttribute('style') ?? '')) {
      node.removeAttribute('style');
    }
    if (node.tagName === 'A') {
      if (node.getAttribute('target') !== '_blank') node.removeAttribute('target');
      if (node.hasAttribute('href')) node.setAttribute('rel', 'noopener noreferrer');
    }
  });
  purifier = instance;
  return instance;
}

/**
 * HTML that is safe for dangerouslySetInnerHTML. Without a DOM (never in a browser, e.g. in node tests) it returns
 * '' rather than something unsafe.
 */
export function sanitizeHtml(html: string | null | undefined): string {
  if (!html) return '';
  const instance = getPurifier();
  if (!instance) return '';
  return instance.sanitize(String(html), {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    KEEP_CONTENT: true,
    RETURN_TRUSTED_TYPE: false,
  }) as string;
}
