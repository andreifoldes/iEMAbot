/**
 * Read-aloud for questions whose researcher supplied `spokenText`.
 *
 * Uses the browser's built-in speech synthesis, so nothing leaves the device. Speech is
 * always started by a participant tap (never automatically) and can be stopped with the
 * same button (WCAG 1.4.2).
 */

export function speechAvailable(): boolean {
  return typeof window !== 'undefined' && 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined';
}

/** Speak `text`, replacing anything already being read. Calls `onEnd` when done or interrupted. */
export function speak(text: string, lang: string, onEnd: () => void): void {
  if (!speechAvailable()) { onEnd(); return; }
  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  if (lang) utterance.lang = lang;
  utterance.onend = onEnd;
  utterance.onerror = onEnd;
  window.speechSynthesis.speak(utterance);
}

export function stopSpeaking(): void {
  if (speechAvailable()) window.speechSynthesis.cancel();
}
