/**
 * ImageLightbox — fullscreen viewer for images embedded in question rich text
 * (e.g. the picture-description task's kitchen-scene illustration). Opened by
 * App's delegated click handler on any `.esmira-rich img`.
 *
 * Gestures: pinch to zoom (1–5×), drag to pan while zoomed, double-tap or
 * double-click to toggle 2.5× at the tapped point, mouse wheel to zoom,
 * Escape / X / backdrop tap to close. Pointer events give mouse and touch a
 * single code path.
 */

import { useEffect, useRef, useState } from 'react';
import { motion } from 'motion/react';
import { X } from 'lucide-react';
import { useDialogA11y } from '../lib/useDialogA11y';

const MIN_SCALE = 1;
const MAX_SCALE = 5;
const DOUBLE_TAP_SCALE = 2.5;
const DOUBLE_TAP_MS = 300;
const TAP_SLOP_PX = 8;

interface Transform {
  scale: number;
  tx: number;
  ty: number;
}

interface Props {
  src: string;
  alt: string;
  reduceMotion: boolean;
  onClose: () => void;
}

export function ImageLightbox({ src, alt, reduceMotion, onClose }: Props) {
  const dialogRef = useDialogA11y<HTMLDivElement>(true, onClose);
  const [t, setT] = useState<Transform>({ scale: 1, tx: 0, ty: 0 });
  const [gesturing, setGesturing] = useState(false);

  const overlayRef = useRef<HTMLDivElement | null>(null);
  const imgRef = useRef<HTMLImageElement | null>(null);
  const closeRef = useRef<HTMLButtonElement | null>(null);
  // Live transform for gesture math (state lags a frame behind pointer events).
  const tRef = useRef<Transform>(t);
  tRef.current = t;

  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const pinchStart = useRef<{ dist: number; scale: number } | null>(null);
  const dragStart = useRef<{ x: number; y: number; tx: number; ty: number } | null>(null);
  // onBackdrop is captured at pointerdown: setPointerCapture retargets the
  // later pointerup to the overlay, so its e.target can't tell image from backdrop.
  const downInfo = useRef<{ x: number; y: number; time: number; moved: boolean; onBackdrop: boolean } | null>(null);
  const lastTap = useRef<{ x: number; y: number; time: number } | null>(null);

  // Keep the image inside the viewport: never let a zoomed edge pull past centre.
  const clamp = (scale: number, tx: number, ty: number): Transform => {
    const img = imgRef.current;
    const maxX = img ? Math.max(0, (img.clientWidth * scale - window.innerWidth) / 2) : 0;
    const maxY = img ? Math.max(0, (img.clientHeight * scale - window.innerHeight) / 2) : 0;
    return {
      scale,
      tx: Math.min(maxX, Math.max(-maxX, tx)),
      ty: Math.min(maxY, Math.max(-maxY, ty)),
    };
  };

  /** Rescale about a fixed screen point p so the content under p stays put. */
  const zoomAt = (p: { x: number; y: number }, newScale: number) => {
    const { scale, tx, ty } = tRef.current;
    const s = Math.min(MAX_SCALE, Math.max(MIN_SCALE, newScale));
    const cx = window.innerWidth / 2;
    const cy = window.innerHeight / 2;
    const ratio = s / scale;
    setT(clamp(s, p.x - cx - (p.x - cx - tx) * ratio, p.y - cy - (p.y - cy - ty) * ratio));
  };

  const onPointerDown = (e: React.PointerEvent) => {
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
    setGesturing(true);
    const pts = Array.from(pointers.current.values());
    if (pts.length === 2) {
      pinchStart.current = {
        dist: Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y),
        scale: tRef.current.scale,
      };
      dragStart.current = null;
      downInfo.current = null; // a second finger is never a tap
    } else if (pts.length === 1) {
      dragStart.current = { x: e.clientX, y: e.clientY, tx: tRef.current.tx, ty: tRef.current.ty };
      downInfo.current = {
        x: e.clientX,
        y: e.clientY,
        time: performance.now(),
        moved: false,
        onBackdrop: e.target === overlayRef.current,
      };
    }
  };

  const onPointerMove = (e: React.PointerEvent) => {
    if (!pointers.current.has(e.pointerId)) return;
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
    const pts = Array.from(pointers.current.values());

    if (pts.length === 2 && pinchStart.current) {
      const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
      const mid = { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 };
      zoomAt(mid, pinchStart.current.scale * (dist / pinchStart.current.dist));
      return;
    }

    if (pts.length === 1 && dragStart.current) {
      const dx = e.clientX - dragStart.current.x;
      const dy = e.clientY - dragStart.current.y;
      if (downInfo.current && Math.hypot(dx, dy) > TAP_SLOP_PX) downInfo.current.moved = true;
      if (tRef.current.scale > 1) {
        setT(clamp(tRef.current.scale, dragStart.current.tx + dx, dragStart.current.ty + dy));
      }
    }
  };

  const onPointerUp = (e: React.PointerEvent) => {
    pointers.current.delete(e.pointerId);
    const remaining = Array.from(pointers.current.entries());
    if (remaining.length === 0) setGesturing(false);
    pinchStart.current = null;
    if (remaining.length === 1) {
      // Pinch ended with one finger still down: restart the pan from there.
      const [, p] = remaining[0];
      dragStart.current = { x: p.x, y: p.y, tx: tRef.current.tx, ty: tRef.current.ty };
      return;
    }
    dragStart.current = null;

    const down = downInfo.current;
    downInfo.current = null;
    if (!down || down.moved || performance.now() - down.time > 250) return;

    // Quick tap: double-tap toggles zoom; single tap on the backdrop closes.
    const tap = { x: e.clientX, y: e.clientY, time: performance.now() };
    const prev = lastTap.current;
    if (prev && tap.time - prev.time < DOUBLE_TAP_MS && Math.hypot(tap.x - prev.x, tap.y - prev.y) < 40) {
      lastTap.current = null;
      if (tRef.current.scale > 1.01) setT({ scale: 1, tx: 0, ty: 0 });
      else zoomAt(tap, DOUBLE_TAP_SCALE);
      return;
    }
    lastTap.current = tap;
    if (down.onBackdrop && tRef.current.scale <= 1.01) onClose();
  };

  // Wheel zoom (desktop). Native listener: React's synthetic wheel handlers are
  // passive, so preventDefault (needed to stop page scroll chaining) only works here.
  useEffect(() => {
    const el = overlayRef.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      const factor = Math.exp(-e.deltaY / 400);
      const { scale, tx, ty } = tRef.current;
      const s = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale * factor));
      const cx = window.innerWidth / 2;
      const cy = window.innerHeight / 2;
      const ratio = s / scale;
      const img = imgRef.current;
      const maxX = img ? Math.max(0, (img.clientWidth * s - window.innerWidth) / 2) : 0;
      const maxY = img ? Math.max(0, (img.clientHeight * s - window.innerHeight) / 2) : 0;
      setT({
        scale: s,
        tx: Math.min(maxX, Math.max(-maxX, e.clientX - cx - (e.clientX - cx - tx) * ratio)),
        ty: Math.min(maxY, Math.max(-maxY, e.clientY - cy - (e.clientY - cy - ty) * ratio)),
      });
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, []);

  // Escape closes; lock body scroll behind the overlay; focus the close button.
  // Mount-only (onClose read via ref) so App re-renders don't steal focus back.
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onCloseRef.current();
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    closeRef.current?.focus();
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, []);

  return (
    <motion.div
      ref={(el) => { overlayRef.current = el; dialogRef.current = el; }}
      tabIndex={-1}
      role="dialog"
      aria-modal="true"
      aria-label={alt || 'Enlarged image'}
      initial={reduceMotion ? { opacity: 1 } : { opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: reduceMotion ? 0 : 0.15 }}
      className="fixed inset-0 z-[130] bg-black/90 flex items-center justify-center select-none"
      style={{ touchAction: 'none' }}
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
    >
      <img
        ref={imgRef}
        src={src}
        alt={alt}
        draggable={false}
        className="max-w-full max-h-full"
        style={{
          transform: `translate(${t.tx}px, ${t.ty}px) scale(${t.scale})`,
          transition: gesturing || reduceMotion ? 'none' : 'transform 0.25s ease-out',
        }}
      />
      <p className="absolute bottom-4 left-1/2 -translate-x-1/2 text-white/60 text-xs font-medium pointer-events-none">
        Pinch or double-tap to zoom
      </p>
      <button
        ref={closeRef}
        onClick={onClose}
        aria-label="Close enlarged image"
        className="absolute top-4 right-4 p-2.5 bg-white/15 hover:bg-white/25 rounded-full text-white transition-colors active:scale-95"
      >
        <X size={20} aria-hidden="true" />
      </button>
    </motion.div>
  );
}
