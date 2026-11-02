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

/** macOS novelty voices and similar: never a sensible default for reading a questionnaire. */
const NOVELTY = /\b(albert|bad news|bahh|bells|boing|bubbles|cellos|good news|jester|organ|superstar|trinoids|whisper|wobble|zarvox|fred|junior|kathy|ralph|deranged|hysterical|pipe organ)\b/i;
const HIGH_QUALITY = /premium|enhanced|natural|neural|online|siri/i;

export interface VoiceInfo { name: string; lang: string; localService?: boolean }

/** Score a voice for `lang`: right language first, then natural-sounding ones, never novelty/robotic. */
export function voiceScore(v: VoiceInfo, lang: string): number {
  const want = lang.toLowerCase().replace('_', '-');
  const have = v.lang.toLowerCase().replace('_', '-');
  let score = 0;
  if (want && have === want) score += 100;
  else if (want && have.split('-')[0] === want.split('-')[0]) score += 60;
  else return -1; // wrong language: only used if nothing else exists
  if (NOVELTY.test(v.name)) return -1;
  if (/espeak/i.test(v.name)) score -= 50;
  if (HIGH_QUALITY.test(v.name)) score += 30;
  if (/google/i.test(v.name)) score += 10;
  if (v.localService) score += 5;
  return score;
}

/** The best available voice for `lang`, or undefined to let the browser decide. */
export function pickVoice<T extends VoiceInfo>(voices: readonly T[], lang: string, offline = false): T | undefined {
  let best: T | undefined;
  let bestScore = 0;
  for (const v of voices) {
    // Offline, a network ("online"/cloud) voice would stay silent, so only on-device voices qualify.
    if (offline && v.localService === false) continue;
    const sc = voiceScore(v, lang);
    if (sc > bestScore) { best = v; bestScore = sc; }
  }
  return best;
}

/** A little slower than default: easier to follow for older listeners and non-native speakers. */
const SPEECH_RATE = 0.9;

/** Speak `text`, replacing anything already being read. Calls `onEnd` when done or interrupted. */
export function speak(text: string, lang: string, onEnd: () => void): void {
  if (!speechAvailable()) { onEnd(); return; }
  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  if (lang) utterance.lang = lang;
  utterance.rate = SPEECH_RATE;
  // getVoices() can be empty until the browser has loaded them; then the default is used.
  const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
  const voice = pickVoice(window.speechSynthesis.getVoices(), lang, offline);
  if (voice) utterance.voice = voice;
  utterance.onend = onEnd;
  utterance.onerror = onEnd;
  window.speechSynthesis.speak(utterance);
}

export function stopSpeaking(): void {
  if (speechAvailable()) window.speechSynthesis.cancel();
}
