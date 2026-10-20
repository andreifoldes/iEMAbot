/**
 * AudioRecorder — a modal voice-memo recorder for `record_audio` questions.
 *
 * Flow: recording (with live waveform + REC timer) → Stop → review (play back the
 * clip before committing) → Save. Redo restarts at any point; the X / backdrop
 * discards without committing. Recording begins as soon as the sheet opens (mic
 * permission is requested up front).
 *
 * Capture uses MediaRecorder; the live waveform is driven by a Web Audio
 * AnalyserNode sampled on an interval. The recorded container (WebM on
 * Chrome/Android, MP4 on Safari/iOS) satisfies the server's `/video/i` mime check
 * in file_uploads.php. Review playback uses a plain <audio> element on a blob URL.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { motion } from 'motion/react';
import { X, Pause, Play, Mic, Square, RotateCcw, Check } from 'lucide-react';
import { cn, splitQuestionHtml } from '../lib/utils';
import { useDialogA11y } from '../lib/useDialogA11y';
import { newAudioIdentifier } from '../lib/audioUploads';
import type { PreloadedQuestion } from '../types';

const BAR_COUNT = 44;
const SAMPLE_MS = 90; // waveform sample + timer tick cadence

type Status = 'starting' | 'recording' | 'paused' | 'review' | 'denied' | 'error';

function pickMimeType(): string {
  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
  const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/ogg;codecs=opus'];
  for (const t of candidates) if (MediaRecorder.isTypeSupported(t)) return t;
  return '';
}

function fmt(sec: number): string {
  const s = Math.max(0, Math.floor(sec));
  const m = Math.floor(s / 60);
  return `${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

/** First line of the (rich-text/HTML) prompt — used as the compact modal title. */
function firstLine(html: string): string {
  const text = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(div|p|h[1-6]|li|tr)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&');
  const line = text.split('\n').map((s) => s.trim()).find(Boolean);
  return line || 'Voice memo';
}

interface Props {
  question: PreloadedQuestion;
  reduceMotion: boolean;
  onCancel: () => void;
  onSave: (identifier: number, blob: Blob) => void;
}

