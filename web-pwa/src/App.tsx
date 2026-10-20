/**
 * ESMira participant PWA — a chat-style survey experience modelled on the
 * iEMAbot PWA, served by ESMira's PHP backend. Pull-driven: the participant
 * opens /pwa/?key=ACCESSKEY and the "conversation" plays out locally.
 *
 * Flow: load study -> (consent) -> participant name -> questionnaire list ->
 * chat-style survey (one question per bubble, engine-driven) -> submit to
 * api/datasets.php -> thank-you -> back to list.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import {
  Settings, Info, X, Grid, SkipForward, PlayCircle,
  Sun, Moon, Contrast, Type, Send, ChevronRight, ChevronLeft, CheckCircle, RotateCcw, LogOut,
  FileText, ShieldCheck, Bell, BellRing, BellOff, MessageSquare, UploadCloud, Clock, RefreshCw,
  Download, Bug, ExternalLink, Watch, Lock, Check,
} from 'lucide-react';
import { cn, splitQuestionHtml } from './lib/utils';
import { OfflineSurveyEngine } from './lib/surveyEngine';
import type { PendingSpecify } from './lib/surveyEngine';
import { adaptQuestionnaire, headerText } from './lib/esmiraAdapter';
import {
  buildEsmiraResponses, fetchStudy, installSubmitQueueFlusher, submitQuestionnaire,
  submitCognitiveTrials, serverRootUrl, sendParticipantMessage, loadUploadProtocol,
  flushSubmitQueue, pendingSubmitCount, loadErrorLog, clearErrorLog, installErrorLogger,
  subscribeToPush, fetchNextNotification, logEvent, reportClientInfo, sendWelcomePush, reportPushEvent,
  submitJoined, setLocalOnly,
  startWearableConnect, fetchWearableStatus, disconnectWearable,
  claimPidLock, releasePidLock, getOrCreateDeviceToken,
} from './lib/esmiraApi';
import type { UploadProtocolEntry } from './lib/esmiraApi';
import { ensurePushSubscription, isPushSupported, showLocalNotification } from './lib/push';
import {
  computeAvailability, summarize, ensureEnrollment, loadCompletions, recordCompletion,
  type Availability,
} from './lib/availability';
import { mirrorCompletion, backfillCompletions } from './lib/completionMirror';
import type { EsmiraStudy, EsmiraQuestionnaire, PreloadedQuestion, WearableStatus } from './types';
import { SurveyInputs } from './components/SurveyInputs';
import { AudioRecorder } from './components/AudioRecorder';
import { KeystrokeRecorder } from './components/KeystrokeRecorder';
import {
  cognitiveSummaryValue, cognitiveTrialRows, newCognitiveSession, TRIALS_QN_TITLE, type M2c2Complete,
} from './lib/cognitiveTrials';
import { InstallPrompt } from './components/InstallPrompt';
import { InviteCodeNote } from './components/InviteCodeNote';
import { PhoneOnlyInvite } from './components/PhoneOnlyInvite';
import { isMobileOrTablet, isPhone, isStandalone, usePwaInstall } from './lib/pwaInstall';
import { handoffUrl, isPhoneGated, readPhoneOverride, setPhoneOverride } from './lib/phoneGate';
import { enrollUrl, invitedParticipantId } from './lib/invite';
import { funnelSteps } from './lib/installFunnel';
import { pvtSummary, snapshotMessages } from './lib/snapshot';
import { clearPaused, loadPaused, savePaused } from './lib/pausedCheckin';
import { readingPauseMs } from './lib/pacing';
import { useDialogA11y } from './lib/useDialogA11y';
import { loadA11yPrefs, saveA11yPrefs, TEXT_SIZE_ROOT_PERCENT, type TextSize } from './lib/a11yPrefs';
import { ImageLightbox } from './components/ImageLightbox';
import { WearablesPanel } from './components/WearablesPanel';
import { saveRecording } from './lib/audioUploads';
import { saveKeystrokes } from './lib/keystrokeUploads';
import type { CaptureMode } from './lib/keystrokeCapture';

type Phase = 'loading' | 'error' | 'consent' | 'name' | 'notifications' | 'list' | 'done' | 'survey' | 'tutorial' | 'tutorialOffer' | 'enterKey' | 'pid-conflict' | 'phone-only';

/** localStorage key holding the last study invite code that loaded successfully,
 *  so an installed home-screen launch (which carries no ?key=) reopens it. */
const LAST_KEY_STORE = 'esmira_last_key';
/** PWA package version, injected at build time (see vite.config.ts). */
const APP_VERSION = typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : '0.0.0';
/** Display names for wearable provider slugs (used in the Details panel). */
const WEARABLE_LABEL: Record<string, string> = { withings: 'Withings', googlehealth: 'Google Health' };

interface ChatMsg {
  id: string;
  sender: 'bot' | 'user';
  kind: 'text' | 'section';
  content: string;
  html?: boolean;
  /** Question id this message belongs to (set on settled Q&A pairs). */
  qid?: string;
}


// The text-size setting scales the root font size (see the effect that applies
// TEXT_SIZE_ROOT_PERCENT), so every rem-based size in the app grows with it. Question and
// chat text starts at 18px, comfortable for older readers at the default setting.
const TEXT_SIZE_CLASS = 'text-lg';

const TEXT_SIZE_LABEL: Record<TextSize, string> = {
  normal: 'Normal',
  large: 'Large',
  xlarge: 'X-Large',
  xxlarge: 'XX-Large',
};

