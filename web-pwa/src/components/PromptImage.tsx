/**
 * PromptImage — the picture-description task's image (voice recorder sheet, and the
 * typed task's question card while writing in the chat row), with a magnifier button
 * pinned to the picture's bottom-right corner.
 *
 * The image is `object-contain`, so the visible picture can be narrower or shorter
 * than the <img> box (letterboxed). The button is placed against the drawn picture,
 * not the box, by measuring the box and fitting the natural size into it.
 *
 * Tapping the image or the button opens App's zoomable lightbox: the wrapper is
 * `.esmira-rich`, whose images the delegated click handler picks up, and the button
 * forwards its click to the <img>. The button also gives keyboard and screen-reader
 * users a way in, since the <img> itself is not focusable.
 */

import { useEffect, useRef, useState } from 'react';
import { ZoomIn } from 'lucide-react';
import { cn } from '../lib/utils';
import { t } from '../lib/i18n';

const EDGE_PX = 8; // gap between the button and the picture's corner

interface Props {
  src: string;
  alt: string;
  /** Classes for the wrapper (sizing and placement within the parent). */
  className?: string;
  /** Classes for the <img> (e.g. its height cap). */
  imgClassName?: string;
}

export function PromptImage({ src, alt, className, imgClassName }: Props) {
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const imgRef = useRef<HTMLImageElement | null>(null);
  const [inset, setInset] = useState<{ right: number; bottom: number } | null>(null);

  useEffect(() => {
    const wrap = wrapRef.current;
    const img = imgRef.current;
    if (!wrap || !img) return;
    const measure = () => {
      const { naturalWidth: nw, naturalHeight: nh, clientWidth: w, clientHeight: h } = img;
      if (!nw || !nh || !w || !h) { setInset(null); return; }
      const scale = Math.min(w / nw, h / nh);
      // Distance from the wrapper's right/bottom edge to the drawn picture's corner.
      setInset({
        right: wrap.clientWidth - (img.offsetLeft + w) + (w - nw * scale) / 2,
        bottom: wrap.clientHeight - (img.offsetTop + h) + (h - nh * scale) / 2,
      });
    };
    measure();
    img.addEventListener('load', measure);
    const ro = new ResizeObserver(measure);
    ro.observe(img);
    ro.observe(wrap);
    return () => {
      img.removeEventListener('load', measure);
      ro.disconnect();
    };
  }, [src]);

  return (
    <div ref={wrapRef} className={cn('esmira-rich relative flex justify-center', className)}>
      <img ref={imgRef} src={src} alt={alt} draggable={false} className={imgClassName} />
      {inset && (
        <button
          type="button"
          onClick={() => imgRef.current?.click()}
          aria-label={t('pwa_image_enlarge')}
          className="absolute p-2 rounded-full bg-black/70 text-white shadow-md hover:bg-black/80 active:scale-95 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black"
          style={{ right: inset.right + EDGE_PX, bottom: inset.bottom + EDGE_PX }}
        >
          <ZoomIn size={22} aria-hidden="true" />
        </button>
      )}
    </div>
  );
}
