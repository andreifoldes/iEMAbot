import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export interface QuestionHtmlParts {
  /** HTML before the first embedded image — the short pitch shown on the chat card. */
  intro: string;
  image: { src: string; alt: string } | null;
  /** HTML after the first embedded image — detailed instructions shown in the answer modal. */
  detail: string;
}

/**
 * Splits question rich text around its first embedded <img>.
 *
 * Convention for picture tasks (audio / keystroke questions): the text before
 * the image is the task pitch and stays on the chat card; the image and the
 * text after it appear only inside the recorder/writing modal. Questions
 * without an embedded image keep everything in `intro`.
 */
export function splitQuestionHtml(html: string): QuestionHtmlParts {
  const body = new DOMParser().parseFromString(html, 'text/html').body;
  const img = body.querySelector('img');
  const src = img?.getAttribute('src');
  if (!img || !src) return { intro: html, image: null, detail: '' };

  // Split at the image's top-level container so its wrapper div goes with it.
  let top: Element = img;
  while (top.parentElement && top.parentElement !== body) top = top.parentElement;
  const before: string[] = [];
  const after: string[] = [];
  let seen = false;
  body.childNodes.forEach((node) => {
    if (node === top) { seen = true; return; }
    (seen ? after : before).push(node instanceof Element ? node.outerHTML : (node.textContent ?? ''));
  });
  return {
    intro: trimEdgeBreaks(before.join('')),
    image: { src, alt: img.getAttribute('alt') ?? '' },
    detail: trimEdgeBreaks(after.join('')),
  };
}

/** Drop the <br> separators left dangling at the split edges. */
function trimEdgeBreaks(html: string): string {
  return html.replace(/^(\s*<br\s*\/?>)+/i, '').replace(/(<br\s*\/?>\s*)+$/i, '').trim();
}