function nowTime(): string {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** Human-readable echo of a participant's answer for the user bubble. */
function formatAnswer(q: PreloadedQuestion, value: string): string {
  if (q.type === 'audio') return '🎤 Voice memo';
  if (q.type === 'yesno') {
    if (value === q.yes_value) return q.yes_label || 'Yes';
    if (value === q.no_value) return q.no_label || 'No';
  }
  if (q.type === 'multi_choice') return value ? value.split(',').join(', ') : '(none)';
  if (q.type === 'duration') {
    // Stored value is total minutes (ESMira `duration` format); show it human-readably.
    const total = Number(value);
    if (Number.isFinite(total)) {
      const h = Math.floor(total / 60);
      const m = total % 60;
      return h && m ? `${h} h ${m} min` : h ? `${h} h` : `${m} min`;
    }
  }
  return value;
}

let msgSeq = 0;
const mkId = () => `m${++msgSeq}`;

/**
 * Resolve a stable per-study user id — the identifier sent to the backend,
 * kept distinct from the participant's display name. Priority:
 *   1. a `uid` (alias `user_id` / `userId`) URL param — for personalised invite links
 *   2. an id already stored for this study (constant across visits)
 *   3. a freshly generated random id
 * The resolved id is persisted immediately so it survives reloads.
 */
function resolveUserId(studyId: number, params: URLSearchParams): string {
  const storeKey = `esmira_userid_${studyId}`;
  const fromUrl = (
    params.get('pid') ?? params.get('uid') ?? params.get('user_id') ?? params.get('userId') ?? ''
  ).trim();
  if (fromUrl) {
    localStorage.setItem(storeKey, fromUrl);
    return fromUrl;
  }
  const stored = localStorage.getItem(storeKey);
  if (stored) return stored;
  const generated = generateUserId();
  localStorage.setItem(storeKey, generated);
  return generated;
}

/** A URL/filesystem-safe random user id (the backend maps userId onto a path). */
function generateUserId(): string {
  const c = typeof crypto !== 'undefined' ? crypto : undefined;
  if (c?.randomUUID) return c.randomUUID();
  if (c?.getRandomValues) {
    const b = c.getRandomValues(new Uint8Array(16));
    return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  }
  return `u${Date.now().toString(36)}${Math.floor(Math.random() * 1e9).toString(36)}`;
}


/** localStorage flag marking that this device has already seen the study's tutorial
 *  overview (so it only auto-shows on the participant's first visit). */
const tutorialSeenKey = (studyId: number) => `esmira_tutorial_seen_${studyId}`;

/** Rough size hint for a questionnaire's tutorial card: counts answerable inputs
 *  (display-only info/image items aren't questions). */
function countQuestions(q: EsmiraQuestionnaire): number {
  return q.pages.reduce(
    (n, p) => n + p.inputs.filter((i) => i.responseType !== 'text' && i.responseType !== 'image').length,
    0,
  );
}


export default function App() {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const accessKey = params.get('key') ?? params.get('access_key') ?? '';
  const studyIdParam = params.get('id') ? Number(params.get('id')) : undefined;
  // `?tutorial=1` forces the tutorial (and `?tutorial=0` suppresses it) regardless of
  // the study flag — a test hook and a manual "tutorial link" researchers can share.
  const tutorialParam = params.get('tutorial');

  const [phase, setPhase] = useState<Phase>('loading');
  const [study, setStudy] = useState<EsmiraStudy | null>(null);
  const [serverVersion, setServerVersion] = useState(11);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [keyInput, setKeyInput] = useState(''); // invite-code field on the enterKey screen
  const [pidInput, setPidInput] = useState(''); // optional participant-ID field on the same screen
  // `standalone` is true when the app is running as an installed PWA (home-screen /
  // standalone) rather than a browser tab. The signup funnel (install → open → enter code)
  // keeps the invite-code field disabled in a plain browser tab so participants set up the
  // installed app first — required for reliable background notifications, especially on iOS.
  // `installed` flips when the browser reports the install finished while this tab is still
  // a browser tab. Both come from the shared hook (lib/pwaInstall.ts), which also tracks
  // display-mode changes so the funnel unlocks if the app becomes standalone in-session.
  const { standalone, installed: justInstalled, ios: onIOS } = usePwaInstall();

  const [participant, setParticipant] = useState(''); // display name (username)
  const [userId, setUserId] = useState('');           // stable backend identifier
  const [messages, setMessages] = useState<ChatMsg[]>([]);
  // Paced messages (webPacedMessages): from message `from` on, only `shown` messages are on screen
  // while a batch of bubbles is revealed one by one; null = show every message. See lib/pacing.ts.
  const [pace, setPace] = useState<{ from: string; shown: number } | null>(null);
  // Questionnaire to open as soon as the list phase has rendered (see enterList); null = none.
  const [autoStartId, setAutoStartId] = useState<number | null>(null);
  const paceStart = pace ? messages.findIndex((m) => m.id === pace.from) : -1;
  const visibleCount = paceStart >= 0 ? Math.min(messages.length, paceStart + pace!.shown) : messages.length;
  const pacing = visibleCount < messages.length;
  const [footerValue, setFooterValue] = useState('');

  const engineRef = useRef<OfflineSurveyEngine | null>(null);
  const initedRef = useRef(false);
  const surveyStartRef = useRef(0);
  const activeQRef = useRef<{ id: number; name: string; changeMode: 'previous' | 'any' | 'none' } | null>(null);
  // True while a no-submit tutorial practice run is in progress (gates all network writes).
  const practiceRef = useRef(false);
  const [currentQuestion, setCurrentQuestion] = useState<PreloadedQuestion | null>(null);
  const [progress, setProgress] = useState(0);
  const [submitting, setSubmitting] = useState(false);

  // Accessibility (client-only). Stored on the device; first run follows the OS settings.
  const [initialPrefs] = useState(loadA11yPrefs);
  const [dark, setDark] = useState(initialPrefs.dark);
  const [highContrast, setHighContrast] = useState(initialPrefs.highContrast);
  const [reduceMotion, setReduceMotion] = useState(initialPrefs.reduceMotion);
  const [textSize, setTextSize] = useState<TextSize>(initialPrefs.textSize);
  const textSizeClass = TEXT_SIZE_CLASS;
  useEffect(() => {
    saveA11yPrefs({ dark, highContrast, reduceMotion, textSize });
  }, [dark, highContrast, reduceMotion, textSize]);
  useEffect(() => {
    // Scale from the root so rem-based text, spacing and tap targets all grow together.
    document.documentElement.style.fontSize = `${TEXT_SIZE_ROOT_PERCENT[textSize]}%`;
  }, [textSize]);

  const [gridMenuOpen, setGridMenuOpen] = useState(false);
  const [a11yOpen, setA11yOpen] = useState(false);
  // Settings modal nested navigation: appearance (main) + the app-level features.
  const [settingsView, setSettingsView] = useState<'main' | 'about' | 'notifications' | 'errorReport' | 'wearables'>('main');
  const [updateStatus, setUpdateStatus] = useState<'idle' | 'updating' | 'done' | 'error'>('idle');
  const [notifPerm, setNotifPerm] = useState<NotificationPermission | 'unsupported'>(
    typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
  );
  // Server VAPID public key (present only when a study has web push enabled).
  const [vapidKey, setVapidKey] = useState<string | null>(null);
  // Wearable providers with credentials configured on the server (from studies.php).
  const [wearableProviders, setWearableProviders] = useState<string[]>([]);
  // Connected wearables for this participant (+ last sync), from wearables_status.php.
  const [wearableStatus, setWearableStatus] = useState<WearableStatus[]>([]);
  // Provider whose connect/disconnect is in flight; disables its buttons.
  const [wearableBusy, setWearableBusy] = useState<string | null>(null);
  // Transient banner after returning from a provider OAuth flow ({provider, ok}).
  const [wearableFlash, setWearableFlash] = useState<{ provider: string; ok: boolean } | null>(null);
  // True while the mandatory notifications onboarding step is requesting permission
  // / subscribing / sending the welcome push (disables its buttons).
  const [notifBusy, setNotifBusy] = useState(false);
  // While the mandatory notifications step is showing the "did the welcome arrive?"
  // confirmation (Yes/No), after a welcome push has been dispatched.
  const [awaitingWelcomeConfirm, setAwaitingWelcomeConfirm] = useState(false);
  // Next scheduled reminder (UTC ms) shown in the Details panel; null = none/unknown.
  const [nextNotification, setNextNotification] = useState<number | null>(null);
  // Result of the on-demand "send a test notification" button in Settings → Notifications.
  const [testPushStatus, setTestPushStatus] = useState<'idle' | 'sending' | 'sent' | 'local' | 'error'>('idle');
  const [errorText, setErrorText] = useState('');
  const [errorStatus, setErrorStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const [aboutOpen, setAboutOpen] = useState(false);
  const [aboutView, setAboutView] = useState<'main' | 'description' | 'consent' | 'protocol'>('main');
  // Bumped by the Upload protocol "Refresh" button to force a re-read of the log.
  const [protocolTick, setProtocolTick] = useState(0);
  // Schedule anchor: when the participant enrolled (drives questionnaire availability).
  const [enrolledAt, setEnrolledAt] = useState<number | null>(null);
  // Ticks once a minute so availability windows re-evaluate while the app is open.
  const [nowTick, setNowTick] = useState(0);
  // PID assigned via invite link that is already locked on another device.
  const [pidConflict, setPidConflict] = useState<string | null>(null);
  const [activeQTitle, setActiveQTitle] = useState('');
  const [contactOpen, setContactOpen] = useState(false);
  const [contactText, setContactText] = useState('');
  const [contactStatus, setContactStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const [webview, setWebview] = useState<{ url: string; title: string; qid?: string } | null>(null);
  const webviewRef = useRef<{ url: string; title: string; qid?: string } | null>(null);
  webviewRef.current = webview;
  // Voice-memo recorder overlay (record_audio questions) + the upload identifiers
  // captured in the active questionnaire (released for upload after submission).
  const [recorder, setRecorder] = useState<PreloadedQuestion | null>(null);
  const audioIdsRef = useRef<number[]>([]);
  // record_keystrokes: the question whose writing modal is open, plus per-questionnaire
  // buffers for the log identifiers and the sibling columns (typed transcript, capture mode).
  const [keystroker, setKeystroker] = useState<PreloadedQuestion | null>(null);
  const keystrokeIdsRef = useRef<number[]>([]);
  const keystrokeTextsRef = useRef<Record<string, string>>({});
  const keystrokeModesRef = useRef<Record<string, string>>({});
  // Reaction-time line for the end-of-check-in answers list (webSnapshot), by cognitive question id.
  const pvtLinesRef = useRef<Record<string, string | null>>({});
  // "Other, please specify" free-text modal: set when the participant picks the
  // catch-all option of a list_single that has ESMira's `other` flag enabled.
  const [specify, setSpecify] = useState<PendingSpecify | null>(null);
  // Fullscreen zoomable viewer for images embedded in question rich text
  // (e.g. the picture-description illustration). Opened by delegated click below.
  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);

  const scrollRef = useRef<HTMLElement | null>(null);
  // Always-fresh userId for callbacks that would otherwise capture a stale closure.
  const userIdRef = useRef('');
  userIdRef.current = userId;

  // NB: compute the id OUTSIDE the updater so the updater stays pure (React
  // StrictMode double-invokes updaters, which would otherwise dupe ids/keys).
  const pushBot = useCallback((content: string, html = false, qid?: string): string => {
    const id = mkId();
    setMessages((prev) => [...prev, { id, sender: 'bot', kind: 'text', content, html, qid }]);
    return id;
  }, []);
  const pushUser = useCallback((content: string, qid?: string) => {
    const id = mkId();
    setMessages((prev) => [...prev, { id, sender: 'user', kind: 'text', content, qid }]);
  }, []);
  const pushSection = useCallback((content: string) => {
    const id = mkId();
    setMessages((prev) => [...prev, { id, sender: 'bot', kind: 'section', content }]);
  }, []);

  // ── Tutorial-mode helpers ────────────────────────────────────
  // Record tutorial-engagement events in the participant's event stream
  // (tutorial_offered / accepted / declined / completed) for the data export.
  const logTutorialEvent = useCallback((eventType: string) => {
    if (!study) return;
    const key = accessKey || study.accessKeys?.[0] || '';
    void logEvent({ study, serverVersion, userId: userIdRef.current, accessKey: key, eventType });
  }, [study, serverVersion, accessKey]);

  const pushTutorialIntro = useCallback((s: EsmiraStudy) => {
    pushBot(s.tutorialIntro?.trim()
      || "Here's a quick tour. Below is every questionnaire this study uses — you can try a practice run of any of them (nothing you enter is saved). When you're ready, continue to the study.");
    if (s.postInstallInstructions) pushBot(s.postInstallInstructions);
  }, [pushBot]);

  // Enter the questionnaire list: stamp the enrollment anchor and greet with a
  // schedule-aware message ("Choose a questionnaire" vs. "your study starts on …").
  const enterList = useCallback((s: EsmiraStudy, lead?: string) => {
    const uid = userIdRef.current;
    const now = Date.now();
    const joined = ensureEnrollment(s.id, uid, now);
    setEnrolledAt(joined);
    // Backfill the SW-readable completion mirror so notifications for already-completed
    // questionnaires are suppressed even for participants who joined before it existed.
    void backfillCompletions(s.id, uid, loadCompletions(s.id, uid));
    const visible = s.questionnaires.filter((q) => q.title !== TRIALS_QN_TITLE);
    const completions = loadCompletions(s.id, uid);
    const open = visible.filter((q) => computeAvailability(q, joined, now, completions).state === 'available');
    // A one-questionnaire check-in with an end snapshot (esrs2026) starts straight away, like the
    // Telegram bot, instead of offering a list with a single card.
    const autoStart = !!s.webSnapshot && open.length === 1;
    const { message } = summarize(visible, joined, now, completions);
    const greeting = autoStart ? "Let's go!" : message;
    pushBot(lead ? `${lead} ${greeting}` : greeting);
    if (autoStart) setAutoStartId(open[0].internalId);
    setPhase('list');
  }, [pushBot]);

  // After consent + name: offer a guided tour (yes/no) when the study enables it
  // and it hasn't been done yet; otherwise go straight to the questionnaire list.
  // `?tutorial=1` forces the tour, `?tutorial=0` suppresses the offer.
  const enterStudy = useCallback((s: EsmiraStudy) => {
    if (tutorialParam === '1') {
      pushTutorialIntro(s);
      setPhase('tutorial');
      return;
    }
    if (tutorialParam !== '0' && s.enableTutorialMode && !localStorage.getItem(tutorialSeenKey(s.id))) {
      pushBot(s.tutorialOffer?.trim() || 'Would you like a quick guided tour of the questionnaires before you start?');
      logTutorialEvent('tutorial_offered');
      setPhase('tutorialOffer');
      return;
    }
    enterList(s);
  }, [tutorialParam, pushTutorialIntro, pushBot, enterList, logTutorialEvent]);

  const acceptTutorial = useCallback(() => {
    if (!study) return;
    pushUser('Yes, show me');
    logTutorialEvent('tutorial_accepted');
    pushTutorialIntro(study);
    setPhase('tutorial');
  }, [study, pushTutorialIntro, pushUser, logTutorialEvent]);

  const declineTutorial = useCallback(() => {
    if (!study) return;
    pushUser('No thanks');
    logTutorialEvent('tutorial_declined');
    localStorage.setItem(tutorialSeenKey(study.id), '1');
    enterList(study, 'No problem —');
  }, [study, enterList, pushUser, logTutorialEvent]);

  const finishTutorial = useCallback(() => {
    if (study) localStorage.setItem(tutorialSeenKey(study.id), '1');
    logTutorialEvent('tutorial_completed');
    enterList(study!, 'Great — that\'s the tour done.');
  }, [study, enterList, logTutorialEvent]);

  // Ensure the enrollment anchor exists once the study + user are known (backstop for
  // any path into the list that doesn't go through enterList), and re-evaluate
  // availability windows every minute while the app is open.
  useEffect(() => {
    if (study && userId && enrolledAt == null) setEnrolledAt(ensureEnrollment(study.id, userId, Date.now()));
  }, [study, userId, enrolledAt]);
  // Report install/device telemetry once per load (for the researcher's Push panel).
  const clientInfoSentRef = useRef(false);
  useEffect(() => {
    if (study && userId && !clientInfoSentRef.current) {
      clientInfoSentRef.current = true;
      void reportClientInfo(study.id, userId);
    }
  }, [study, userId]);
  useEffect(() => {
    const id = setInterval(() => setNowTick((t) => t + 1), 60000);
    return () => clearInterval(id);
  }, []);
  // Delegated tap-to-enlarge: any image inside rendered question rich text
  // (question cards, subtext, chat history) opens the zoomable lightbox. One
  // document-level listener covers every dangerouslySetInnerHTML render site.
  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      const target = e.target;
      if (target instanceof HTMLImageElement && target.closest('.esmira-rich')) {
        setLightbox({ src: target.src, alt: target.alt || '' });
      }
    };
    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, []);
  // Availability of each visible questionnaire (recomputed as time/enrollment change).
  const questionnaireAvailability = useMemo(() => {
    const map = new Map<number, Availability>();
    if (!study || enrolledAt == null) return map;
    const completions = loadCompletions(study.id, userId);
    const now = Date.now();
    for (const q of study.questionnaires) {
      if (q.title === TRIALS_QN_TITLE) continue;
      map.set(q.internalId, computeAvailability(q, enrolledAt, now, completions));
    }
    return map;
    // nowTick forces re-evaluation each minute.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [study, userId, enrolledAt, nowTick]);

  // ── Retry queue flusher (re-installs cleanly on remount) ─────
  useEffect(() => installSubmitQueueFlusher(), []);
  // ── Capture client errors for "Send error report" ────────────
  useEffect(() => installErrorLogger(), []);

  // ── Load study once (guarded against StrictMode double-mount) ─
  useEffect(() => {
    if (initedRef.current) return;
    initedRef.current = true;
    // The invite code from the URL, or — only for installed home-screen launches
    // (standalone display-mode, no URL bar) — the last code that worked, so the
    // app reopens the same study after install. In a normal browser tab we never
    // auto-restore the key: a bare /pwa/ visit should show the code-entry screen.
    const storedKey = isStandalone() ? localStorage.getItem(LAST_KEY_STORE) : null;
    const effectiveKey = accessKey || storedKey || '';
    if (!effectiveKey) {
      // No code anywhere: ask the participant for their study invite code.
      setPhase('enterKey');
      return;
    }
    fetchStudy(effectiveKey, studyIdParam)
      .then(async ({ study, serverVersion, vapidPublicKey, wearableProviders }) => {
        // Remember the code only once it actually loaded a study.
        localStorage.setItem(LAST_KEY_STORE, effectiveKey);
        // Before anything else can send: a local-only study (webLocalOnly) uploads nothing.
        setLocalOnly(study.webLocalOnly === true);
        setStudy(study);
        setServerVersion(serverVersion);
        setVapidKey(vapidPublicKey ?? null);
        setWearableProviders(wearableProviders ?? []);
        // Researcher restricted this study to smartphones: show the invite page with a QR
        // code instead. Must come before the user id, PID lock and consent below, so a
        // computer visit leaves nothing behind that the phone would later trip over. A device
        // that already consented here, or where the participant chose "continue here", is
        // exempt, so switching the setting on mid-study locks nobody out.
        const phoneGateExempt = readPhoneOverride(study.id)
          || localStorage.getItem(`esmira_consent_${study.id}`) === '1';
        if (isPhoneGated(study, { phone: isPhone() }, phoneGateExempt)) {
          setPhase('phone-only');
          return;
        }
        // Assign/restore the stable user id (invite-link param > stored > random).
        const uid = resolveUserId(study.id, params);
        setUserId(uid);
        // If the PID was pre-assigned via an invite link, check whether another device
        // has already claimed it. Fail open (network errors let the participant through).
        const invitedPid = (
          params.get('pid') ?? params.get('uid') ?? params.get('user_id') ?? params.get('userId') ?? ''
        ).trim();
        if (invitedPid) {
          const deviceToken = getOrCreateDeviceToken();
          const lockStatus = await claimPidLock(String(study.id), invitedPid, deviceToken);
          if (lockStatus === 'conflict') {
            setPidConflict(invitedPid);
            pushBot(`Welcome to ${study.title}.`);
            pushBot(
              `⚠️ Participant ID **${invitedPid}** is already active on another device for this study. ` +
              `If you've switched devices, you can transfer this ID here. Otherwise, ask your researcher for a different invite link.`,
            );
            setPhase('pid-conflict');
            return;
          }
        }
        const savedName = localStorage.getItem(`esmira_participant_${study.id}`) || '';
        setParticipant(savedName);
        // A description that greets the participant itself replaces the generic welcome line.
        const welcomeId = /^welcome\b/i.test(headerText(study.studyDescription))
          ? null : pushBot(`Welcome to ${study.title}.`);
        // studyDescription is rich text (HTML) in ESMira; render it formatted.
        // informedConsentForm is a plain-text field — keep it plain (line breaks preserved).
        const descriptionId = study.studyDescription ? pushBot(study.studyDescription, true) : null;
        // A first visit to a paced study sees welcome, description and consent appear one by one
        // (set in the same batch as the pushes, so nothing flashes up early).
        const firstId = welcomeId ?? descriptionId;
        if (firstId && study.webPacedMessages && study.informedConsentForm
          && localStorage.getItem(`esmira_consent_${study.id}`) !== '1') setPace({ from: firstId, shown: 1 });
        const consented = localStorage.getItem(`esmira_consent_${study.id}`) === '1';
        // Resolve enrollment for an already-consented participant before entering the study
        // (new participants are handled at consent in onConsent). Adopt the SERVER's anchor
        // (join time, else subscribe time) when it already has one, so a returning device's
        // schedule is NOT shifted by a client-side stamp; only mint + record a fresh join
        // when neither the client nor the server has an anchor. Awaited so every downstream
        // ensureEnrollment (enterList, the backstop effect) reads the resolved value.
        const joinKey = `esmira_joined_${study.id}_${uid}`;
        if (consented && uid && !localStorage.getItem(joinKey)) {
          const subscribed = localStorage.getItem(`esmira_push_subscribed_${study.id}`) === '1';
          const serverAnchor = subscribed && study.webPushEnabled
            ? (await fetchNextNotification(study.id, uid)).joinedAt
            : null;
          if (serverAnchor != null) {
            localStorage.setItem(joinKey, String(serverAnchor));
            ensureEnrollment(study.id, uid, serverAnchor);
          } else {
            const joinedAt = Date.now();
            localStorage.setItem(joinKey, String(joinedAt));
            ensureEnrollment(study.id, uid, joinedAt);
            void submitJoined({ study, serverVersion, accessKey, userId: uid, joinedAt });
          }
        }
        if (study.informedConsentForm && !consented) {
          pushBot(study.informedConsentForm);
          setPhase('consent');
        } else if (!savedName && !study.webSkipName) {
          pushBot('Before we begin — what name would you like to go by? (Just for display; your study id is assigned automatically.)');
          setPhase('name');
        } else {
          if (savedName) pushBot(`Welcome back, ${savedName}!`);
          enterStudyGated(study);
        }
      })
      .catch((e: unknown) => {
        // A stored code that no longer resolves shouldn't trap the participant on
        // every launch — forget it so they can enter a fresh one.
        localStorage.removeItem(LAST_KEY_STORE);
        setLoadError(e instanceof Error ? e.message : 'Could not load the study');
        setPhase('error');
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Auto-scroll to bottom ────────────────────────────────────
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    // The smartphone-only invite page is read from the top: its heading and the top of the
    // QR code would otherwise sit under the fixed header on a short laptop screen.
    if (phase === 'phone-only') { el.scrollTo({ top: 0 }); return; }
    const behavior = reduceMotion ? ('auto' as const) : ('smooth' as const);
    // Choice lists render full-height (no nested scrollbox), so a question card can be
    // taller than the viewport (e.g. the 9-option KSS). Scrolling to the bottom would
    // push the question text off-screen — align the card's top instead so the
    // participant reads the question first, then scrolls down through the options.
    const live = el.querySelector<HTMLElement>('[data-live-question]');
    if (live && live.offsetHeight > el.clientHeight) {
      const delta = live.getBoundingClientRect().top - el.getBoundingClientRect().top;
      el.scrollTo({ top: el.scrollTop + delta - 12, behavior });
    } else {
      el.scrollTo({ top: el.scrollHeight, behavior });
    }
  }, [messages, visibleCount, currentQuestion, phase, reduceMotion]);

  // ── Paced messages: reveal the next bubble once the previous one has had time to be read ──
  useEffect(() => {
    if (!pace) return;
    if (!pacing) { setPace(null); return; }
    const prev = messages[visibleCount - 1];
    const t = setTimeout(() => setPace({ ...pace, shown: pace.shown + 1 }), readingPauseMs(prev.content, prev.html));
    return () => clearTimeout(t);
  }, [pace, pacing, messages, visibleCount]);

  // ── Consent ──────────────────────────────────────────────────
  const onConsent = (agree: boolean) => {
    if (!study) return;
    if (!agree) {
      pushUser('I do not consent');
      pushBot('No problem — you can close this page. Thank you for your time.');
      setPhase('loading'); // no further actions
      return;
    }
    pushUser('I consent');
    localStorage.setItem(`esmira_consent_${study.id}`, '1');
    // Enrollment = consent. Stamp the join time now (it drives "Joined at" and the
    // schedule anchor), anchor availability day-counting to it, and record the "joined"
    // event server-side immediately — ESMira's native apps also join at enrollment
    // rather than deferring to the first questionnaire. Guarded so it runs once.
    const uid = userIdRef.current;
    const joinedKey = `esmira_joined_${study.id}_${uid}`;
    if (uid && !localStorage.getItem(joinedKey)) {
      const joinedAt = Date.now();
      localStorage.setItem(joinedKey, String(joinedAt));
      ensureEnrollment(study.id, uid, joinedAt);
      void submitJoined({ study, serverVersion, accessKey, userId: uid, joinedAt });
    }
    const savedName = localStorage.getItem(`esmira_participant_${study.id}`) || '';
    if (!savedName && !study.webSkipName) {
      pushBot('Thank you. What name would you like to go by?');
      setPhase('name');
    } else {
      enterStudyGated(study);
    }
  };

  // ── Sign out: a COMPLETE reset of this device back to first-visit state ──
  // Wipes all local ESMira data (identity, consent, onboarding flags, enrollment,
  // completions, queues, appearance prefs), unsubscribes push, and drops the IndexedDB
  // completion mirror, every Cache Storage entry, and the service worker itself. This
  // guarantees the next sign-up runs the full onboarding again (notifications gate +
  // welcome push + tutorial) instead of being treated as a returning device.
  const signOut = () => {
    if (!window.confirm("Sign out and reset this device? This clears everything stored here — you'll start sign-up again from the beginning.")) return;
    // Capture identity before wiping so we can free the PID lock server-side.
    const sid = study ? String(study.id) : '';
    const uid = userIdRef.current;
    const deviceToken = localStorage.getItem('esmira_device_token') || '';
    void (async () => {
      if (sid && uid && deviceToken) {
        try { await releasePidLock(sid, uid, deviceToken); } catch { /* best-effort */ }
      }
      // Drop the push subscription (bound to the SW registration) so no stale endpoint lingers.
      try {
        if ('serviceWorker' in navigator) {
          const reg = await navigator.serviceWorker.ready;
          const sub = await reg.pushManager.getSubscription();
          if (sub) await sub.unsubscribe();
        }
      } catch { /* best-effort */ }
      // Clear all web storage.
      try { localStorage.clear(); } catch { /* ignore */ }
      try { sessionStorage.clear(); } catch { /* ignore */ }
      // Delete the completion-mirror IndexedDB.
      try { indexedDB.deleteDatabase('esmira_state'); } catch { /* ignore */ }
      // Delete every Cache Storage entry (app-shell precache, study definition, fonts).
      try {
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map((k) => caches.delete(k)));
        }
      } catch { /* ignore */ }
      // Unregister the service worker so it re-installs clean on next load.
      try {
        if ('serviceWorker' in navigator) {
          const regs = await navigator.serviceWorker.getRegistrations();
          await Promise.all(regs.map((r) => r.unregister()));
        }
      } catch { /* ignore */ }
      // Restart — a fresh navigation with no SW/caches/storage = first-visit state.
      window.location.reload();
    })();
  };

  // ── Contact: send an anonymous message to the research team ──
  const openContact = () => {
    setContactText('');
    setContactStatus('idle');
    setContactOpen(true);
  };
  const sendContact = async () => {
    const text = contactText.trim();
    if (!study || !userId || text.length < 2 || contactStatus === 'sending') return;
    setContactStatus('sending');
    try {
      await sendParticipantMessage({ study, serverVersion, userId, content: text });
      setContactText('');
      setContactStatus('sent');
    } catch {
      setContactStatus('error');
    }
  };

  // ── Settings modal: open on the main (appearance) view, reset on close ──
  const openSettings = () => {
    setSettingsView('main');
    setUpdateStatus('idle');
    setErrorStatus('idle');
    setErrorText('');
    setTestPushStatus('idle');
    if (typeof Notification !== 'undefined') setNotifPerm(Notification.permission);
    setA11yOpen(true);
  };
  const closeSettings = () => {
    setA11yOpen(false);
    setSettingsView('main');
    setUpdateStatus('idle');
  };
  // Open the Settings modal straight onto the wearables panel (from the grid menu tile).
  const openWearables = () => {
    setSettingsView('wearables');
    setA11yOpen(true);
  };

  // Dialog accessibility: Escape-to-close, focus trap, focus restore (see useDialogA11y).
  const settingsDialogRef = useDialogA11y<HTMLDivElement>(a11yOpen, closeSettings);
  const aboutDialogRef = useDialogA11y<HTMLDivElement>(aboutOpen, () => setAboutOpen(false));
  const contactDialogRef = useDialogA11y<HTMLDivElement>(contactOpen, () => setContactOpen(false));
  const gridMenuRef = useDialogA11y<HTMLDivElement>(gridMenuOpen, () => setGridMenuOpen(false));
  const specifyDialogRef = useDialogA11y<HTMLDivElement>(!!specify, () => handleCancelSpecify());
  const webviewDialogRef = useDialogA11y<HTMLDivElement>(!!webview, () => setWebview(null));

  // ── Update studies: re-download the study config and flush the queue ──
  const updateStudies = async () => {
    if (updateStatus === 'updating') return;
    const effectiveKey = accessKey || localStorage.getItem(LAST_KEY_STORE) || '';
    if (!effectiveKey) { setUpdateStatus('error'); return; }
    setUpdateStatus('updating');
    try {
      await flushSubmitQueue();
      const { study: fresh, serverVersion: sv, vapidPublicKey, wearableProviders: wp } = await fetchStudy(effectiveKey, studyIdParam);
      setLocalOnly(fresh.webLocalOnly === true);
      setStudy(fresh);
      setServerVersion(sv);
      setVapidKey(vapidPublicKey ?? null);
      setWearableProviders(wp ?? []);
      setUpdateStatus('done');
    } catch {
      setUpdateStatus('error');
    }
  };

  // ── Web push: register this device's subscription so the server can send
  //    questionnaire reminders while the app is closed. Best-effort & silent. ──
  const subscribePush = useCallback(async (): Promise<boolean> => {
    if (!study || !userId || !vapidKey || !isPushSupported()) return false;
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return false;
    try {
      const subscription = await ensurePushSubscription(vapidKey);
      await subscribeToPush({ study, serverVersion, userId, subscription });
      localStorage.setItem(`esmira_push_subscribed_${study.id}`, '1');
      return true;
    } catch {
      /* unsupported / SW not ready / offline — the next visit retries */
      return false;
    }
  }, [study, vapidKey, serverVersion, userId]);

  // Deliver the onboarding welcome to this device: a real server-sent push (the true test
  // of the reminder pipeline), falling back to a local service-worker notification if the
  // server couldn't send it. Returns whether the *server* push was accepted. Not guarded —
  // callers decide when to send; delivery itself can't be verified server-side, so the
  // participant is asked to confirm receipt (see the notifications-step confirmation).
  const deliverWelcome = useCallback(async (s: EsmiraStudy): Promise<boolean> => {
    const uid = userIdRef.current;
    let succeeded = 0;
    try {
      succeeded = await sendWelcomePush({ study: s, serverVersion, userId: uid });
    } catch {
      succeeded = 0;
    }
    if (succeeded < 1) {
      await showLocalNotification(
        `Welcome to ${s.title}`,
        "Notifications are on — we'll remind you when a questionnaire is ready.",
      );
    }
    return succeeded >= 1;
  }, [serverVersion]);

  // Silent onboarding path (returning device that already granted permission): deliver the
  // welcome at most once per study+user, with no confirmation prompt.
  const maybeDeliverWelcome = useCallback(async (s: EsmiraStudy) => {
    const uid = userIdRef.current;
    const key = `esmira_push_welcomed_${s.id}_${uid}`;
    if (localStorage.getItem(key) === '1') return;
    localStorage.setItem(key, '1');
    await deliverWelcome(s);
  }, [deliverWelcome]);

  // On-demand self-test from Settings → Notifications: refresh the subscription (which
  // self-heals a rotated/mismatched VAPID key) then send a real server push, falling back
  // to a local notification if the server can't deliver. Lets a participant verify that
  // reminders reach their device at any time, not only during onboarding.
  const sendTestPush = useCallback(async (s: EsmiraStudy) => {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
    setTestPushStatus('sending');
    try {
      await subscribePush();
      const serverOk = await deliverWelcome(s);
      setTestPushStatus(serverOk ? 'sent' : 'local');
    } catch {
      setTestPushStatus('error');
    }
  }, [subscribePush, deliverWelcome]);

  // ── Notifications: ask the browser for permission, then subscribe to push ──
  const requestNotifications = async () => {
    if (typeof Notification === 'undefined') return;
    try {
      const perm = await Notification.requestPermission();
      setNotifPerm(perm);
      if (perm === 'granted') await subscribePush();
    } catch {
      /* user dismissed / unsupported */
    }
  };

  // Once a participant has reached the study (list/tutorial), keep their push
  // subscription fresh if notifications are already granted. The not-yet-granted
  // case is handled up front by the mandatory notifications step (see enterStudyGated).
  useEffect(() => {
    if (!study || !vapidKey || !study.webPushEnabled) return;
    if (phase !== 'list' && phase !== 'tutorial') return;
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') void subscribePush();
  }, [phase, study, vapidKey, subscribePush]);

  // Whether the mandatory notifications step is already settled for this study/user:
  // push is off for the study, permission is granted + subscribed, or the participant
  // has taken the "continue without notifications" escape hatch.
  const notificationsSettled = useCallback((s: EsmiraStudy): boolean => {
    if (!s.webPushEnabled) return true;
    if (localStorage.getItem(`esmira_push_optout_${s.id}`) === '1') return true;
    return typeof Notification !== 'undefined'
      && Notification.permission === 'granted'
      && localStorage.getItem(`esmira_push_subscribed_${s.id}`) === '1';
  }, []);

  // Onboarding gate: for push-enabled studies, route through the mandatory
  // notifications step before entering the study. If permission is already granted
  // (e.g. a returning device), subscribe + welcome silently and continue; otherwise
  // show the step. Non-push studies (or already-settled ones) go straight through.
  const enterStudyGated = useCallback((s: EsmiraStudy) => {
    if (notificationsSettled(s)) { enterStudy(s); return; }
    if (isPushSupported() && typeof Notification !== 'undefined' && Notification.permission === 'granted') {
      void (async () => {
        await subscribePush();
        await maybeDeliverWelcome(s);
        enterStudy(s);
      })();
      return;
    }
    pushBot("One last step — please turn on notifications so you don't miss a questionnaire. I'll send a quick welcome to confirm they're working.");
    setPhase('notifications');
  }, [notificationsSettled, enterStudy, subscribePush, maybeDeliverWelcome, pushBot]);

  // Mandatory notifications step — primary action. Request permission (if not decided),
  // then subscribe + deliver the welcome push, then continue into the study. A denied
  // result keeps the participant on the step (the UI shows how to unblock + an escape).
  const enableNotifications = useCallback(async () => {
    if (!study || notifBusy) return;
    setNotifBusy(true);
    try {
      let perm: NotificationPermission = typeof Notification !== 'undefined' ? Notification.permission : 'denied';
      if (perm === 'default' && typeof Notification !== 'undefined') {
        perm = await Notification.requestPermission();
      }
      setNotifPerm(perm === 'default' ? 'default' : perm);
      if (perm !== 'granted') return;

      const ok = await subscribePush();
      if (!ok) {
        // Permission granted but we couldn't register a push subscription (rare on a
        // supported browser). No real push is possible, so skip the confirmation and
        // continue — they'll still get notifications while the app is open.
        pushBot("✅ Notifications are on. You'll get notifications while the app is open; for background reminders, install this app to your home screen.");
        enterStudy(study);
        return;
      }
      // Record that a welcome has been dispatched so the silent path won't re-send later.
      localStorage.setItem(`esmira_push_welcomed_${study.id}_${userIdRef.current}`, '1');
      const serverSucceeded = await deliverWelcome(study);
      pushBot(serverSucceeded
        ? "✅ Notifications are on — I've just sent you a welcome notification."
        : "✅ Notifications are on — I've sent a welcome notification to this device.");
      pushBot('Did it show up? Let me know so I can be sure notifications are reaching you.');
      setAwaitingWelcomeConfirm(true);
    } finally {
      setNotifBusy(false);
    }
  }, [study, notifBusy, subscribePush, deliverWelcome, pushBot, enterStudy]);

  // Notifications-step confirmation — the participant saw the welcome. Record it (so we
  // never re-welcome or re-prompt) and continue into the study.
  const confirmWelcomeReceived = useCallback(() => {
    if (!study) return;
    const uid = userIdRef.current;
    localStorage.setItem(`esmira_push_welcome_confirmed_${study.id}_${uid}`, '1');
    void reportPushEvent({ studyId: study.id, userId: uid, event: 'welcome_confirmed' });
    pushUser('Yes, got it');
    pushBot("Perfect — you're all set. I'll send a notification whenever a questionnaire is ready.");
    setAwaitingWelcomeConfirm(false);
    enterStudy(study);
  }, [study, pushUser, pushBot, enterStudy]);

  // Notifications-step confirmation — the welcome didn't arrive. Flag it for the research
  // team's Push panel, try one local notification so the participant still sees something,
  // then continue (a delivery problem must never block participation).
  const reportWelcomeMissed = useCallback(async () => {
    if (!study) return;
    const uid = userIdRef.current;
    void reportPushEvent({ studyId: study.id, userId: uid, event: 'welcome_missed' });
    pushUser('No, nothing yet');
    const shown = await showLocalNotification(
      `Welcome to ${study.title}`,
      "Notifications are on — we'll remind you when a questionnaire is ready.",
    );
    pushBot(shown
      ? "Thanks — I've shown one now and let the study team know, in case reminders don't reach you. You can carry on with the study."
      : "Thanks — I've let the study team know so they can help make sure reminders reach you. You can still take part in the study.");
    setAwaitingWelcomeConfirm(false);
    enterStudy(study);
  }, [study, pushUser, pushBot, enterStudy]);

  // Mandatory notifications step — escape hatch. Records the opt-out (so the step
  // isn't shown again for this study) and continues into the study.
  const skipNotifications = useCallback(() => {
    if (!study) return;
    localStorage.setItem(`esmira_push_optout_${study.id}`, '1');
    pushUser('Not now');
    pushBot('No problem — you can turn notifications on any time from Settings.');
    enterStudy(study);
  }, [study, pushBot, pushUser, enterStudy]);

  // Refresh the "Next notification" row whenever the Details panel opens.
  useEffect(() => {
    if (!aboutOpen || !study?.webPushEnabled || !userId) { setNextNotification(null); return; }
    let cancelled = false;
    void fetchNextNotification(study.id, userId).then((r) => { if (!cancelled) setNextNotification(r.next); });
    return () => { cancelled = true; };
  }, [aboutOpen, study, userId]);

  // ── Wearables: offered = the study's providers that the server also has creds for ──
  const offeredWearables = useMemo(
    () => (study?.wearablesEnabled ? (study.wearablesProviders ?? []).filter((p) => wearableProviders.includes(p)) : []),
    [study, wearableProviders],
  );

  const refreshWearableStatus = useCallback(async () => {
    if (!study?.wearablesEnabled || !userId) { setWearableStatus([]); return; }
    setWearableStatus(await fetchWearableStatus(study.id, userId));
  }, [study, userId]);

  const connectWearable = async (provider: string) => {
    if (!study || !userId) return;
    setWearableBusy(provider);
    try {
      const url = await startWearableConnect({ study, serverVersion, userId, provider });
      window.location.href = url; // top-level navigation: providers forbid being framed
    } catch {
      setWearableBusy(null);
      setWearableFlash({ provider, ok: false });
    }
  };

  const onDisconnectWearable = async (provider: string) => {
    if (!study) return;
    setWearableBusy(provider);
    try {
      await disconnectWearable({ study, serverVersion, userId, provider });
      await refreshWearableStatus();
    } catch {
      /* ignore — status stays as-is */
    } finally {
      setWearableBusy(null);
    }
  };

  // Refresh the connection list whenever the wearables panel or the Details panel opens.
  useEffect(() => {
    if ((a11yOpen && settingsView === 'wearables') || (aboutOpen && study?.wearablesEnabled))
      void refreshWearableStatus();
  }, [a11yOpen, settingsView, aboutOpen, study, refreshWearableStatus]);

  // Handle the return from a provider OAuth flow (?wearable=<p>&status=connected|error).
  // The callback redirects the whole PWA here, so this runs once on a fresh mount.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const provider = params.get('wearable');
    const status = params.get('status');
    if (!provider || !status) return;
    setWearableFlash({ provider, ok: status === 'connected' });
    params.delete('wearable');
    params.delete('status');
    const qs = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash);
  }, []);

  // After a successful return, once study/user are known, refresh the connection list.
  useEffect(() => {
    if (wearableFlash?.ok && study?.wearablesEnabled && userId) void refreshWearableStatus();
  }, [wearableFlash, study, userId, refreshWearableStatus]);

  // Auto-dismiss the wearables flash banner.
  useEffect(() => {
    if (!wearableFlash) return;
    const t = setTimeout(() => setWearableFlash(null), 5000);
    return () => clearTimeout(t);
  }, [wearableFlash]);

  // ── Send error report: assemble diagnostics + forward to researchers ──
  // No survey answers are included — only the participant's note and technical
  // context that helps diagnose problems (device, versions, recent JS errors).
  const buildErrorReport = (note: string): string => {
    const lines: string[] = ['--- iEMAbot error report ---'];
    const trimmed = note.trim();
    if (trimmed) lines.push('', 'Participant note:', trimmed);
    lines.push(
      '',
      `App version: ${APP_VERSION}`,
      `Server version: ${serverVersion}`,
      `Server: ${serverRootUrl()}`,
      `Study: ${study?.title ?? '—'} (id ${study?.id ?? '—'})`,
      `User id: ${userId || '—'}`,
      `Pending uploads: ${pendingSubmitCount()}`,
      `Online: ${navigator.onLine}`,
      `Language: ${navigator.language}`,
      `User agent: ${navigator.userAgent}`,
    );
    const log = loadErrorLog();
    if (log.length) {
      lines.push('', `Recent errors (${log.length}):`);
      for (const e of log.slice(-15)) {
        lines.push(`  [${new Date(e.time).toISOString()}] ${e.message}`);
      }
    } else {
      lines.push('', 'No client errors recorded.');
    }
    return lines.join('\n');
  };
  const sendErrorReport = async () => {
    if (!study || !userId || errorStatus === 'sending') return;
    setErrorStatus('sending');
    try {
      await sendParticipantMessage({ study, serverVersion, userId, content: buildErrorReport(errorText) });
      clearErrorLog();
      setErrorStatus('sent');
    } catch {
      setErrorStatus('error');
    }
  };

  // ── Footer submit (name entry or text-question answer) ───────
  const onFooterSend = () => {
    const value = footerValue.trim();
    if (!value) return;
    if (phase === 'name' && study) {
      setParticipant(value);
      localStorage.setItem(`esmira_participant_${study.id}`, value);
      pushUser(value);
      setFooterValue('');
      pushBot(`Thanks, ${value}!`);
      enterStudyGated(study);
      return;
    }
    if (phase === 'survey' && currentQuestion?.type === 'text') {
      setFooterValue('');
      handleRespond(currentQuestion.id, value);
    }
  };

  // ── Start a questionnaire ────────────────────────────────────
  const startQuestionnaire = (internalId: number, practice = false, auto = false) => {
    if (!study) return;
    const q = study.questionnaires.find((x) => x.internalId === internalId);
    if (!q) return;
    // Gate real (non-practice) completions by the questionnaire's schedule.
    if (!practice) {
      const av = questionnaireAvailability.get(internalId);
      if (av && av.state !== 'available') {
        pushBot(
          av.state === 'completed' ? "You've already completed that questionnaire."
          : av.state === 'ended' ? 'That questionnaire is no longer available.'
          : `That questionnaire isn't open yet — ${av.reason}.`,
        );
        return;
      }
    }
    practiceRef.current = practice;
    const session = adaptQuestionnaire(study.id, q, Date.now());
    // A check-in parked with "Remind me later" picks up where it stopped (lib/pausedCheckin.ts).
    const parked = practice ? null : loadPaused(study.id, userId);
    const resume = parked && parked.questionnaireId === internalId ? parked : null;
    if (resume) {
      session.answered = resume.responses;
      session.current_question_index = resume.currentIndex;
    }
    const engine = new OfflineSurveyEngine(session);
    if (resume) engine.restoreSpecifyTexts(resume.specifyTexts);
    engineRef.current = engine;
    activeQRef.current = { id: q.internalId, name: q.title, changeMode: q.changeResponseMode ?? 'previous' };
    setActiveQTitle(q.title);
    surveyStartRef.current = Date.now();
    audioIdsRef.current = resume ? [...resume.audioIds] : [];
    keystrokeIdsRef.current = resume ? [...resume.keystrokeIds] : [];
    keystrokeTextsRef.current = resume ? { ...resume.keystrokeTexts } : {};
    keystrokeModesRef.current = resume ? { ...resume.keystrokeModes } : {};
    pvtLinesRef.current = {};
    // A participant's tap shows as their bubble; an automatic start labels the check-in instead.
    if (auto) pushSection(q.title);
    else pushUser(q.title);
    if (resume) {
      clearPaused(study.id, userId);
      // Show what was answered before the pause, then carry on from the saved position.
      for (const done of session.questions) {
        const value = resume.responses[done.id];
        if (value === undefined) continue;
        if (done.type === 'cognitive') {
          pushBot(done.title || 'Cognitive task');
          pushUser('✓ Completed');
        } else {
          pushBot(settledQuestionHtml(done), !!done.is_html);
          pushUser(formatAnswer(done, value));
        }
      }
      pushBot('Welcome back — carrying on where you left off.');
    }
    if (practice) pushSection('Practice run — nothing you enter is saved');
    setPhase('survey');
    const transition = engine.getSectionTransition();
    if (transition?.description) pushSection(transition.description);
    setCurrentQuestion(engine.getCurrentQuestion());
    setProgress(engine.getProgress());
  };

  // Open the questionnaire enterList picked once the list phase has rendered, so the start sees the
  // freshly set user id, enrollment and availability.
  useEffect(() => {
    if (autoStartId == null || phase !== 'list' || pacing) return;
    setAutoStartId(null);
    startQuestionnaire(autoStartId, false, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoStartId, phase, pacing]);

  // ── Respond to current question ──────────────────────────────
  const handleRespond = (questionId: string, value: string) => {
    const engine = engineRef.current;
    if (!engine) return;
    const next = engine.respond(questionId, value);
    // An "other, please specify" choice opens a free-text prompt: keep the
    // question live and defer settling the answer until the detail is confirmed.
    const pending = engine.getPendingSpecify();
    if (pending) {
      setSpecify(pending);
      return;
    }
    const q = engine.session.questions.find((x) => x.id === questionId);
    if (q) {
      // Settle the question into the thread as a bot bubble, then the answer.
      // Tag both with the question id so "Change response" can rewind/remove them.
      // Rich-text prompts (e.g. voice memos) carry HTML in `text`; render them as
      // HTML like the live card, otherwise the bubble shows literal <div>/<br>.
      pushBot(settledQuestionHtml(q), !!q.is_html, questionId);
      pushUser(formatAnswer(q, value), questionId);
    }
    afterAdvance(next);
  };

  // ── "Other, please specify" free-text prompt ─────────────────
  // Confirm: settle the chosen option + its detail into the thread, then advance.
  const handleSubmitSpecify = (text: string) => {
    const engine = engineRef.current;
    if (!engine) return;
    const pending = engine.getPendingSpecify();
    const next = engine.submitSpecify(text);
    setSpecify(null);
    if (pending) {
      const q = engine.session.questions.find((x) => x.id === pending.questionId);
      const detail = text.trim();
      if (q) {
        pushBot(q.text, !!q.is_html, pending.questionId);
        pushUser(detail ? `${pending.baseValue} — ${detail}` : pending.baseValue, pending.questionId);
      }
    }
    afterAdvance(next);
  };

  // Back: discard the tentative choice and re-show the options (nothing settled).
  const handleCancelSpecify = () => {
    const engine = engineRef.current;
    if (!engine) return;
    const reopened = engine.cancelSpecify();
    setSpecify(null);
    setCurrentQuestion(reopened);
    setProgress(engine.getProgress());
  };

  // ── Save a completed voice memo ──────────────────────────────
  // The recording bytes are persisted to IndexedDB immediately (survives reload /
  // offline); the integer identifier becomes this question's response value and is
  // uploaded to file_uploads.php after the questionnaire dataset reaches the server
  // (see audioUploads.ts / submitQuestionnaire). For a practice run we still let the
  // participant experience recording, but nothing is persisted or uploaded.
  const handleSaveRecording = (identifier: number, blob: Blob) => {
    const q = recorder;
    setRecorder(null);
    if (!q) return;
    if (!practiceRef.current && study) {
      void saveRecording({ studyId: study.id, userId, identifier, blob });
      audioIdsRef.current.push(identifier);
    }
    handleRespond(q.id, String(identifier));
  };

  // ── Save a keystroke-dynamics text answer ────────────────────
  // Mirrors handleSaveRecording: the CSV log is persisted to IndexedDB and its identifier
  // becomes this question's response value (uploaded to file_uploads.php after the dataset
  // lands). The typed transcript and capture mode ride along in sibling columns.
  const handleSaveKeystrokes = (identifier: number, blob: Blob, transcript: string, captureMode: CaptureMode) => {
    const q = keystroker;
    setKeystroker(null);
    if (!q) return;
    if (!practiceRef.current && study) {
      void saveKeystrokes({ studyId: study.id, userId, identifier, blob });
      keystrokeIdsRef.current.push(identifier);
    }
    keystrokeTextsRef.current[q.id] = transcript;
    keystrokeModesRef.current[q.id] = captureMode;
    handleRespond(q.id, String(identifier));
  };

  // ── Change the most recent answer (rewind engine, re-ask it) ──
  const onChangeResponse = (qid: string) => {
    const engine = engineRef.current;
    if (!engine) return;
    const rewound = engine.rewindTo(qid);
    if (!rewound) return;
    // Truncate the thread back to that question (removes it and everything after),
    // so re-answering from here — including "any"-mode back-navigation — stays consistent.
    setMessages((prev) => {
      const idx = prev.findIndex((m) => m.qid === qid);
      return idx >= 0 ? prev.slice(0, idx) : prev;
    });
    setCurrentQuestion(rewound);
    setProgress(engine.getProgress());
  };

  // Cognitive tasks are never changeable, regardless of the questionnaire's mode:
  // their result can only be redone, and re-attempts aren't permitted.
  const isCognitiveQid = (qid: string): boolean =>
    engineRef.current?.session.questions.find((x) => x.id === qid)?.type === 'cognitive';

  // A settled question's bubble text: picture tasks (audio/typed with an
  // embedded image) settle as their pitch only, matching the live card — the
  // image stays exclusive to the recorder modal.
  const settledQuestionHtml = (q: PreloadedQuestion): string =>
    (q.type === 'audio' || q.type === 'keystroke_text') && q.is_html
      ? splitQuestionHtml(q.text).intro
      : q.text;

  // Settle a question into the thread when it's skipped or continued past.
  // Cognitive link-out items hold raw HTML in `text` (rendered live as a launch
  // card via title/description, never as text), so settling that text as a plain
  // bubble would dump the markup. Settle them by their clean title instead —
  // matching how a *completed* cognitive task is settled.
  const settleQuestionIntoThread = (q: PreloadedQuestion, qid?: string) => {
    if (q.type === 'cognitive') pushBot(q.title || 'Cognitive task', false, qid);
    else pushBot(settledQuestionHtml(q), !!q.is_html, qid);
  };

  const handleContinueInfo = () => {
    const engine = engineRef.current;
    if (!engine) return;
    // Settle the info/link-out content into the thread (keeps the link clickable).
    if (currentQuestion) settleQuestionIntoThread(currentQuestion);
    const next = engine.skip();
    afterAdvance(next);
  };

  // Leave the running questionnaire without submitting it and show the list again.
  const leaveSurvey = () => {
    engineRef.current = null;
    activeQRef.current = null;
    setCurrentQuestion(null);
    setSpecify(null);
    setProgress(0);
    setPhase('list');
  };

  // Task-card "Remind me later" (webTaskActions): park the answers so far on this device. No push
  // is scheduled, so the message promises a saved place, not a reminder.
  const handleRemindLater = () => {
    const engine = engineRef.current;
    const active = activeQRef.current;
    if (!engine || !study || !active) return;
    pushUser('Remind me later');
    const saved = savePaused(study.id, userId, {
      questionnaireId: active.id,
      currentIndex: engine.getState().currentIndex,
      responses: { ...engine.getResponseMap() },
      specifyTexts: { ...engine.getSpecifyTexts() },
      audioIds: [...audioIdsRef.current],
      keystrokeIds: [...keystrokeIdsRef.current],
      keystrokeTexts: { ...keystrokeTextsRef.current },
      keystrokeModes: { ...keystrokeModesRef.current },
      pausedAt: Date.now(),
    });
    pushBot(saved
      ? "Okay — I've saved your answers so far on this phone. Open the check-in below when you're ready to carry on."
      : "Sorry, I couldn't save your answers on this phone, so this check-in was closed. You can start it again from the list.");
    leaveSurvey();
  };

  // Task-card "Skip check-in" (webTaskActions): drop the whole questionnaire, nothing is submitted.
  const handleSkipCheckin = () => {
    if (!study) return;
    pushUser('Skip check-in');
    clearPaused(study.id, userId);
    pushBot('Okay, I skipped this check-in. None of your answers were submitted.');
    leaveSurvey();
  };

  // Skip a voice memo. If it has a keystroke-text fallback, reveal that fallback so the
  // participant is asked the same prompt to answer by typing; otherwise it's a plain skip.
  const handleAudioSkip = () => {
    const engine = engineRef.current;
    if (!engine) return;
    if (currentQuestion?.skip_fallback_id) engine.activateFallback(currentQuestion.skip_fallback_id);
    if (currentQuestion) settleQuestionIntoThread(currentQuestion);
    const next = engine.skip();
    afterAdvance(next);
  };

  const afterAdvance = (next: PreloadedQuestion | null) => {
    const engine = engineRef.current;
    if (!engine) return;
    setProgress(engine.getProgress());
    if (next) {
      const transition = engine.getSectionTransition();
      if (transition?.description) pushSection(transition.description);
      setCurrentQuestion(next);
    } else {
      setCurrentQuestion(null);
      void finishSurvey();
    }
  };

  const handleSkip = () => {
    const engine = engineRef.current;
    if (!engine || phase !== 'survey' || !currentQuestion) return;
    // Settle the skipped question into the thread, then record an explicit
    // "Skipped" response. Both bubbles are tagged with the question id so the
    // "Change response" affordance can rewind and re-ask it.
    const qid = currentQuestion.id;
    settleQuestionIntoThread(currentQuestion, qid);
    pushUser('Skipped', qid);
    const next = engine.skip();
    afterAdvance(next);
  };

  // ── Finish & submit ──────────────────────────────────────────
  const finishSurvey = async () => {
    const engine = engineRef.current;
    const active = activeQRef.current;
    if (!engine || !study || !active) return;
    // Tutorial practice run: render is identical to a live beep, but nothing is
    // sent and no join/completion state is recorded. Return to the tutorial.
    if (practiceRef.current) {
      pushBot("✅ Practice complete — that's exactly what a real questionnaire looks like. Nothing was saved.");
      engineRef.current = null;
      activeQRef.current = null;
      practiceRef.current = false;
      setPhase('tutorial');
      return;
    }
    setSubmitting(true);
    const responses = buildEsmiraResponses(
      engine.session.questions, engine.getResponseMap(), engine.getSpecifyTexts(),
      keystrokeTextsRef.current, keystrokeModesRef.current,
    );
    const joinedKey = `esmira_joined_${study.id}_${userId}`;
    const newParticipant = !localStorage.getItem(joinedKey);
    const submittedAt = Date.now();
    const ok = await submitQuestionnaire({
      study,
      serverVersion,
      accessKey,
      questionnaireInternalId: active.id,
      questionnaireName: active.name,
      userId,
      responses,
      newParticipant,
      formDuration: Date.now() - surveyStartRef.current,
      pageDurations: '',
      audioIdentifiers: audioIdsRef.current,
      keystrokeIdentifiers: keystrokeIdsRef.current,
    });
    // Join time is normally stamped at consent (see onConsent). This is a fallback for
    // participants who joined before that flow existed (no stored join time): record it on
    // first submission so "Joined at" and the schedule anchor still resolve. Also bump the
    // completed-questionnaire count. Both surface in the About / Study information panel.
    if (newParticipant) localStorage.setItem(joinedKey, String(submittedAt));
    const completedKey = `esmira_completed_${study.id}_${userId}`;
    localStorage.setItem(completedKey, String(Number(localStorage.getItem(completedKey) || '0') + 1));
    // Record against the schedule so completion limits (once / frequency / per-signal) apply.
    const anchor = enrolledAt ?? ensureEnrollment(study.id, userId, submittedAt);
    if (active) {
      const q = study.questionnaires.find((x) => x.internalId === active.id);
      if (q) {
        recordCompletion(study.id, userId, q, anchor, submittedAt);
        // Mirror to IndexedDB so the SW suppresses further prompts for this questionnaire.
        const rec = loadCompletions(study.id, userId)[q.internalId];
        if (rec) void mirrorCompletion(study.id, userId, q.internalId, rec.lastAt, rec.count, rec.occ);
      }
      setNowTick((t) => t + 1);
    }
    setSubmitting(false);
    // Studies that opt in (webSnapshot) get a snapshot, the participant's own answers and a thank-you.
    const messages = snapshotMessages(
      study, engine.session.questions, engine.getResponseMap(),
      Object.values(pvtLinesRef.current).find((line): line is string => !!line) ?? null,
    );
    const closingIds: string[] = [];
    if (study.webLocalOnly) {
      // Nothing was sent, so never claim the responses were recorded.
      if (!messages) closingIds.push(pushBot('✅ Thank you — you are all done. Nothing was sent to the server.'));
    } else {
      closingIds.push(pushBot(ok
        ? '✅ Thank you — your responses have been recorded.'
        : '📥 You appear to be offline. Your responses are saved and will be sent automatically when you reconnect.'));
    }
    messages?.forEach((message) => closingIds.push(pushBot(message)));
    // Paced studies reveal these closing bubbles (and the offer below) one by one.
    if (study.webPacedMessages && closingIds.length > 1) setPace({ from: closingIds[0], shown: 1 });
    clearPaused(study.id, userId);
    engineRef.current = null;
    activeQRef.current = null;
    // A study with an end snapshot (webSnapshot) ends on its thank-you: no offer of another
    // questionnaire and no list. Reopening the app returns to the list.
    if (messages) {
      setPhase('done');
      return;
    }
    // Only offer another questionnaire if one is actually completable right now —
    // otherwise the prompt dead-ends into a locked card (e.g. "Opens tomorrow 08:00"),
    // which reads as a bug rather than as there simply being nothing left to do today.
    const completions = loadCompletions(study.id, userId);
    const now = Date.now();
    const anotherAvailable = study.questionnaires.some((q) =>
      q.title !== TRIALS_QN_TITLE && computeAvailability(q, anchor, now, completions).state === 'available');
    if (anotherAvailable) {
      pushBot('Would you like to complete another questionnaire?');
    }
    setPhase('list');
  };

  // ── Cognitive task result (posted by the m2c2 iframe in embed mode) ──
  // Reassigned each render so the listener always sees current closures.
  const cogCompleteRef = useRef<(qid: string, payload: M2c2Complete) => void>(() => {});
  cogCompleteRef.current = (qid, payload) => {
    const engine = engineRef.current;
    if (!engine) return;
    const q = engine.session.questions.find((x) => x.id === qid);
    const session = newCognitiveSession();
    if (q) {
      pushBot(q.title || 'Cognitive task', false, qid);
      // Participant sees only a neutral confirmation — no performance feedback.
      // The full summary (trials, duration, correctness) is still stored below.
      pushUser('✓ Completed', qid);
    }
    // Store a compact summary (incl. the session id, to join the trial rows) in
    // the cognitive item's own column.
    pvtLinesRef.current[qid] = pvtSummary((payload.data as { trials?: unknown } | undefined)?.trials);
    const next = engine.respond(qid, cognitiveSummaryValue(payload, session));
    setWebview(null);
    afterAdvance(next);

    // Write the tabular trial rows to the hidden "Cognitive Trials" questionnaire.
    const trialsQn = study?.questionnaires.find((x) => x.title === TRIALS_QN_TITLE);
    const active = activeQRef.current;
    const rows = active ? cognitiveTrialRows(payload, active.name, qid, session) : [];
    if (!practiceRef.current && study && trialsQn && active && rows.length) {
      void submitCognitiveTrials({
        study, serverVersion, accessKey, userId,
        trialsInternalId: trialsQn.internalId, trialsName: trialsQn.title, rows,
      });
    }
  };

  useEffect(() => {
    const handler = (e: MessageEvent) => {
      const wv = webviewRef.current;
      if (!wv) return;
      let origin: string;
      try { origin = new URL(wv.url, window.location.href).origin; } catch { return; }
      if (e.origin !== origin) return;
      const d = e.data as M2c2Complete | undefined;
      if (d && d.type === 'm2c2:complete' && wv.qid) cogCompleteRef.current(wv.qid, d);
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, []);

  // ── Derived state ────────────────────────────────────────────
  const headerStatus = useMemo(() => {
    switch (phase) {
      case 'enterKey':
      case 'name':
      case 'phone-only':
      case 'pid-conflict': return 'Sign up';
      case 'consent': return 'Consent';
      case 'notifications': return 'Notifications';
      case 'tutorialOffer':
      case 'tutorial': return 'Tutorial';
      case 'survey': return activeQTitle || 'Survey';
      case 'done': return 'Done';
      case 'list': {
        const DAY_MS = 24 * 60 * 60 * 1000;
        const avValues = [...questionnaireAvailability.values()];
        const visibleQs = study?.questionnaires.filter(q => q.title !== TRIALS_QN_TITLE) ?? [];
        const maxPeriod = visibleQs.length
          ? Math.max(0, ...visibleQs.map(q => q.durationPeriodDays ?? 0))
          : 0;

        if (avValues.length > 0) {
          // All questionnaires ended → study over
          if (avValues.every(av => av.state === 'ended')) {
            if (enrolledAt && maxPeriod > 0) {
              const d = new Date(enrolledAt + maxPeriod * DAY_MS);
              return `Ended ${d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`;
            }
            return 'Study ended';
          }
          // None available yet → study hasn't started
          if (avValues.every(av => av.state === 'upcoming')) {
            const earliest = avValues
              .map(av => av.opensAt)
              .filter((t): t is number => t != null)
              .sort((a, b) => a - b)[0];
            if (earliest) {
              const d = new Date(earliest);
              return `Starts ${d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}`;
            }
          }
        }

        // Study in progress → show which day
        if (enrolledAt) {
          const dayNum = Math.floor((Date.now() - enrolledAt) / DAY_MS) + 1;
          if (maxPeriod > 0) return `Day ${dayNum} of ${maxPeriod}`;
          if (dayNum >= 1) return `Day ${dayNum}`;
        }
        return 'Questionnaires';
      }
      case 'loading': return 'Loading…';
      case 'error': return 'Error';
      default: return 'Survey';
    }
  }, [phase, activeQTitle, questionnaireAvailability, enrolledAt, study]);

  // The most recently answered question shows a "Change response" pill (t-1).
  const lastAnswerId = useMemo(() => {
    let id: string | undefined;
    for (const m of messages) if (m.sender === 'user' && m.qid) id = m.id;
    return id;
  }, [messages]);
  const footerActive = phase === 'name' || (phase === 'survey' && currentQuestion?.type === 'text');
  const footerPlaceholder = phase === 'name' ? 'Enter your name' : 'Type your response…';
  // Quick actions (settings, details, contact, sign out) act on a joined study, so keep them
  // off until an invite code has loaded one — i.e. not during install / invite-code entry.
  const quickActionsEnabled = study !== null && phase !== 'loading' && phase !== 'error' && phase !== 'enterKey';

  // Submit the invite code (and participant ID, if one was typed): reload with ?key=&pid= so
  // the normal mount flow runs exactly as it would from an invite link. This is how an iOS
  // participant enrols after installing — the home-screen app starts with empty storage, so
  // nothing the link carried survives. The code is only remembered once it successfully
  // loads a study (see the load effect).
  const submitAccessKey = () => {
    const code = keyInput.trim();
    if (!code) return;
    window.location.assign(enrollUrl(window.location.pathname, code, pidInput));
  };

  // ── Render ───────────────────────────────────────────────────
  return (
    <div className={cn(
      'app-shell flex flex-col h-dvh bg-surface overflow-hidden transition-colors duration-300',
      dark && 'dark', highContrast && 'high-contrast',
    )}>
      {/* Wearable connect/disconnect result toast (auto-dismisses). */}
      {wearableFlash && (
        <div role="status" aria-live="polite" aria-atomic="true" className="fixed top-3 left-1/2 -translate-x-1/2 z-[120] max-w-[92%]">
          <div className={cn('flex items-center gap-2 px-4 py-2.5 rounded-full shadow-lg text-sm font-semibold text-white',
            wearableFlash.ok ? 'bg-green-600' : 'bg-red-600')}>
            {wearableFlash.ok ? <CheckCircle size={16} aria-hidden="true" /> : <X size={16} aria-hidden="true" />}
            {(WEARABLE_LABEL[wearableFlash.provider] ?? wearableFlash.provider) + (wearableFlash.ok ? ' connected' : ' connection failed')}
          </div>
        </div>
      )}

      {/* Header */}
      <header aria-label={study?.title ?? 'iEMAbot Study'} className="app-header shrink-0 relative z-50 bg-[#075E54] dark:bg-surface-container-lowest text-white dark:text-on-surface shadow-md flex items-center justify-between px-4 py-3">
        <div className="flex items-center gap-3 min-w-0">
          <img src={`${import.meta.env.BASE_URL}esmira-logo.svg`} alt="" aria-hidden="true" className="w-10 h-10 rounded-full shrink-0 object-cover" />
          <div className="flex flex-col min-w-0">
            <h1 className="text-base font-bold leading-none truncate">{study?.title ?? 'iEMAbot Study'}</h1>
            <span className="text-[0.75rem] opacity-80 uppercase tracking-widest font-semibold truncate">
              {headerStatus}
            </span>
          </div>
        </div>
        {phase !== 'phone-only' && <InstallPrompt variant="compact" className="ml-2" />}
      </header>

      {/* Chat area */}
      <main ref={scrollRef} role="log" aria-label="Survey conversation" aria-live="polite" tabIndex={0} className="flex-1 min-h-0 chat-wallpaper overflow-y-auto px-4 py-6 flex flex-col gap-4 no-scrollbar focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
        <div className="flex justify-center my-2">
          <span className="bg-slate-700 dark:bg-surface-container-highest text-white dark:text-on-surface px-4 py-1.5 rounded-full text-[0.75rem] font-bold uppercase tracking-wider shadow-sm">Today</span>
        </div>

        <AnimatePresence initial={false}>
          {messages.slice(0, visibleCount).map((msg) => msg.kind === 'section' ? (
            <motion.div key={msg.id}
              initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
              transition={{ duration: reduceMotion ? 0 : 0.3 }} className="flex justify-center my-1">
              <span className="bg-secondary-container text-on-secondary-container px-4 py-1.5 rounded-full text-xs font-bold shadow-sm">{msg.content}</span>
            </motion.div>
          ) : (
            <motion.div key={msg.id}
              initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 10, scale: 0.95 }} animate={{ opacity: 1, y: 0, scale: 1 }}
              transition={{ duration: reduceMotion ? 0 : 0.3 }}
              className={cn('flex flex-col max-w-[85%]', msg.sender === 'user' ? 'self-end items-end' : 'self-start items-start')}>
              <div className={cn('px-4 py-3 rounded-2xl message-shadow relative',
                msg.sender === 'user' ? 'bg-primary text-on-primary rounded-tr-none'
                  : 'bg-white dark:bg-surface-container-lowest text-on-surface rounded-tl-none border border-slate-200 dark:border-outline-variant/30')}>
                {msg.html
                  ? <div className={cn('leading-relaxed font-medium esmira-rich', textSizeClass)} dangerouslySetInnerHTML={{ __html: msg.content }} />
                  : <p className={cn('leading-relaxed font-medium whitespace-pre-wrap', textSizeClass)}>{msg.content}</p>}
                <div className="flex justify-end items-center gap-1 mt-1">
                  <span className={cn('text-[0.75rem] font-bold', msg.sender === 'user' ? 'text-on-primary' : 'text-on-surface-variant')}>{nowTime()}</span>
                  {msg.sender === 'user' && <CheckCircle size={12} className="text-on-primary opacity-70" aria-hidden="true" />}
                </div>
              </div>
              {phase === 'survey' && msg.qid
                && !isCognitiveQid(msg.qid)
                && (activeQRef.current?.changeMode ?? 'previous') !== 'none'
                && ((activeQRef.current?.changeMode ?? 'previous') === 'any' || msg.id === lastAnswerId) && (
                <button
                  onClick={() => onChangeResponse(msg.qid!)}
                  className="mt-1 px-3 py-1.5 bg-surface-container-high dark:bg-surface-container-highest text-on-surface-variant rounded-full text-xs font-semibold flex items-center gap-1.5 hover:bg-surface-container-highest transition-colors active:scale-95"
                >
                  <RotateCcw size={12} aria-hidden="true" /> Change response
                </button>
              )}
            </motion.div>
          ))}
        </AnimatePresence>
        {/* Paced messages: typing dots until the next bubble; no exit animation, so they never sit above it */}
        {pacing && (
          <motion.div aria-hidden="true"
            initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
            transition={{ duration: reduceMotion ? 0 : 0.2 }}
            className="self-start px-4 py-4 rounded-2xl rounded-tl-none message-shadow bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 flex gap-1.5">
            {[0, 150, 300].map((delay) => (
              <span key={delay} style={{ animationDelay: `${delay}ms` }}
                className={cn('w-2 h-2 rounded-full bg-on-surface-variant/60', !reduceMotion && 'animate-bounce')} />
            ))}
          </motion.div>
        )}

        {/* Error */}
        {phase === 'error' && (
          <div className="self-center max-w-[85%] flex flex-col items-center gap-3">
            <div role="alert" className="bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl text-sm font-medium border border-red-200 dark:border-red-500/30">
              {loadError}
            </div>
            <button
              onClick={() => { localStorage.removeItem(LAST_KEY_STORE); setLoadError(null); setKeyInput(''); setPhase('enterKey'); }}
              className="px-4 py-2 bg-surface-container-high dark:bg-surface-container-highest text-on-surface font-semibold rounded-full text-sm active:scale-95 transition-all"
            >
              Enter a different code
            </button>
          </div>
        )}

        {/* Invite-code prompt (no study key in the URL or remembered) */}
        {phase === 'enterKey' && (
          <div className="self-center w-full max-w-sm mt-4 flex flex-col gap-4">
            {/* Signup funnel — in a plain browser tab, install first. Hidden once the
                app is running as an installed PWA (standalone), where step 1 is done. */}
            {!standalone && (
              <div className="bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-5 flex flex-col gap-4">
                <div className="flex items-start gap-3">
                  <span className="p-2 rounded-full bg-secondary-container text-on-secondary-container shrink-0"><Download size={20} aria-hidden="true" /></span>
                  <div>
                    <h2 className="text-lg font-bold text-on-surface leading-snug">Install the app to begin</h2>
                    <p className="text-sm text-on-surface-variant leading-relaxed mt-0.5">
                      For reliable reminders, set up the app before entering your invite code.
                    </p>
                  </div>
                </div>
                <ol className="flex flex-col gap-2.5 text-sm">
                  {funnelSteps(justInstalled).map((step) => (
                    <li key={step.n} className={cn('flex items-center gap-2.5', step.state === 'active' ? 'font-semibold text-on-surface' : 'text-on-surface-variant')}>
                      <span className={cn('flex items-center justify-center w-5 h-5 rounded-full text-[0.75rem] font-bold shrink-0',
                        step.state === 'active' ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant')}>
                        {step.state === 'done' ? <Check size={12} aria-label="Done" /> : step.n}
                      </span>
                      {step.label}
                    </li>
                  ))}
                </ol>
                <InstallPrompt variant="card" />
              </div>
            )}

            {/* Step 3 — enter the invite code. Shown only once the app is running as
                the installed PWA (standalone); in a plain browser tab we show just the
                install funnel above so participants set up the app first. */}
            {standalone && (
              <div className="bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-5 flex flex-col gap-3">
                <h2 className="text-lg font-bold text-on-surface">Enter your study invite code</h2>
                <p className="text-sm text-on-surface-variant leading-relaxed">
                  Enter the invite code from your invitation, and your participant ID if you were given one, to begin.
                </p>
                <input
                  type="text"
                  inputMode="text"
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  autoFocus
                  value={keyInput}
                  onChange={(e) => setKeyInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') submitAccessKey(); }}
                  placeholder="Invite code"
                  aria-label="Study invite code"
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-outline-variant bg-surface text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <input
                  type="text"
                  inputMode="text"
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  maxLength={64}
                  value={pidInput}
                  onChange={(e) => setPidInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') submitAccessKey(); }}
                  placeholder="Participant ID (if you were given one)"
                  aria-label="Participant ID, if you were given one"
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-outline-variant bg-surface text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <button
                  onClick={submitAccessKey}
                  disabled={!keyInput.trim()}
                  className="w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-50 disabled:active:scale-100"
                >
                  Continue
                </button>
              </div>
            )}
          </div>
        )}

        {/* Loading */}
        {phase === 'loading' && messages.length === 0 && (
          <div className="self-center text-on-surface-variant text-sm">Loading study…</div>
        )}

        {/* Smartphone-only study opened on a computer/tablet: hand off to the phone via QR code */}
        {phase === 'phone-only' && study && (
          <div className="self-center w-full max-w-sm mt-4 flex flex-col gap-4">
            <PhoneOnlyInvite
              studyTitle={study.title}
              url={handoffUrl(window.location.href, accessKey || localStorage.getItem(LAST_KEY_STORE) || '')}
              code={accessKey || localStorage.getItem(LAST_KEY_STORE) || ''}
              participantId={invitedParticipantId(params)}
              canContinueHere={isMobileOrTablet()}
              onContinueHere={() => { setPhoneOverride(study.id); window.location.reload(); }}
            />
          </div>
        )}

        {/* PID conflict — invite link already claimed on another device */}
        {phase === 'pid-conflict' && study && (
          <div role="group" aria-label="Participant ID already in use" className="self-start w-[85%] flex flex-col gap-3">
            <button
              onClick={async () => {
                const deviceToken = getOrCreateDeviceToken();
                const status = await claimPidLock(String(study.id), pidConflict!, deviceToken, true);
                if (status === 'claimed') {
                  pushUser('Transfer to this device');
                  setPidConflict(null);
                  const savedName = localStorage.getItem(`esmira_participant_${study.id}`) || '';
                  setParticipant(savedName);
                  if (study.studyDescription) pushBot(study.studyDescription, true);
                  const consented = localStorage.getItem(`esmira_consent_${study.id}`) === '1';
                  if (study.informedConsentForm && !consented) {
                    pushBot(study.informedConsentForm);
                    setPhase('consent');
                  } else if (!savedName && !study.webSkipName) {
                    pushBot('Before we begin — what name would you like to go by?');
                    setPhase('name');
                  } else {
                    if (savedName) pushBot(`Welcome back, ${savedName}!`);
                    enterStudyGated(study);
                  }
                }
              }}
              className="w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all"
            >
              Transfer to this device
            </button>
            <button
              onClick={() => {
                localStorage.removeItem(LAST_KEY_STORE);
                setPidConflict(null);
                setKeyInput('');
                setPhase('enterKey');
              }}
              className="w-full bg-surface-container-high text-on-surface font-semibold py-3 rounded-full active:scale-95 transition-all"
            >
              Use a different code
            </button>
          </div>
        )}

        {/* Consent actions */}
        {phase === 'consent' && !pacing && (
          <div className="self-start w-[85%] flex gap-3">
            <button onClick={() => onConsent(true)} className="flex-1 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">I consent</button>
            <button onClick={() => onConsent(false)} className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">I do not consent</button>
          </div>
        )}

        {/* Tutorial offer (yes/no) — shown after consent + name for tutorial-enabled studies */}
        {phase === 'tutorialOffer' && (
          <div className="self-start w-[85%] flex gap-3">
            <button onClick={acceptTutorial} className="flex-1 inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
              <PlayCircle size={18} aria-hidden="true" /> Yes, show me
            </button>
            <button onClick={declineTutorial} className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">No thanks</button>
          </div>
        )}

        {/* Mandatory notifications step (push-enabled studies, after consent + name) */}
        {phase === 'notifications' && study && (() => {
          const supported = isPushSupported() && typeof Notification !== 'undefined';
          const denied = supported && notifPerm === 'denied';
          return (
          <div role="group" aria-label="Turn on notifications" aria-live="polite" className="self-start w-[85%] flex flex-col gap-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-4">
            {awaitingWelcomeConfirm ? (
              // A welcome was dispatched — ask the participant to confirm it actually showed
              // up (server push delivery can't be verified). "No" flags the research team.
              <>
                <div className="flex items-start gap-2">
                  <BellRing size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
                  <div>
                    <p className="font-bold text-on-surface">Did the notification arrive?</p>
                    <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                      I just sent a welcome notification to this device. Tap “Yes, got it” if you saw it — or “No, nothing yet” if nothing showed up, and I'll let the study team know.
                    </p>
                  </div>
                </div>
                <div className="flex gap-3">
                  <button onClick={confirmWelcomeReceived}
                    className="flex-1 inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
                    <Check size={16} aria-hidden="true" /> Yes, got it
                  </button>
                  <button onClick={() => void reportWelcomeMissed()}
                    className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">
                    No, nothing yet
                  </button>
                </div>
              </>
            ) : (
            <>
            <div className="flex items-start gap-2">
              <BellRing size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
              <div>
                <p className="font-bold text-on-surface">Turn on notifications</p>
                <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                  This study sends each questionnaire as a notification when it's due. Turn them on so you don't miss one — we'll send a quick welcome to confirm they work.
                </p>
              </div>
            </div>

            {!supported ? (
              // Push isn't available in this context (typically iOS Safari before the PWA
              // is installed) — guide them to add it to the Home Screen, then reopen.
              <>
                <p className={cn('text-on-surface-variant', textSizeClass)}>
                  To get reminders on this device, add this app to your Home Screen first, then reopen it from there and turn on notifications.
                </p>
                <InstallPrompt variant="card" />
                {/* iOS gives the installed app its own empty storage, so the invite code from
                    this tab won't carry over — show it so they can type it in afterwards. */}
                {onIOS && !standalone && (
                  <InviteCodeNote code={accessKey || localStorage.getItem(LAST_KEY_STORE) || ''} participantId={invitedParticipantId(params)} />
                )}
                <button onClick={skipNotifications}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
                  Continue without notifications
                </button>
              </>
            ) : denied ? (
              // Permission was blocked — can't be re-prompted programmatically; explain how
              // to unblock, offer a re-check, and keep the escape hatch.
              <>
                <p className={cn('text-on-surface-variant', textSizeClass)}>
                  Notifications are currently blocked for this site. Open your browser's settings for this page, allow notifications, then tap Try again.
                </p>
                <button
                  onClick={() => {
                    const perm = typeof Notification !== 'undefined' ? Notification.permission : 'denied';
                    setNotifPerm(perm);
                    if (perm !== 'denied') void enableNotifications();
                  }}
                  className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
                  <RefreshCw size={16} aria-hidden="true" /> Try again
                </button>
                <button onClick={skipNotifications}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
                  Continue without notifications
                </button>
              </>
            ) : (
              <>
                <button onClick={() => void enableNotifications()} disabled={notifBusy}
                  className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 disabled:active:scale-100">
                  <BellRing size={16} aria-hidden="true" /> {notifBusy ? 'Enabling…' : 'Enable notifications'}
                </button>
                <button onClick={skipNotifications} disabled={notifBusy}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors disabled:opacity-60">
                  Continue without notifications
                </button>
              </>
            )}
            </>
            )}
          </div>
          );
        })()}

        {/* Questionnaire list */}
        {phase === 'list' && study && !pacing && autoStartId == null && (() => {
          // Show what the participant can act on now: the currently-available
          // questionnaires, or — when nothing is open yet — every questionnaire that
          // still has a future opening, so e.g. a Morning survey whose window already
          // closed today doesn't silently disappear just because another questionnaire
          // (like a midday check-in) happens to open sooner.
          const withAv = study.questionnaires
            .filter((q) => q.title !== TRIALS_QN_TITLE)
            .map((q) => ({ q, av: questionnaireAvailability.get(q.internalId) }));
          const available = withAv.filter((x) => !x.av || x.av.state === 'available');
          const items = available.length
            ? available
            : withAv.filter((x) => x.av?.opensAt).sort((a, b) => a.av!.opensAt! - b.av!.opensAt!);
          const parked = userId ? loadPaused(study.id, userId) : null;
          return (
          <div className="self-start w-[85%] flex flex-col gap-2">
            {items.map(({ q, av }) => {
              const open = !av || av.state === 'available';
              return open ? (
                <button key={q.internalId} onClick={() => startQuestionnaire(q.internalId)}
                  className="w-full flex items-center justify-between px-4 py-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 hover:bg-surface-container-high rounded-xl transition-colors text-left shadow-sm message-shadow">
                  <span className="min-w-0">
                    <span className={cn('block font-semibold', textSizeClass)}>{q.title}</span>
                    {parked?.questionnaireId === q.internalId && (
                      <span className="block text-xs text-on-surface-variant mt-0.5">Paused — tap to carry on</span>
                    )}
                  </span>
                  <ChevronRight size={18} className="text-outline-variant" aria-hidden="true" />
                </button>
              ) : (
                <button type="button" disabled key={q.internalId}
                  aria-label={`${q.title}${av?.reason ? ` — ${av.reason}` : ''}`}
                  className="w-full flex items-center justify-between gap-3 px-4 py-3 bg-surface-container-high/40 dark:bg-surface-container-highest/40 border border-dashed border-outline-variant/40 rounded-xl text-left opacity-70">
                  <div className="min-w-0">
                    <span className={cn('font-semibold text-on-surface-variant', textSizeClass)}>{q.title}</span>
                    <p className="text-xs text-on-surface-variant mt-0.5 flex items-center gap-1">
                      <Lock size={11} aria-hidden="true" />{av?.reason}
                    </p>
                  </div>
                  <Clock size={16} className="text-outline-variant shrink-0" aria-hidden="true" />
                </button>
              );
            })}
          </div>
          );
        })()}

        {/* Tutorial overview — first-visit orientation with optional practice runs */}
        {phase === 'tutorial' && study && (
          <div className="self-start w-[85%] flex flex-col gap-2">
            {study.questionnaires.filter((q) => q.title !== TRIALS_QN_TITLE).map((q) => {
              const n = countQuestions(q);
              return (
                <div key={q.internalId}
                  className="w-full flex items-center justify-between gap-3 px-4 py-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-xl shadow-sm message-shadow">
                  <div className="min-w-0">
                    <p className={cn('font-semibold', textSizeClass)}>{q.title}</p>
                    <p className="text-xs text-on-surface-variant mt-0.5">{n} {n === 1 ? 'question' : 'questions'}</p>
                  </div>
                  <button onClick={() => startQuestionnaire(q.internalId, true)}
                    aria-label={`Try a practice run of ${q.title}`}
                    className="shrink-0 inline-flex items-center gap-1.5 bg-secondary-container text-on-secondary-container font-semibold text-sm px-3 py-2 rounded-full active:scale-95 hover:brightness-95 transition-all">
                    <PlayCircle size={16} aria-hidden="true" /> Practice
                  </button>
                </div>
              );
            })}
            <button onClick={finishTutorial}
              className="mt-2 w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
              I'm ready — continue
            </button>
            <button onClick={finishTutorial}
              className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
              Skip tutorial
            </button>
          </div>
        )}

        {/* Active survey question */}
        {phase === 'survey' && currentQuestion && (
          <SurveyInputs
            question={currentQuestion}
            progress={progress}
            textSizeClass={textSizeClass}
            reduceMotion={reduceMotion}
            onRespond={handleRespond}
            onContinueInfo={handleContinueInfo}
            onOpenWebview={(url, title) => setWebview({ url, title, qid: currentQuestion?.id })}
            onOpenRecorder={() => setRecorder(currentQuestion)}
            onSkipAudio={handleAudioSkip}
            onOpenKeystroke={() => setKeystroker(currentQuestion)}
            taskActions={study?.webTaskActions && !practiceRef.current
              ? { onRemindLater: handleRemindLater, onSkipCheckin: handleSkipCheckin }
              : undefined}
          />
        )}

        {submitting && <div role="status" aria-live="polite" className="self-center text-on-surface-variant text-sm">Saving…</div>}
      </main>

      {/* Footer. Not on the smartphone-only invite page: its menu actions (contact, error report,
          notifications, wearables) all need a participant id, which that page never creates. */}
      {phase !== 'phone-only' && (
      <footer aria-label="Message input" className="app-footer shrink-0 relative z-50 bg-white/85 dark:bg-surface-container-lowest/85 backdrop-blur-md rounded-t-2xl shadow-[0_-4px_12px_rgba(0,0,0,0.05)] px-4 py-3">
        <div className="flex items-center gap-2">
          {/* Quick-actions grid menu */}
          <div className="relative shrink-0">
            <button
              onClick={() => setGridMenuOpen((o) => !o)}
              disabled={!quickActionsEnabled}
              className="p-3 text-on-surface-variant hover:bg-surface-container-high dark:hover:bg-surface-container-highest rounded-full transition-colors disabled:opacity-40 disabled:hover:bg-transparent disabled:cursor-not-allowed"
              aria-label="Quick actions"
            >
              <Grid size={20} aria-hidden="true" />
            </button>
            <AnimatePresence>
              {gridMenuOpen && (
                <>
                  <div
                    className="fixed inset-0 z-40 cursor-pointer"
                    role="presentation"
                    onClick={() => setGridMenuOpen(false)}
                    onTouchEnd={(e) => { e.preventDefault(); setGridMenuOpen(false); }}
                  />
                  <motion.div
                    ref={gridMenuRef} tabIndex={-1} role="group" aria-label="Quick actions menu"
                    initial={reduceMotion ? { opacity: 1 } : { opacity: 0, scale: 0.95, y: 10 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.95, y: 10 }}
                    transition={{ duration: reduceMotion ? 0 : 0.15 }}
                    className={cn('absolute bottom-full left-0 mb-4 p-4 bg-white dark:bg-surface-container-lowest rounded-2xl shadow-xl z-50 border origin-bottom-left w-64', highContrast ? 'border-2 border-black' : 'border-slate-100 dark:border-outline-variant/30')}
                  >
                    <div className="grid grid-cols-2 gap-3">
                      {[
                        { key: 'settings', display: 'Settings', icon: Settings, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-500/10', onSelect: openSettings },
                        { key: 'about', display: 'Details', icon: Info, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-500/10', onSelect: () => { setAboutView('main'); setAboutOpen(true); } },
                        ...(offeredWearables.length > 0
                          ? [{ key: 'wearables', display: 'Wearables', icon: Watch, color: 'text-teal-500', bg: 'bg-teal-50 dark:bg-teal-500/10', onSelect: openWearables }]
                          : []),
                        { key: 'contact', display: 'Contact', icon: MessageSquare, color: 'text-purple-500', bg: 'bg-purple-50 dark:bg-purple-500/10', onSelect: openContact },
                        ...(phase === 'survey' && currentQuestion
                          ? [{ key: 'skip', display: 'Skip', icon: SkipForward, color: 'text-yellow-500', bg: 'bg-yellow-50 dark:bg-yellow-500/10', onSelect: handleSkip }]
                          : []),
                        { key: 'signout', display: 'Sign out', icon: LogOut, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-500/10', onSelect: signOut },
                      ].map((a) => (
                        <button
                          key={a.key}
                          onClick={() => { setGridMenuOpen(false); a.onSelect(); }}
                          className={cn('flex flex-col items-center justify-center gap-2 p-3 rounded-xl transition-colors', highContrast ? 'border-2 border-black hover:bg-black hover:text-white' : 'hover:bg-surface-container-high dark:hover:bg-surface-container-highest')}
                        >
                          <div className={cn('p-3 rounded-full', highContrast ? 'bg-transparent' : a.bg)}>
                            <a.icon size={24} className={highContrast ? 'text-current' : a.color} aria-hidden="true" />
                          </div>
                          <span className="text-xs font-semibold text-on-surface">{a.display}</span>
                        </button>
                      ))}
                    </div>
                  </motion.div>
                </>
              )}
            </AnimatePresence>
          </div>
          <input
            type="text"
            value={footerValue}
            disabled={!footerActive}
            onChange={(e) => setFooterValue(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && onFooterSend()}
            aria-label="Type a response"
            placeholder={footerActive ? footerPlaceholder : 'Tap an option above to continue'}
            className="flex-1 min-w-0 bg-surface-container-high rounded-full px-4 py-3 text-[0.9375rem] text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:opacity-60"
          />
          <button
            onClick={onFooterSend}
            disabled={!footerActive || !footerValue.trim()}
            className="bg-primary text-on-primary p-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100 shrink-0"
            aria-label="Send"
          >
            <Send size={18} aria-hidden="true" />
          </button>
        </div>
      </footer>
      )}

      {/* Settings modal — appearance + app-level features (error report, notifications, update, about) */}
      <AnimatePresence>
        {a11yOpen && (() => {
          const settingsTitle = settingsView === 'about' ? 'About iEMAbot'
            : settingsView === 'notifications' ? 'Notifications'
            : settingsView === 'errorReport' ? 'Send error report'
            : settingsView === 'wearables' ? 'Connect wearables'
            : 'Settings';
          return (
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: reduceMotion ? 0 : 0.2 }}
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <motion.div ref={settingsDialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="settings-dialog-title" initial={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }}
              className="w-full max-w-md max-h-[85dvh] bg-white dark:bg-surface-container-lowest rounded-2xl shadow-2xl overflow-hidden flex flex-col text-on-surface">
              <div className="flex items-center justify-between gap-2 px-5 py-4 border-b border-outline-variant/30 shrink-0">
                <div className="flex items-center gap-2 min-w-0">
                  {settingsView !== 'main' && (
                    <button onClick={() => setSettingsView('main')} aria-label="Back" className="min-w-11 min-h-11 -ml-2 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><ChevronLeft size={20} aria-hidden="true" /></button>
                  )}
                  <h2 id="settings-dialog-title" className="font-bold text-lg flex items-center gap-2 truncate">
                    {settingsView === 'main' && <Settings size={20} aria-hidden="true" />}{settingsTitle}
                  </h2>
                </div>
                <button onClick={closeSettings} aria-label="Close Settings" className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><X size={20} aria-hidden="true" /></button>
              </div>

              {settingsView === 'main' ? (
                <div className="p-5 flex flex-col gap-5 overflow-y-auto custom-scrollbar">
                  {/* Live preview — reflects theme, contrast and text size */}
                  <div>
                    <div className="text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">Preview</div>
                    <div className="rounded-xl p-4 flex flex-col gap-3 chat-wallpaper border border-outline-variant/30 overflow-hidden bg-surface-container-low">
                      <div className="self-start max-w-[85%]">
                        <div className="px-3 py-2 rounded-2xl message-shadow bg-white dark:bg-surface-container-lowest text-on-surface rounded-tl-none border border-slate-200 dark:border-outline-variant/30">
                          <p className={cn('leading-relaxed font-medium', textSizeClass)}>How does this look?</p>
                        </div>
                      </div>
                      <div className="self-end max-w-[85%]">
                        <div className="px-3 py-2 rounded-2xl message-shadow bg-primary text-on-primary rounded-tr-none">
                          <p className={cn('leading-relaxed font-medium', textSizeClass)}>Looks great!</p>
                        </div>
                      </div>
                    </div>
                  </div>
                  <Toggle icon={<Moon size={18} aria-hidden="true" />} label="Dark mode" on={dark} onClick={() => setDark((v) => !v)} />
                  <Toggle icon={<Contrast size={18} aria-hidden="true" />} label="High contrast" on={highContrast} onClick={() => setHighContrast((v) => !v)} />
                  <Toggle icon={<Sun size={18} aria-hidden="true" />} label="Reduce motion" on={reduceMotion} onClick={() => setReduceMotion((v) => !v)} />
                  <div>
                    <div className="flex items-center gap-2 mb-2"><Type size={18} aria-hidden="true" /><span className="font-semibold text-sm">Text size</span></div>
                    <div role="group" aria-label="Text size" className="grid grid-cols-4 gap-2">
                      {(['normal', 'large', 'xlarge', 'xxlarge'] as TextSize[]).map((s) => (
                        <button key={s} onClick={() => setTextSize(s)} aria-pressed={textSize === s}
                          className={cn('py-2 rounded-full text-xs font-bold transition-colors',
                            textSize === s ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface')}>{TEXT_SIZE_LABEL[s]}</button>
                      ))}
                    </div>
                  </div>
                  {/* App-level actions (mirrors the native ESMira app menu) */}
                  <div>
                    <div className="text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-1">App</div>
                    <div className="flex flex-col">
                      <AboutLinkButton icon={Bug} label="Send error report" onClick={() => { setErrorStatus('idle'); setErrorText(''); setSettingsView('errorReport'); }} />
                      <AboutLinkButton icon={BellRing} label="Notifications not working?" onClick={() => { if (typeof Notification !== 'undefined') setNotifPerm(Notification.permission); setSettingsView('notifications'); }} />
                      {offeredWearables.length > 0 && (
                        <AboutLinkButton icon={Watch} label="Connect wearables" onClick={() => setSettingsView('wearables')} />
                      )}
                      <button onClick={updateStudies} disabled={updateStatus === 'updating'}
                        className="w-full flex items-center gap-3 px-2 py-3 rounded-xl hover:bg-surface-container-high dark:hover:bg-surface-container-highest transition-colors text-left disabled:opacity-70">
                        <span className="p-2 rounded-full bg-surface-container-high dark:bg-surface-container-highest text-on-surface-variant shrink-0"><Download size={18} aria-hidden="true" /></span>
                        <span className="flex-1 font-semibold text-sm text-on-surface">Update studies</span>
                        {updateStatus === 'updating' && <RefreshCw size={16} className="animate-spin text-on-surface-variant shrink-0" aria-hidden="true" />}
                        {updateStatus === 'done' && <span className="text-xs font-semibold text-green-600 dark:text-green-400 shrink-0">Updated</span>}
                        {updateStatus === 'error' && <span className="text-xs font-semibold text-red-600 dark:text-red-400 shrink-0">Failed</span>}
                        {updateStatus === 'idle' && <ChevronRight size={18} className="text-outline-variant shrink-0" aria-hidden="true" />}
                      </button>
                      <AboutLinkButton icon={Info} label="About iEMAbot" onClick={() => setSettingsView('about')} />
                    </div>
                  </div>
                </div>
              ) : settingsView === 'about' ? (
                <AboutEsmiraPanel serverVersion={serverVersion} />
              ) : settingsView === 'notifications' ? (
                <NotificationsPanel perm={notifPerm} onEnable={requestNotifications}
                  onTest={() => { if (study) void sendTestPush(study); }}
                  testStatus={testPushStatus} canTest={!!study && !!vapidKey} />
              ) : settingsView === 'wearables' ? (
                <WearablesPanel
                  providers={offeredWearables}
                  status={wearableStatus}
                  busy={wearableBusy}
                  onConnect={connectWearable}
                  onDisconnect={onDisconnectWearable}
                />
              ) : (
                <ErrorReportPanel
                  canSend={!!study}
                  text={errorText}
                  status={errorStatus}
                  preview={buildErrorReport(errorText)}
                  textSizeClass={textSizeClass}
                  onChange={setErrorText}
                  onSend={sendErrorReport}
                  onReset={() => { setErrorStatus('idle'); setErrorText(''); }}
                  onClose={closeSettings}
                />
              )}
            </motion.div>
          </motion.div>
          );
        })()}
      </AnimatePresence>

      {/* About / Study information modal */}
      <AnimatePresence>
        {aboutOpen && (() => {
          // Read the participant's per-study record fresh each time the panel opens.
          const joinedRaw = study ? localStorage.getItem(`esmira_joined_${study.id}_${userId}`) : null;
          let joinedAt: Date | null = null;
          if (joinedRaw && joinedRaw !== '1') {
            const t = Number(joinedRaw);
            const d = Number.isFinite(t) && t > 0 ? new Date(t) : new Date(joinedRaw);
            if (!Number.isNaN(d.getTime())) joinedAt = d;
          }
          const completedCount = study ? Number(localStorage.getItem(`esmira_completed_${study.id}_${userId}`) || '0') : 0;
          const detailTitle = aboutView === 'description' ? 'Study description'
            : aboutView === 'consent' ? 'Informed consent'
            : 'Upload protocol';
          // studyDescription is rich text (HTML); informedConsentForm is a plain-text
          // field whose line breaks must be preserved (rendered below accordingly).
          const detailContent = aboutView === 'description' ? study?.studyDescription : study?.informedConsentForm;
          const protocolEntries = study && aboutView === 'protocol' ? loadUploadProtocol(study.id, userId) : [];
          return (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: reduceMotion ? 0 : 0.2 }}
              className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
              <motion.div ref={aboutDialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="about-dialog-title" initial={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }}
                className="w-full max-w-md max-h-[85dvh] bg-white dark:bg-surface-container-lowest rounded-2xl shadow-2xl overflow-hidden flex flex-col text-on-surface">
                <div className="flex items-center justify-between gap-2 px-5 py-4 border-b border-outline-variant/30 shrink-0">
                  <div className="flex items-center gap-2 min-w-0">
                    {aboutView !== 'main' && (
                      <button onClick={() => setAboutView('main')} aria-label="Back" className="min-w-11 min-h-11 -ml-2 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><ChevronLeft size={20} aria-hidden="true" /></button>
                    )}
                    <h2 id="about-dialog-title" className="font-bold text-lg truncate">{aboutView === 'main' ? 'Details' : detailTitle}</h2>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    {aboutView === 'protocol' && (
                      <button
                        onClick={() => { void flushSubmitQueue().then(() => setProtocolTick((t) => t + 1)); }}
                        aria-label="Refresh upload protocol"
                        className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"
                      >
                        <RefreshCw size={18} aria-hidden="true" />
                      </button>
                    )}
                    <button onClick={() => setAboutOpen(false)} aria-label="Close" className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"><X size={20} aria-hidden="true" /></button>
                  </div>
                </div>

                {aboutView === 'main' ? (
                  <div className="overflow-y-auto custom-scrollbar">
                    {/* Study identity */}
                    <div className="px-5 pt-4 pb-2">
                      <p className="font-semibold text-on-surface leading-snug">{study?.title}</p>
                    </div>
                    {/* Study information rows */}
                    <div className="px-5 pb-2">
                      <InfoRow label="Username" value={participant || '—'} />
                      <InfoRow label="User id" value={userId || '—'} mono />
                      <InfoRow label="Server url" value={serverRootUrl()} mono />
                      <InfoRow label="Joined at" value={joinedAt ? joinedAt.toLocaleString() : 'Not yet joined'} />
                      <InfoRow label="Completed questionnaires" value={completedCount} />
                      <InfoRow label="Next notification" value={
                        <span className="inline-flex items-center gap-1 text-on-surface-variant">
                          <Bell size={13} aria-hidden="true" />
                          {!study?.webPushEnabled
                            ? 'Off for this study'
                            : nextNotification
                              ? new Date(nextNotification).toLocaleString()
                              : 'Not scheduled'}
                        </span>
                      } />
                      {study?.wearablesEnabled && (
                        <InfoRow label="Wearables" value={
                          <span className="inline-flex items-center gap-1 text-on-surface-variant">
                            <Watch size={13} aria-hidden="true" />
                            {wearableStatus.length > 0
                              ? wearableStatus.map((w) => WEARABLE_LABEL[w.provider] ?? w.provider).join(', ')
                              : 'None connected'}
                          </span>
                        } />
                      )}
                    </div>
                    {/* Detail navigation */}
                    <div className="px-3 pb-4 pt-1 flex flex-col">
                      <AboutLinkButton icon={FileText} label="Study description" onClick={() => setAboutView('description')} />
                      <AboutLinkButton icon={ShieldCheck} label="Informed consent" onClick={() => setAboutView('consent')} />
                      <AboutLinkButton icon={UploadCloud} label="Upload protocol" onClick={() => setAboutView('protocol')} />
                    </div>
                    <div className="px-5 pb-4 pt-1 text-center text-xs text-on-surface-variant border-t border-outline-variant/20">
                      Powered by ESMira · web participant interface
                    </div>
                  </div>
                ) : aboutView === 'protocol' ? (
                  <div className="overflow-y-auto custom-scrollbar px-3 py-2">
                    {protocolEntries.length === 0 ? (
                      <p className="px-2 py-4 text-sm text-on-surface-variant">
                        No uploads yet. Each time you submit a questionnaire, the upload to the research server will be listed here.
                      </p>
                    ) : (
                      <ul key={protocolTick} className="flex flex-col">
                        {protocolEntries.map((e) => <ProtocolRow key={e.id} entry={e} />)}
                      </ul>
                    )}
                    <p className="px-2 pt-3 pb-1 text-xs text-on-surface-variant">
                      A record of the data this device has uploaded to the research server.
                    </p>
                  </div>
                ) : (
                  // tabIndex={0} so keyboard users can scroll this text-only region (WCAG 2.1.1).
                  <div className="p-5 overflow-y-auto custom-scrollbar focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary" tabIndex={0} role="region" aria-label={detailTitle}>
                    {detailContent
                      ? (aboutView === 'description'
                          // Rich text (HTML) — render formatted.
                          ? <div className={cn('leading-relaxed esmira-rich', textSizeClass)} dangerouslySetInnerHTML={{ __html: detailContent }} />
                          // Plain text — preserve line breaks exactly like the chat stream.
                          : <p className={cn('leading-relaxed whitespace-pre-wrap', textSizeClass)}>{detailContent}</p>)
                      : <p className="text-sm text-on-surface-variant">Not provided for this study.</p>}
                  </div>
                )}
              </motion.div>
            </motion.div>
          );
        })()}
      </AnimatePresence>

      {/* Contact researchers modal */}
      <AnimatePresence>
        {contactOpen && (
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: reduceMotion ? 0 : 0.2 }}
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <motion.div ref={contactDialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="contact-dialog-title" initial={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }}
              className="w-full max-w-md max-h-[85dvh] bg-white dark:bg-surface-container-lowest rounded-2xl shadow-2xl overflow-hidden flex flex-col text-on-surface">
              <div className="flex items-center justify-between px-5 py-4 border-b border-outline-variant/30 shrink-0">
                <h2 id="contact-dialog-title" className="font-bold text-lg flex items-center gap-2"><MessageSquare size={20} aria-hidden="true" /> Contact researchers</h2>
                <button onClick={() => setContactOpen(false)} aria-label="Close" className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"><X size={20} aria-hidden="true" /></button>
              </div>
              <div className="p-5 flex flex-col gap-4 overflow-y-auto custom-scrollbar">
                {contactStatus === 'sent' ? (
                  <div className="flex flex-col items-center text-center gap-3 py-4">
                    <span className="p-3 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400"><CheckCircle size={28} aria-hidden="true" /></span>
                    <p className="font-semibold">Message sent</p>
                    <p className="text-sm text-on-surface-variant">Thank you — your message has reached the research team.</p>
                    <div className="flex gap-2 mt-2">
                      <button onClick={() => setContactStatus('idle')} className="px-4 py-2 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all">Send another</button>
                      <button onClick={() => setContactOpen(false)} className="px-4 py-2 rounded-full bg-primary text-on-primary font-semibold text-sm active:scale-95 hover:brightness-110 transition-all">Close</button>
                    </div>
                  </div>
                ) : (
                  <>
                    <p className="text-sm text-on-surface-variant">
                      Send a message to the research team. It's linked to your study id
                      {' '}(<span className="font-mono text-on-surface break-all">{userId || '—'}</span>), so they can see which participant it's from and connect it to your responses. Your name and contact details aren't shared unless you write them here.
                    </p>
                    <textarea
                      value={contactText}
                      onChange={(e) => setContactText(e.target.value)}
                      rows={5}
                      disabled={contactStatus === 'sending'}
                      placeholder="Type your message to the researchers…"
                      aria-label="Message to researchers"
                      className={cn('w-full resize-none rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:opacity-60', textSizeClass)}
                    />
                    {contactStatus === 'error' && (
                      <p className="text-sm text-red-600 dark:text-red-400">Couldn't send your message. Please check your connection and try again.</p>
                    )}
                    <button
                      onClick={sendContact}
                      disabled={contactText.trim().length < 2 || contactStatus === 'sending'}
                      className="w-full flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
                    >
                      {contactStatus === 'sending' ? 'Sending…' : <><Send size={18} aria-hidden="true" /> Send message</>}
                    </button>
                  </>
                )}
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Voice-memo recorder overlay (record_audio questions) */}
      {recorder && (
        <AudioRecorder
          question={recorder}
          reduceMotion={reduceMotion}
          onCancel={() => setRecorder(null)}
          onSave={handleSaveRecording}
        />
      )}

      {/* Keystroke-logging writing overlay (record_keystrokes questions) */}
      {keystroker && (
        <KeystrokeRecorder
          question={keystroker}
          reduceMotion={reduceMotion}
          onCancel={() => setKeystroker(null)}
          onSave={handleSaveKeystrokes}
        />
      )}

      {/* "Other, please specify" free-text modal (list_single with `other`) */}
      <AnimatePresence>
        {specify && (
          <SpecifyModal
            key={specify.questionId}
            pending={specify}
            dialogRef={specifyDialogRef}
            reduceMotion={reduceMotion}
            textSizeClass={textSizeClass}
            onSubmit={handleSubmitSpecify}
            onBack={handleCancelSpecify}
          />
        )}
      </AnimatePresence>

      {/* Zoomable fullscreen viewer for images embedded in question rich text */}
      <AnimatePresence>
        {lightbox && (
          <ImageLightbox
            key="lightbox"
            src={lightbox.src}
            alt={lightbox.alt}
            reduceMotion={reduceMotion}
            onClose={() => setLightbox(null)}
          />
        )}
      </AnimatePresence>

      {/* Full-screen webview overlay (cognitive tasks / external assessments) */}
      <AnimatePresence>
        {webview && (
          <motion.div
            ref={webviewDialogRef}
            tabIndex={-1}
            role="dialog"
            aria-modal="true"
            aria-labelledby="task-dialog-title"
            initial={reduceMotion ? { opacity: 1 } : { y: '100%' }}
            animate={{ y: 0, opacity: 1 }}
            exit={reduceMotion ? { opacity: 0 } : { y: '100%' }}
            transition={reduceMotion ? { duration: 0 } : { type: 'spring', damping: 28, stiffness: 220 }}
            className="fixed inset-0 z-[110] bg-surface flex flex-col"
          >
            <header className="bg-surface-container-high text-on-surface px-4 py-3 flex items-center justify-between border-b border-outline-variant/30 shadow-sm shrink-0">
              <h2 id="task-dialog-title" className="font-bold text-base truncate pr-2">{webview.title}</h2>
              <button
                onClick={() => setWebview(null)}
                className="flex items-center gap-1.5 px-3 py-2 bg-surface-container-highest rounded-full text-sm font-semibold hover:brightness-95 active:scale-95 transition-all shrink-0"
                aria-label="Close"
              >
                <X size={18} aria-hidden="true" /> Close
              </button>
            </header>
            <iframe
              src={webview.url}
              title={webview.title}
              className="flex-1 w-full border-0"
              allow="autoplay; fullscreen; accelerometer; gyroscope"
            />
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

/**
 * Free-text follow-up for an "other, please specify" choice. The chosen option
 * (e.g. "Something else") heads the dialog; the entered text is stored in the
 * question's `name~other` column. "Done" requires non-empty text; "Back" returns
 * to the options so the participant can pick a different one instead.
 */
function SpecifyModal({
  pending, dialogRef, reduceMotion, textSizeClass, onSubmit, onBack,
}: {
  pending: PendingSpecify;
  dialogRef: React.RefObject<HTMLDivElement | null>;
  reduceMotion: boolean;
  textSizeClass: string;
  onSubmit: (text: string) => void;
  onBack: () => void;
}) {
  const [text, setText] = useState('');
  const trimmed = text.trim();
  const submit = () => { if (trimmed) onSubmit(text); };
  return (
    <motion.div
      initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
      transition={{ duration: reduceMotion ? 0 : 0.2 }}
      className="fixed inset-0 z-[105] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
    >
      <motion.div
        ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="specify-dialog-title"
        initial={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }}
        className="w-full max-w-md bg-white dark:bg-surface-container-lowest rounded-2xl shadow-2xl overflow-hidden flex flex-col text-on-surface"
      >
        <div className="p-5 flex flex-col gap-4">
          <div>
            <h2 id="specify-dialog-title" className={cn('font-bold leading-snug', textSizeClass)}>{pending.baseValue}</h2>
            <p className="text-sm text-on-surface-variant mt-1">{pending.prompt}</p>
          </div>
          <input
            type="text"
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } }}
            aria-label={pending.prompt}
            placeholder="Type your answer…"
            className={cn('w-full rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50', textSizeClass)}
          />
          <div className="flex gap-2">
            <button
              onClick={onBack}
              className="flex-1 py-3 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all hover:bg-surface-container-highest"
            >
              Back
            </button>
            <button
              onClick={submit}
              disabled={!trimmed}
              className="flex-1 py-3 rounded-full bg-primary text-on-primary font-bold text-sm active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
            >
              Done
            </button>
          </div>
        </div>
      </motion.div>
    </motion.div>
  );
}

/** A label / value row in the About · Study information panel. */
function InfoRow({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5 border-b border-outline-variant/20 last:border-0">
      <span className="text-sm text-on-surface-variant shrink-0">{label}</span>
      <span className={cn('text-sm font-medium text-on-surface text-right break-all', mono && 'font-mono')}>{value}</span>
    </div>
  );
}

/** Format an upload time as dd/mm/yy HH:MM (matches the native ESMira protocol). */
function formatProtocolTime(t: number): string {
  const d = new Date(t);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${String(d.getFullYear()).slice(-2)} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** One upload in the Upload protocol: survey name, timestamp, and send status. */
function ProtocolRow({ entry }: { entry: UploadProtocolEntry }) {
  const sent = entry.status === 'sent';
  return (
    <li className="flex items-start justify-between gap-3 px-2 py-3 border-b border-outline-variant/20 last:border-0">
      <div className="flex items-start gap-3 min-w-0">
        <span className={cn('p-2 rounded-full shrink-0',
          sent ? 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400'
               : 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400')}>
          {sent ? <CheckCircle size={16} aria-hidden="true" /> : <Clock size={16} aria-hidden="true" />}
        </span>
        <div className="min-w-0">
          <p className="font-semibold text-sm text-on-surface break-words">{entry.label}</p>
          <p className="text-xs text-on-surface-variant">{formatProtocolTime(entry.time)}</p>
        </div>
      </div>
      <span className={cn('text-xs font-semibold shrink-0 mt-1',
        sent ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400')}>
        {sent ? 'Sent' : 'Pending'}
      </span>
    </li>
  );
}

/** A tappable row that opens a sub-page (Study description / Informed consent). */
function AboutLinkButton({ icon: Icon, label, onClick }: { icon: typeof FileText; label: string; onClick: () => void }) {
  return (
    <button onClick={onClick}
      className="w-full flex items-center gap-3 px-2 py-3 rounded-xl hover:bg-surface-container-high dark:hover:bg-surface-container-highest transition-colors text-left">
      <span className="p-2 rounded-full bg-surface-container-high dark:bg-surface-container-highest text-on-surface-variant shrink-0"><Icon size={18} aria-hidden="true" /></span>
      <span className="flex-1 font-semibold text-sm text-on-surface">{label}</span>
      <ChevronRight size={18} className="text-outline-variant shrink-0" aria-hidden="true" />
    </button>
  );
}

/** About ESMira sub-panel: app identity, version, server, and project link. */
function AboutEsmiraPanel({ serverVersion }: { serverVersion: number }) {
  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <img src={`${import.meta.env.BASE_URL}esmira-logo.svg`} alt="" className="w-12 h-12 shrink-0" />
        <div className="min-w-0">
          <p className="font-bold text-lg leading-none">iEMAbot</p>
          <p className="text-xs text-on-surface-variant mt-1">Web participant interface</p>
        </div>
      </div>
      <p className="text-sm text-on-surface-variant leading-relaxed">
        ESMira is a tool for running longitudinal studies (ESM, AA, EMA, …). Studies are created
        through an online interface, and participants can take part using iOS, Android, or a web browser.
      </p>
      <div>
        <InfoRow label="App version" value={APP_VERSION} />
        <InfoRow label="Server version" value={serverVersion} />
        <InfoRow label="Server" value={serverRootUrl()} mono />
      </div>
      <a
        href="https://github.com/KL-Psychological-Methodology/ESMira-web"
        target="_blank"
        rel="noreferrer noopener"
        className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline"
      >
        <ExternalLink size={16} aria-hidden="true" /> ESMira project &amp; source code
      </a>
    </div>
  );
}

/** Notifications troubleshooting sub-panel: current permission + guidance. */
function NotificationsPanel({ perm, onEnable, onTest, testStatus, canTest }: {
  perm: NotificationPermission | 'unsupported';
  onEnable: () => void;
  onTest: () => void;
  testStatus: 'idle' | 'sending' | 'sent' | 'local' | 'error';
  canTest: boolean;
}) {
  const status = perm === 'granted'
    ? { icon: <CheckCircle size={18} aria-hidden="true" />, text: 'Notifications are enabled.', cls: 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400' }
    : perm === 'denied'
      ? { icon: <BellOff size={18} aria-hidden="true" />, text: 'Notifications are blocked for this site.', cls: 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400' }
      : perm === 'unsupported'
        ? { icon: <Bell size={18} aria-hidden="true" />, text: 'This browser does not support notifications.', cls: 'bg-surface-container-high text-on-surface-variant' }
        : { icon: <Bell size={18} aria-hidden="true" />, text: 'Notifications are not enabled yet.', cls: 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400' };
  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <span className={cn('p-2 rounded-full shrink-0', status.cls)}>{status.icon}</span>
        <p className="text-sm font-semibold">{status.text}</p>
      </div>
      {perm === 'default' && (
        <button onClick={onEnable}
          className="self-start inline-flex items-center gap-2 bg-primary text-on-primary font-bold px-4 py-2.5 rounded-full active:scale-95 hover:brightness-110 transition-all">
          <BellRing size={16} aria-hidden="true" /> Enable notifications
        </button>
      )}
      {perm === 'denied' && (
        <p className="text-sm text-on-surface-variant">
          You've blocked notifications. To turn them back on, open your browser's site settings for this
          page, allow notifications, then reload.
        </p>
      )}
      {perm === 'granted' && canTest && (
        <div className="flex flex-col gap-1.5" aria-live="polite">
          <button onClick={onTest} disabled={testStatus === 'sending'}
            className="self-start inline-flex items-center gap-2 bg-primary text-on-primary font-bold px-4 py-2.5 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60">
            <BellRing size={16} aria-hidden="true" /> {testStatus === 'sending' ? 'Sending…' : 'Send a test notification'}
          </button>
          {testStatus === 'sent' && <p className="text-sm text-green-600 dark:text-green-400">✓ Sent — you should see it shortly.</p>}
          {testStatus === 'local' && <p className="text-sm text-on-surface-variant">Shown on this device (couldn't confirm a server push).</p>}
          {testStatus === 'error' && <p className="text-sm text-red-600 dark:text-red-400">Couldn't send a test — please try again.</p>}
        </div>
      )}
      <div>
        <p className="font-semibold text-sm mb-2">If reminders aren't arriving:</p>
        <ul className="list-disc pl-5 text-sm text-on-surface-variant space-y-1.5">
          <li>Install this app (add it to your home screen or Dock) so it can run in the background.</li>
          <li>Allow notifications for this site in your browser settings.</li>
          <li>Check your device's system notification settings for your browser or this app.</li>
          <li>On Android, exclude your browser from battery optimisation so reminders aren't delayed.</li>
          <li>Open the app regularly — browsers limit background activity, so some reminders can be delayed.</li>
        </ul>
      </div>
      <p className="text-xs text-on-surface-variant">
        For the most reliable reminders, the ESMira iOS or Android app is recommended.
      </p>
    </div>
  );
}

/** Send error report sub-panel: optional note + a transparent diagnostics preview. */
function ErrorReportPanel({ canSend, text, status, preview, textSizeClass, onChange, onSend, onReset, onClose }: {
  canSend: boolean;
  text: string;
  status: 'idle' | 'sending' | 'sent' | 'error';
  preview: string;
  textSizeClass: string;
  onChange: (v: string) => void;
  onSend: () => void;
  onReset: () => void;
  onClose: () => void;
}) {
  if (status === 'sent') {
    return (
      <div className="p-5 flex flex-col items-center text-center gap-3 py-8 overflow-y-auto custom-scrollbar">
        <span className="p-3 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400"><CheckCircle size={28} aria-hidden="true" /></span>
        <p className="font-semibold">Report sent</p>
        <p className="text-sm text-on-surface-variant">Thank you — your error report has reached the research team.</p>
        <div className="flex gap-2 mt-2">
          <button onClick={onReset} className="px-4 py-2 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all">Send another</button>
          <button onClick={onClose} className="px-4 py-2 rounded-full bg-primary text-on-primary font-semibold text-sm active:scale-95 hover:brightness-110 transition-all">Close</button>
        </div>
      </div>
    );
  }
  return (
    <div className="p-5 flex flex-col gap-4 overflow-y-auto custom-scrollbar">
      <p className="text-sm text-on-surface-variant">
        Something not working? Send a report to the research team. We include basic technical details
        about your device and any recent app errors to help diagnose the problem — your survey answers
        are never included.
      </p>
      <textarea
        value={text}
        onChange={(e) => onChange(e.target.value)}
        rows={4}
        disabled={status === 'sending'}
        placeholder="Describe what went wrong (optional)…"
        aria-label="Describe the problem"
        className={cn('w-full resize-none rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:opacity-60', textSizeClass)}
      />
      <details className="text-sm">
        <summary className="cursor-pointer font-semibold text-on-surface-variant select-none">What's included in the report</summary>
        <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-container-high p-3 text-xs text-on-surface-variant font-mono">{preview}</pre>
      </details>
      {!canSend && <p className="text-sm text-amber-600 dark:text-amber-400">No study is loaded, so the report can't be sent right now.</p>}
      {status === 'error' && <p className="text-sm text-red-600 dark:text-red-400">Couldn't send your report. Please check your connection and try again.</p>}
      <button
        onClick={onSend}
        disabled={!canSend || status === 'sending'}
        className="w-full flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
      >
        {status === 'sending' ? 'Sending…' : <><Bug size={18} aria-hidden="true" /> Send report</>}
      </button>
    </div>
  );
}

function Toggle({ icon, label, on, onClick }: { icon: React.ReactNode; label: string; on: boolean; onClick: () => void }) {
  return (
    <button onClick={onClick} role="switch" aria-checked={on} aria-label={label} className="flex items-center justify-between w-full">
      <span className="flex items-center gap-2 font-semibold text-sm">{icon}{label}</span>
      <span className={cn('w-11 h-6 rounded-full transition-colors relative', on ? 'bg-primary' : 'bg-surface-container-highest')}>
        <span className={cn('absolute top-0.5 w-5 h-5 rounded-full bg-white transition-all', on ? 'left-[22px]' : 'left-0.5')} />
      </span>
    </button>
  );
}