export function AudioRecorder({ question, reduceMotion, onCancel, onSave }: Props) {
  const dialogRef = useDialogA11y<HTMLDivElement>(true, onCancel);
  const maxSec = question.max_recording_seconds ?? 300;
  // Picture-description prompts embed the image mid-text: the pitch before it
  // stays on the chat card, while the picture and the instructions after it
  // are shown here. The esmira-rich wrapper makes the image tap-to-enlarge via
  // App's delegated lightbox handler.
  const { intro, image: promptImage, detail } = splitQuestionHtml(question.text || '');
  const title = firstLine(intro || question.text || '');

  const [status, setStatus] = useState<Status>('starting');
  const [elapsed, setElapsed] = useState(0);
  const [bars, setBars] = useState<number[]>(() => new Array(BAR_COUNT).fill(0));
  // Review-phase state:
  const [reviewUrl, setReviewUrl] = useState<string | null>(null);
  const [reviewDur, setReviewDur] = useState(0); // recorded length (s)
  const [playPos, setPlayPos] = useState(0);      // playback position (s)
  const [isPlaying, setIsPlaying] = useState(false);

  const streamRef = useRef<MediaStream | null>(null);
  const ctxRef = useRef<AudioContext | null>(null);
  const analyserRef = useRef<AnalyserNode | null>(null);
  const recRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const tickRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const accumRef = useRef(0); // ms recorded before the current segment
  const startedAtRef = useRef<number | null>(null); // start of current segment
  const barsRef = useRef<number[]>(new Array(BAR_COUNT).fill(0));
  const statusRef = useRef<Status>('starting');
  const committedRef = useRef(false);
  const recordedBlobRef = useRef<Blob | null>(null);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  statusRef.current = status;

  const elapsedMs = useCallback(
    () => accumRef.current + (startedAtRef.current != null ? Date.now() - startedAtRef.current : 0),
    [],
  );

  const stopTick = useCallback(() => {
    if (tickRef.current) { clearInterval(tickRef.current); tickRef.current = null; }
  }, []);

  const stopMedia = useCallback(() => {
    try { recRef.current?.state !== 'inactive' && recRef.current?.stop(); } catch { /* already stopped */ }
    streamRef.current?.getTracks().forEach((t) => t.stop());
    streamRef.current = null;
    try { void ctxRef.current?.close(); } catch { /* ignore */ }
    ctxRef.current = null;
    analyserRef.current = null;
  }, []);

  const cleanup = useCallback(() => {
    stopTick();
    stopMedia();
    recRef.current = null;
    try { audioRef.current?.pause(); } catch { /* ignore */ }
    if (reviewUrl) URL.revokeObjectURL(reviewUrl);
  }, [stopTick, stopMedia, reviewUrl]);

  const sample = useCallback(() => {
    const analyser = analyserRef.current;
    let amp = 0;
    if (analyser) {
      const buf = new Uint8Array(analyser.fftSize);
      analyser.getByteTimeDomainData(buf);
      let sum = 0;
      for (let i = 0; i < buf.length; i++) {
        const v = (buf[i] - 128) / 128;
        sum += v * v;
      }
      amp = Math.min(1, Math.sqrt(sum / buf.length) * 3.2); // RMS, scaled for visibility
    }
    const next = barsRef.current.slice(1);
    next.push(amp);
    barsRef.current = next;
    setBars(next);
  }, []);

  const startTick = useCallback(() => {
    stopTick();
    tickRef.current = setInterval(() => {
      if (statusRef.current !== 'recording') return;
      sample();
      const sec = elapsedMs() / 1000;
      setElapsed(sec);
      if (sec >= maxSec) stopRef.current?.(); // hit the cap → finish to review
    }, SAMPLE_MS);
  }, [elapsedMs, maxSec, sample, stopTick]);

  const beginRecorder = useCallback((stream: MediaStream) => {
    const mime = pickMimeType();
    const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
    chunksRef.current = [];
    rec.ondataavailable = (e) => { if (e.data && e.data.size) chunksRef.current.push(e.data); };
    recRef.current = rec;
    rec.start();
    accumRef.current = 0;
    startedAtRef.current = Date.now();
    barsRef.current = new Array(BAR_COUNT).fill(0);
    setBars(barsRef.current);
    setElapsed(0);
    setStatus('recording');
    startTick();
  }, [startTick]);

  const start = useCallback(async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      streamRef.current = stream;
      const AC: typeof AudioContext =
        window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      const ctx = new AC();
      ctxRef.current = ctx;
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      ctx.createMediaStreamSource(stream).connect(analyser);
      analyserRef.current = analyser;
      beginRecorder(stream);
    } catch (e) {
      const name = (e as { name?: string })?.name;
      setStatus(name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : 'error');
    }
  }, [beginRecorder]);

  // Finish recording → build the clip and enter the review/playback phase.
  const stop = useCallback(() => {
    const rec = recRef.current;
    if (!rec) return;
    stopTick();
    const dur = elapsedMs() / 1000;
    const finalize = () => {
      const blob = new Blob(chunksRef.current, { type: rec.mimeType || 'audio/webm' });
      recordedBlobRef.current = blob;
      stopMedia(); // release the mic — recording is done
      setReviewDur(dur);
      setPlayPos(0);
      setIsPlaying(false);
      setReviewUrl(URL.createObjectURL(blob));
      setStatus('review');
    };
    rec.onstop = finalize;
    if (rec.state !== 'inactive') rec.stop();
    else finalize();
  }, [elapsedMs, stopMedia, stopTick]);

  // Let the interval reach stop() without a stale closure.
  const stopRef = useRef<() => void>(() => {});
  stopRef.current = stop;

  const togglePause = useCallback(() => {
    const rec = recRef.current;
    if (!rec) return;
    if (statusRef.current === 'recording') {
      accumRef.current = elapsedMs();
      startedAtRef.current = null;
      try { rec.state === 'recording' && rec.pause(); } catch { /* pause unsupported — UI still freezes */ }
      setStatus('paused');
    } else if (statusRef.current === 'paused') {
      startedAtRef.current = Date.now();
      try { rec.state === 'paused' && rec.resume(); } catch { /* ignore */ }
      setStatus('recording');
    }
  }, [elapsedMs]);

  const playPause = useCallback(() => {
    const a = audioRef.current;
    if (!a) return;
    if (a.paused) {
      if (a.ended || a.currentTime >= reviewDur) a.currentTime = 0;
      void a.play();
      setIsPlaying(true);
    } else {
      a.pause();
      setIsPlaying(false);
    }
  }, [reviewDur]);

  const redo = useCallback(() => {
    try { audioRef.current?.pause(); } catch { /* ignore */ }
    if (reviewUrl) { URL.revokeObjectURL(reviewUrl); setReviewUrl(null); }
    recordedBlobRef.current = null;
    setIsPlaying(false);
    setPlayPos(0);
    setReviewDur(0);
    setStatus('starting');
    void start(); // re-acquire mic + record again
  }, [reviewUrl, start]);

  const commit = useCallback(() => {
    if (committedRef.current) return;
    const blob = recordedBlobRef.current;
    if (!blob) { onCancel(); return; }
    committedRef.current = true;
    try { audioRef.current?.pause(); } catch { /* ignore */ }
    if (reviewUrl) URL.revokeObjectURL(reviewUrl);
    onSave(newAudioIdentifier(), blob);
  }, [onCancel, onSave, reviewUrl]);

  // Start on mount; tear everything down on unmount (covers X/backdrop cancel).
  useEffect(() => {
    void start();
    return cleanup;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const recording = status === 'recording';
  const paused = status === 'paused';
  const review = status === 'review';
  const recordingPhase = recording || paused;
  const playedFrac = review && reviewDur > 0 ? Math.min(1, playPos / reviewDur) : 0;
  const playedBars = Math.round(playedFrac * BAR_COUNT);

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onCancel} aria-hidden="true" />
      <motion.div
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 24 }}
        animate={{ opacity: 1, y: 0 }}
        // With a prompt image the sheet goes full-height and lays out as a flex
        // column: controls pin to the bottom and the picture absorbs all leftover
        // space (flex-1) — as large as the screen allows for older participants.
        className={cn(
          'relative w-full sm:max-w-md flex flex-col max-h-[94dvh] overflow-y-auto bg-white dark:bg-surface-container-lowest rounded-t-3xl sm:rounded-3xl shadow-2xl px-6 pt-4 pb-7',
          promptImage && 'h-[94dvh] sm:h-auto',
        )}
      >
        {/* Grab handle + header */}
        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-outline-variant/50 sm:hidden" />
        {/* Title centered relative to the modal width; close button overlaid at the
            right so it doesn't offset the centering. px-9 keeps a long title clear of it. */}
        <div className="relative flex items-center justify-center min-h-8">
          <h2 className="text-lg font-bold text-on-surface text-center truncate px-9">{title}</h2>
          <button
            onClick={onCancel}
            aria-label="Close"
            className="absolute right-0 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-on-surface-variant hover:bg-surface-container-high active:scale-95 transition"
          >
            <X size={22} aria-hidden="true" />
          </button>
        </div>

        {status === 'denied' || status === 'error' ? (
          <div className="py-8 text-center">
            <p className="text-on-surface font-medium">
              {status === 'denied'
                ? 'Microphone access is needed to record a voice memo.'
                : "Recording isn't available on this device or browser."}
            </p>
            {status === 'denied' && (
              <p className="mt-2 text-sm text-on-surface-variant">
                Allow microphone access in your browser, then try again.
              </p>
            )}
            <div className="mt-6 flex justify-center gap-3">
              {status === 'denied' && (
                <button
                  onClick={() => { setStatus('starting'); void start(); }}
                  className="bg-primary text-on-primary font-bold px-6 py-3 rounded-full active:scale-95 transition"
                >
                  Try again
                </button>
              )}
              <button
                onClick={onCancel}
                className="bg-surface-container-high text-on-surface font-semibold px-6 py-3 rounded-full active:scale-95 transition"
              >
                Close
              </button>
            </div>
          </div>
        ) : (
          <>
            {promptImage && (
              // Full-bleed (-mx-6 cancels the card padding) so the picture reaches
              // the width-bound maximum its 4:3 aspect allows on a phone.
              <div className="esmira-rich mt-2 -mx-6 flex-1 min-h-0 flex justify-center">
                <img
                  src={promptImage.src}
                  alt={promptImage.alt}
                  draggable={false}
                  className="w-full max-h-full self-center object-contain"
                />
              </div>
            )}
            {detail && (
              // Capped + scrollable so long instructions never squeeze the picture
              // or push the recording controls off-screen.
              <div
                className="mt-3 max-h-16 overflow-y-auto shrink-0 text-sm text-on-surface-variant leading-relaxed esmira-rich"
                dangerouslySetInnerHTML={{ __html: detail }}
              />
            )}

            {/* Timer: while recording, just count up (no max shown — recording runs
                freely up to a silent cap of maxSec); review shows playback position. */}
            <div className={cn('shrink-0 flex items-center justify-center gap-2 tabular-nums', promptImage ? 'mt-1' : 'mt-2')}>
              <span
                className={cn(
                  'inline-block h-2.5 w-2.5 rounded-full',
                  recording ? 'bg-red-500' : 'bg-outline-variant',
                  recording && !reduceMotion && 'animate-pulse',
                )}
                aria-hidden="true"
              />
              {review ? (
                <>
                  <span className="text-base font-semibold text-on-surface">{fmt(playPos)}</span>
                  <span className="text-base text-on-surface-variant">/ {fmt(reviewDur)}</span>
                </>
              ) : (
                <span className="text-base font-semibold text-on-surface">{fmt(elapsed)}</span>
              )}
            </div>

            {/* Waveform: live while recording; a play-progress fill while reviewing.
                Compact when a prompt image needs the vertical space. */}
            <div
              className={cn('shrink-0 flex items-center justify-center gap-[3px]', promptImage ? 'h-10 my-2' : 'h-16 mt-3 mb-4')}
              aria-hidden="true"
            >
              {bars.map((amp, i) => {
                const played = review && i < playedBars;
                return (
                  <div
                    key={i}
                    className={cn(
                      'w-[3px] rounded-full transition-[height] duration-75',
                      recordingPhase ? 'bg-primary' : review ? (played ? 'bg-primary' : 'bg-outline-variant') : 'bg-outline-variant',
                    )}
                    style={{
                      height: `${Math.max(4, amp * (promptImage ? 36 : 60))}px`,
                      opacity: recordingPhase ? 0.55 + amp * 0.45 : review ? (played ? 1 : 0.5) : 0.4,
                    }}
                  />
                );
              })}
              {recording && <div className={cn('w-[2px] rounded-full ml-0.5 bg-primary', promptImage ? 'h-8' : 'h-12')} />}
            </div>

            {/* Hidden player for the review phase. */}
            {reviewUrl && (
              <audio
                ref={audioRef}
                src={reviewUrl}
                className="hidden"
                onTimeUpdate={(e) => setPlayPos(e.currentTarget.currentTime)}
                onEnded={() => { setIsPlaying(false); setPlayPos(reviewDur); }}
              />
            )}

            {/* Controls */}
            <div className="shrink-0 flex items-center justify-between">
              <button
                onClick={redo}
                disabled={status === 'starting'}
                className="flex flex-col items-center gap-1 text-on-surface-variant disabled:opacity-40 active:scale-95 transition w-16"
              >
                <RotateCcw size={22} aria-hidden="true" />
                <span className="text-xs font-medium">Redo</span>
              </button>

              {/* Center: Pause/Resume while recording, Play/Pause while reviewing. */}
              <button
                onClick={review ? playPause : togglePause}
                disabled={status === 'starting'}
                aria-label={review ? (isPlaying ? 'Pause playback' : 'Play recording') : recording ? 'Pause' : 'Resume'}
                className="h-16 w-24 rounded-full border-2 border-outline-variant/60 flex items-center justify-center text-primary disabled:opacity-40 hover:bg-surface-container-high active:scale-95 transition"
              >
                {review
                  ? (isPlaying ? <Pause size={26} aria-hidden="true" className="fill-current" /> : <Play size={26} aria-hidden="true" className="fill-current" />)
                  : recording
                    ? <Pause size={26} aria-hidden="true" className="fill-current" />
                    : <Mic size={26} aria-hidden="true" />}
              </button>

              {/* Right: Stop finishes recording → review; Save commits the clip. */}
              {review ? (
                <button
                  onClick={commit}
                  className="flex flex-col items-center gap-1 text-primary font-semibold active:scale-95 transition w-16"
                >
                  <Check size={22} aria-hidden="true" />
                  <span className="text-xs">Save</span>
                </button>
              ) : (
                <button
                  onClick={stop}
                  disabled={status === 'starting'}
                  className="flex flex-col items-center gap-1 text-on-surface font-semibold disabled:opacity-40 active:scale-95 transition w-16"
                >
                  <Square size={20} aria-hidden="true" className="fill-current" />
                  <span className="text-xs">Stop</span>
                </button>
              )}
            </div>

            {/* The recording hint yields its space to the prompt image on phones. */}
            {recordingPhase && !promptImage && (
              <p className="mt-4 shrink-0 text-center text-xs text-on-surface-variant">
                Tap Stop when you are done — you can review your recording before it is saved.
              </p>
            )}
            {review && (
              <p className={cn('shrink-0 text-center text-xs text-on-surface-variant', promptImage ? 'mt-2' : 'mt-4')}>
                You can play your recording back before saving, or Redo to record again.
              </p>
            )}
          </>
        )}
      </motion.div>
    </div>
  );
}
