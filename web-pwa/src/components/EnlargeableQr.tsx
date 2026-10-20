import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { X } from 'lucide-react';

/** The QR library only ships to the browsers that actually show a code. */
const InstallQr = lazy(() => import('./InstallQr'));

/** Largest enlarged code, in px. Phones get the screen width minus a margin instead. */
const MAX_ENLARGED_PX = 480;

function QrSpinner({ size }: { size: number }) {
  return (
    <div style={{ width: size, height: size }} className="flex items-center justify-center bg-white rounded-xl">
      <div className="w-6 h-6 border-2 border-primary border-t-transparent rounded-full animate-spin" />
    </div>
  );
}

/**
 * Full-screen, enlarged QR code for scanning from across a desk or a projector.
 * Closes on Esc, on the X, and on a tap outside the code. Focus moves to the close
 * button and stays inside the dialog (it is the only control) until it closes.
 */
export function QrDialog({ url, onClose }: { url: string; onClose: () => void }) {
  const closeRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      else if (e.key === 'Tab') {
        e.preventDefault();
        closeRef.current?.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const size = Math.max(200, Math.min(MAX_ENLARGED_PX, (typeof window !== 'undefined' ? window.innerWidth : MAX_ENLARGED_PX) - 48));
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="QR code, enlarged"
      className="fixed inset-0 z-[80] bg-black/75 flex items-center justify-center p-6"
      onClick={onClose}
    >
      <div className="relative" onClick={(e) => e.stopPropagation()}>
        <button
          ref={closeRef}
          onClick={onClose}
          aria-label="Close enlarged QR code"
          className="absolute -top-3 -right-3 z-10 w-10 h-10 rounded-full bg-white text-slate-900 shadow-lg flex items-center justify-center"
        >
          <X size={20} aria-hidden="true" />
        </button>
        <Suspense fallback={<QrSpinner size={size} />}>
          <InstallQr url={url} size={size} />
        </Suspense>
      </div>
    </div>
  );
}

/** A QR code that opens {@link QrDialog} when tapped or activated from the keyboard. */
export function EnlargeableQr({ url }: { url: string }) {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const close = useCallback(() => {
    setOpen(false);
    triggerRef.current?.focus();
  }, []);
  return (
    <>
      <button
        ref={triggerRef}
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        aria-label="Enlarge QR code"
        className="rounded-xl cursor-zoom-in focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <Suspense fallback={<QrSpinner size={176} />}>
          <InstallQr url={url} />
        </Suspense>
      </button>
      {open && <QrDialog url={url} onClose={close} />}
    </>
  );
}
