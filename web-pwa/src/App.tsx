/**
 * ESMira participant PWA — a chat-style survey experience modelled on the
 * iEMAbot PWA, served by ESMira's PHP backend. Pull-driven: the participant
 * opens /pwa/?key=ACCESSKEY and the "conversation" plays out locally.
 *
 * Flow: load study -> (consent) -> questionnaire list ->
 * chat-style survey (one question per bubble, engine-driven) -> submit to
 * api/datasets.php -> thank-you -> back to list.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import {
  Settings, Info, X, Grid, SkipForward, PlayCircle,
  Sun, Moon, Contrast, Type, Send, ChevronRight, ChevronLeft, CheckCircle, RotateCcw, LogOut,
  FileText, ShieldCheck, Bell, BellRing, BellOff, MessageSquare, UploadCloud, Clock, RefreshCw,
  Download, Bug, ExternalLink, Watch, Lock, Check, Mic, Activity, XCircle,
} from 'lucide-react';
import { cn, splitQuestionHtml } from './lib/utils';
import { sanitizeHtml } from './lib/sanitizeHtml';
import { OfflineSurveyEngine } from './lib/surveyEngine';
import type { PendingSpecify } from './lib/surveyEngine';
import { adaptQuestionnaire, headerText } from './lib/esmiraAdapter';
import {
  buildEsmiraResponses, fetchStudy, installSubmitQueueFlusher, submitQuestionnaire,
  submitCognitiveTrials, serverRootUrl, sendParticipantMessage, loadUploadProtocol,
  flushSubmitQueue, pendingSubmitCount, unsentAnswersNotSaved, loadErrorLog, clearErrorLog, installErrorLogger,
  subscribeToPush, unsubscribeFromPush, submitQuit, fetchNextNotification, fetchScheduleInputs, logEvent, reportClientInfo, sendWelcomePush, reportPushEvent,
  setNotificationPreference,
  submitJoined, setLocalOnly,
  startWearableConnect, fetchWearableStatus, disconnectWearable,
  claimPidLock, releasePidLock, getOrCreateDeviceToken,
} from './lib/esmiraApi';
import type { UploadProtocolEntry } from './lib/esmiraApi';
import { closeExpiredNotifications, ensurePushSubscription, isPushSupported, showLocalNotification } from './lib/push';
import { summarize, ensureEnrollment, loadCompletions, recordCompletion } from './lib/availability';
import { mirrorCompletion, backfillCompletions } from './lib/completionMirror';
import { autoStartIds, hasPrompts, saveServerInputs, scheduleConfig, studyAvailability, studyDayLabel, studyPeriod, type Availability } from './lib/schedule';
import { planReminders } from './lib/notificationPlan';
import { tutorialQuestionnaires } from './lib/tutorial';
import { syncReminders, clearReminders, requestReminderPermission, reminderPermission, openNotificationSettings } from './lib/nativeReminders';
import { markNudgeShown, nudgeEnabled, nudgeKind, nudgeShownToday, type NudgeKind, type NudgePermission } from './lib/notificationNudge';
import { micOnboardedKey, micOnboardingWanted, microphonePermission, requestMicrophone } from './lib/permissions';
import { configureServerNudges, cancelServerNudges } from './lib/serverNudges';
import {
  configurePhoneSensing, cancelPhoneSensing, recordPromptEvent, sensingSettings, missingPermissions, chooseUploadNetwork,
  phoneSensingStatus, requestActivityPermission, openUsageAccessSettings, type PhoneSensingStatus,
} from './lib/phoneSensing';
import { withTimeout } from './lib/withdrawal';
import { syncStudyTasks, resolveTaskUrl } from './lib/taskCache';
import { TASK_IFRAME_SANDBOX } from './lib/taskRoute';
import type { EsmiraStudy, EsmiraQuestionnaire, PreloadedQuestion, WearableStatus } from './types';
import { SurveyInputs } from './components/SurveyInputs';
import { AudioRecorder } from './components/AudioRecorder';
import { KeystrokeComposer } from './components/KeystrokeComposer';
import {
  cognitiveSummaryValue, cognitiveTrialRows, newCognitiveSession, TRIALS_QN_TITLE, type M2c2Complete,
} from './lib/cognitiveTrials';
import { InstallPrompt } from './components/InstallPrompt';
import { InviteCodeNote } from './components/InviteCodeNote';
import { PhoneOnlyInvite } from './components/PhoneOnlyInvite';
import { isMobileOrTablet, isPhone, isStandalone, usePwaInstall } from './lib/pwaInstall';
import { isNativeApp } from './lib/platform';
import { checkAppUpdate, notifyAppUpdate, openAppUpdate, type AppUpdate } from './lib/appUpdate';
import { channelEnabled, hasUnsyncedChoice, notificationsActive, participantOff, setParticipantOff } from './lib/notificationSwitch';
import { getApiRoot, getDefaultServer, getStoredServerBase, setServerBase } from './lib/serverRoot';
import { handoffUrl, isPhoneGated, readPhoneOverride, setPhoneOverride } from './lib/phoneGate';
import { enrollUrl, invitedParticipantId, signedOutUrl } from './lib/invite';
import { funnelSteps } from './lib/installFunnel';
import { pvtSummary, snapshotMessages } from './lib/snapshot';
import { canResume, clearPaused, loadPaused, savePaused } from './lib/pausedCheckin';
import { updateReload } from './lib/reloadGate';
import { revealDelayMs, studyPacingWpm, waitsToBeRead } from './lib/pacing';
import { useDialogA11y } from './lib/useDialogA11y';
import { loadA11yPrefs, saveA11yPrefs, TEXT_SIZE_ROOT_PERCENT, type TextSize } from './lib/a11yPrefs';
import { ImageLightbox } from './components/ImageLightbox';
import { WearablesPanel } from './components/WearablesPanel';
import { PhoneSensingPanel } from './components/PhoneSensingPanel';
import { getCurrentLang, t } from './lib/i18n';
import { isMachineTranslated, setStudyLang, useLang } from './lib/i18nStore';
import { clearAudioQueue, saveRecording } from './lib/audioUploads';
import { clearKeystrokeQueue, saveKeystrokes } from './lib/keystrokeUploads';
import type { CaptureMode } from './lib/keystrokeCapture';

type Phase = 'loading' | 'error' | 'consent' | 'notifications' | 'microphone' | 'list' | 'done' | 'survey' | 'tutorial' | 'tutorialOffer' | 'enterKey' | 'pid-conflict' | 'phone-only';

/** Whether the onboarding microphone step is still to come for this study (see continueAfterNotifications). */
function micPending(s: EsmiraStudy): boolean {
  return micOnboardingWanted(s) && localStorage.getItem(micOnboardedKey(s.id)) !== '1';
}

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
  /** Shown without pacing (the conversation a returning participant reopens). */
  instant?: boolean;
}


// The text-size setting scales the root font size (see the effect that applies
// TEXT_SIZE_ROOT_PERCENT), so every rem-based size in the app grows with it. Question and
// chat text starts at 18px, comfortable for older readers at the default setting.
const TEXT_SIZE_CLASS = 'text-lg';

const TEXT_SIZE_KEY: Record<TextSize, string> = {
  normal: 'pwa_text_size_default',
  large: 'pwa_text_size_large',
  xlarge: 'pwa_text_size_xlarge',
  xxlarge: 'pwa_text_size_xxlarge',
};

function nowTime(): string {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** Human-readable echo of a participant's answer for the user bubble. */
function formatAnswer(q: PreloadedQuestion, value: string): string {
  if (q.type === 'audio') return t('pwa_survey_voice_memo');
  if (q.type === 'yesno') {
    if (value === q.yes_value) return q.yes_label || t('pwa_survey_yes');
    if (value === q.no_value) return q.no_label || t('pwa_survey_no');
  }
  if (q.type === 'multi_choice') return value ? value.split(',').join(', ') : t('pwa_survey_none');
  if (q.type === 'duration') {
    // Stored value is total minutes (ESMira `duration` format); show it human-readably.
    const total = Number(value);
    if (Number.isFinite(total)) {
      const h = Math.floor(total / 60);
      const m = total % 60;
      return h && m ? t('pwa_survey_duration_h_min', h, m) : h ? t('pwa_survey_duration_h', h) : t('pwa_survey_duration_min', m);
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
  const lang = useLang(); // re-render the whole app when the language changes
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
  const [serverInput, setServerInput] = useState(() => getStoredServerBase() ?? getDefaultServer()); // native app: which ESMira server
  const [serverError, setServerError] = useState(false);
  const [pidInput, setPidInput] = useState(''); // optional participant-ID field on the same screen
  // `standalone` is true when the app is running as an installed PWA (home-screen /
  // standalone) rather than a browser tab. The signup funnel (install → open → enter code)
  // keeps the invite-code field disabled in a plain browser tab so participants set up the
  // installed app first — required for reliable background notifications, especially on iOS.
  // `installed` flips when the browser reports the install finished while this tab is still
  // a browser tab. Both come from the shared hook (lib/pwaInstall.ts), which also tracks
  // display-mode changes so the funnel unlocks if the app becomes standalone in-session.
  const { standalone, installed: justInstalled, ios: onIOS } = usePwaInstall();

  const [userId, setUserId] = useState('');           // stable backend identifier
  const [messages, setMessages] = useState<ChatMsg[]>([]);
  // Paced messages: the first `shown` messages are on screen; the rest appear one by one, each
  // after the previous bubble's reading time, with typing dots in between. See lib/pacing.ts.
  const [shown, setShown] = useState(0);
  // When the last revealed message appeared (ms), so a late message is not held back again.
  const shownAtRef = useRef(0);
  // While true, new messages are marked `instant`: set while a returning participant's
  // conversation is rebuilt on launch, so reopening the app never replays the pauses.
  const instantRef = useRef(false);
  // Questionnaire to open as soon as the list phase has rendered (see enterList); null = none.
  const [autoStartId, setAutoStartId] = useState<number | null>(null);
  const pacingWpm = studyPacingWpm(study);
  let visibleCount = Math.min(shown, messages.length);
  // Bubbles that never wait (the participant's own, settled answers, ...) show in the same render,
  // so the question card does not blink out while an answer is added.
  while (visibleCount < messages.length && !waitsToBeRead(messages[visibleCount - 1], messages[visibleCount], pacingWpm)) visibleCount++;
  const pacing = visibleCount < messages.length;
  const [footerValue, setFooterValue] = useState('');

  const engineRef = useRef<OfflineSurveyEngine | null>(null);
  const initedRef = useRef(false);
  const surveyStartRef = useRef(0);
  const activeQRef = useRef<{ id: number; name: string; changeMode: 'previous' | 'any' | 'none' } | null>(null);
  const activeOccKeyRef = useRef<string | undefined>(undefined);
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
  useEffect(() => {
    // Theme the page behind the app shell too (body background on overscroll, native form
    // controls via color-scheme) and the browser / status bar, which follows the header.
    const root = document.documentElement.classList;
    root.toggle('dark', dark);
    root.toggle('high-contrast', highContrast);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content',
      highContrast ? (dark ? '#000000' : '#ffffff') : dark ? '#26292b' : '#075E54');
  }, [dark, highContrast]);

  const [gridMenuOpen, setGridMenuOpen] = useState(false);
  const [a11yOpen, setA11yOpen] = useState(false);
  // Settings modal nested navigation: appearance (main) + the app-level features.
  const [settingsView, setSettingsView] = useState<'main' | 'about' | 'notifications' | 'errorReport' | 'wearables' | 'sensing'>('main');
  // 'offline': the server could not be reached, so the study stays as saved on the device.
  const [updateStatus, setUpdateStatus] = useState<'idle' | 'updating' | 'done' | 'offline' | 'error'>('idle');
  // Answers still waiting in the offline queue after the last "Update studies".
  const [updatePending, setUpdatePending] = useState(0);
  const [notifPerm, setNotifPerm] = useState<NotificationPermission | 'unsupported'>(
    typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
  );
  // Server VAPID public key (present only when a study has web push enabled).
  const [vapidKey, setVapidKey] = useState<string | null>(null);
  // Wearable providers with credentials configured on the server (from studies.php).
  const [wearableProviders, setWearableProviders] = useState<string[]>([]);
  const [errorReportsToGithub, setErrorReportsToGithub] = useState(false);
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
  // Microphone onboarding step (studies with voice-recording items): busy while the system
  // prompt is open; status is the outcome of the last attempt ('ask' = not tried yet).
  const [micBusy, setMicBusy] = useState(false);
  const [micStatus, setMicStatus] = useState<'ask' | 'denied' | 'error' | 'unsupported'>('ask');
  // Next scheduled reminder (UTC ms) shown in the Details panel; null = none/unknown.
  const [nextNotification, setNextNotification] = useState<number | null>(null);
  // The participant's own notification switch (Settings → Notifications), per study; see lib/notificationSwitch.
  const [notifOff, setNotifOff] = useState(false);
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
  // Set while the chat's last word on the list is that nothing is open (see the effect after questionnaireAvailability).
  const awaitingOpenRef = useRef(false);
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
  // record_keystrokes: the question being written in the chat row, plus per-questionnaire
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
    const instant = instantRef.current;
    setMessages((prev) => [...prev, { id, sender: 'bot', kind: 'text', content, html, qid, instant }]);
    return id;
  }, []);
  const pushUser = useCallback((content: string, qid?: string) => {
    const id = mkId();
    setMessages((prev) => [...prev, { id, sender: 'user', kind: 'text', content, qid }]);
  }, []);
  const pushSection = useCallback((content: string) => {
    const id = mkId();
    const instant = instantRef.current;
    setMessages((prev) => [...prev, { id, sender: 'bot', kind: 'section', content, instant }]);
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
      || t('pwa_app_tutorial_intro'));
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
    const availability = studyAvailability(s, uid, joined, now, loadCompletions(s.id, uid));
    const open = visible.filter((q) => availability(q).state === 'available');
    // A check-in saved on this device (lib/pausedCheckin.ts) carries on while its window is open; once that has
    // closed the saved answers are dropped. One the app was closed in reopens by itself, before anything else;
    // one parked with "Remind me later" waits on the list.
    let interrupted: EsmiraQuestionnaire | undefined;
    let interruptedAt = 0;
    for (const q of visible) {
      const saved = loadPaused(s.id, uid, q.internalId);
      if (!saved) continue;
      if (!canResume(saved, availability(q))) clearPaused(s.id, uid, q.internalId);
      else if (!saved.remindLater && saved.pausedAt >= interruptedAt) {
        interrupted = q;
        interruptedAt = saved.pausedAt;
      }
    }
    // A check-in the app starts by itself (autoStart, e.g. usual sleep times on day zero) starts straight away
    // while it is open, so it comes before the regular check-ins. So does a one-questionnaire check-in with an end
    // snapshot (webSnapshot), like the Telegram bot, instead of a list with a single card.
    const autoStarting = autoStartIds(s);
    const onboarding = open.find((q) => autoStarting.has(q.internalId));
    const autoStart = interrupted ?? onboarding ?? (s.webSnapshot && open.length === 1 ? open[0] : undefined);
    const { anyAvailable, message } = summarize(visible, now, availability);
    const greeting = interrupted ? t('pwa_list_saved_answers')
      : onboarding ? t('pwa_list_first_setup') : autoStart ? t('pwa_list_lets_go') : message;
    awaitingOpenRef.current = !anyAvailable && !autoStart;
    pushBot(lead ? t('pwa_app_join_sentences', lead, greeting) : greeting);
    if (autoStart) setAutoStartId(autoStart.internalId);
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
      pushBot(s.tutorialOffer?.trim() || t('pwa_app_tutorial_offer'));
      logTutorialEvent('tutorial_offered');
      setPhase('tutorialOffer');
      return;
    }
    enterList(s);
  }, [tutorialParam, pushTutorialIntro, pushBot, enterList, logTutorialEvent]);

  const acceptTutorial = useCallback(() => {
    if (!study) return;
    pushUser(t('pwa_app_tutorial_yes'));
    logTutorialEvent('tutorial_accepted');
    pushTutorialIntro(study);
    setPhase('tutorial');
  }, [study, pushTutorialIntro, pushUser, logTutorialEvent]);

  const declineTutorial = useCallback(() => {
    if (!study) return;
    pushUser(t('pwa_app_tutorial_no'));
    logTutorialEvent('tutorial_declined');
    localStorage.setItem(tutorialSeenKey(study.id), '1');
    enterList(study, t('pwa_app_tutorial_declined'));
  }, [study, enterList, pushUser, logTutorialEvent]);

  const finishTutorial = useCallback(() => {
    if (study) localStorage.setItem(tutorialSeenKey(study.id), '1');
    logTutorialEvent('tutorial_completed');
    enterList(study!, t('pwa_app_tutorial_done'));
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
  // The study's schedule: refresh the server's inputs (the participant's usual sleep times, wearable events) every
  // 5 minutes, on return to the app and after each uploaded questionnaire (the answers can be the usual sleep
  // times). The windows are computed on the device from the cached copy (lib/schedule.ts), so they keep working
  // offline.
  const [planRefreshTick, setPlanRefreshTick] = useState(0);
  useEffect(() => {
    if (!study || !userId || !scheduleConfig(study)) return;
    let cancelled = false;
    const refresh = () => {
      void fetchScheduleInputs(study.id, userId).then((inputs) => {
        if (cancelled || !inputs) return;
        saveServerInputs(study.id, userId, inputs);
        setNowTick((t) => t + 1);
      });
    };
    refresh();
    const id = setInterval(refresh, 5 * 60000);
    const onVisible = () => { if (document.visibilityState === 'visible') refresh(); };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('online', refresh);
    return () => {
      cancelled = true;
      clearInterval(id);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('online', refresh);
    };
  }, [study, userId, planRefreshTick]);
  // Browser: take down web-push reminders whose check-in has closed (a web notification cannot expire by itself;
  // the service worker also does this on every push). The Android app's expire on their own (setTimeoutAfter).
  useEffect(() => {
    if (isNativeApp()) return;
    const sweep = () => { if (document.visibilityState === 'visible') void closeExpiredNotifications(); };
    sweep();
    document.addEventListener('visibilitychange', sweep);
    return () => document.removeEventListener('visibilitychange', sweep);
  }, []);
  // Native app: the server ships the matching app build; offer it when it is newer than this one (banner and one
  // notification per build, never blocking). Checked on start and whenever the app returns to the foreground.
  const [appUpdate, setAppUpdate] = useState<AppUpdate | null>(null);
  useEffect(() => {
    if (!isNativeApp()) return;
    const check = () => {
      if (document.visibilityState !== 'visible') return;
      void checkAppUpdate(getApiRoot()).then((u) => {
        setAppUpdate(u);
        if (u) void notifyAppUpdate(u);
      });
    };
    check();
    document.addEventListener('visibilitychange', check);
    return () => document.removeEventListener('visibilitychange', check);
  }, []);
  // Native app: keep the OS reminder schedule in line with the study. Re-planned when the study or
  // enrollment changes, after each completion, and whenever the app returns to the foreground, which
  // also rolls the 7-day window forward. No-op in the browser (see lib/nativeReminders.ts).
  const [reminderSyncTick, setReminderSyncTick] = useState(0);
  useEffect(() => {
    if (!isNativeApp()) return;
    // Timers do not run while the app is backgrounded, so also refresh the availability cards on return
    // (a notification tap would otherwise land on a stale "Opens at 20:00" card).
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return;
      setNowTick((t) => t + 1);
      setReminderSyncTick((t) => t + 1);
      refreshNotifPerm(); // permission may have been changed in Android's settings meanwhile
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, []);
  useEffect(() => {
    if (!isNativeApp() || !study || !userId || enrolledAt == null) return;
    const plan = planReminders(study, enrolledAt, Date.now(), loadCompletions(study.id, userId), {
      exclude: (q) => q.title === TRIALS_QN_TITLE,
      userId,
    });
    // Phone sensing gets the same prompt times: the accelerometer records around them (lib/phoneSensing.ts).
    // Independent of the notification switches below. Also re-run on return to the foreground, which picks up
    // a permission granted in Android's settings.
    void configurePhoneSensing({
      server: getApiRoot(), study, userId, accessKey: accessKey || localStorage.getItem(LAST_KEY_STORE) || '',
      promptTimes: plan.map((r) => r.at),
    }).then((st) => { if (st) setSensingStatus(st); });
    // Switched off by the research team (per study) or by the participant: take down what is planned.
    if (!notificationsActive(study, userId, true)) { void clearReminders(); return; }
    void syncReminders(plan, study.id).then((res) => {
      // Same funnel as web push, on the 'android' channel (researcher's Notifications panel).
      if (res?.added) void reportPushEvent({ studyId: study.id, userId, event: 'scheduled', channel: 'android', count: res.added });
      if (res?.fired) void reportPushEvent({ studyId: study.id, userId, event: 'received', channel: 'android', count: res.fired });
    });
  }, [study, userId, enrolledAt, reminderSyncTick, notifOff]); // eslint-disable-line react-hooks/exhaustive-deps
  // (Taps on Android notifications are reported natively, MainActivity.reportNotificationTap.)
  // Native app: arm the background poll for server-driven nudges. Re-armed on every app start, so a poll
  // that stopped itself (study had nothing to nudge about) picks up a later study change.
  useEffect(() => {
    if (!isNativeApp() || !study || !userId || enrolledAt == null) return;
    if (!notificationsActive(study, userId, true)) { void cancelServerNudges(); return; }
    void configureServerNudges(getApiRoot(), study.id, userId, enrolledAt, study.lang);
  }, [study?.id, study?.androidNotificationsEnabled, userId, enrolledAt, notifOff]); // eslint-disable-line react-hooks/exhaustive-deps
  // The participant's switch: read it for this study, and send a choice the server has not confirmed yet
  // (made offline) as soon as the study is open again.
  useEffect(() => {
    if (!study || !userId) return;
    const off = participantOff(study.id, userId);
    setNotifOff(off);
    if (hasUnsyncedChoice(study.id, userId)) {
      void setNotificationPreference({ study, serverVersion, userId, off, channel: isNativeApp() ? 'android' : 'web' })
        .then((ok) => { if (ok) setParticipantOff(study.id, userId, off, false); });
    }
  }, [study?.id, userId]); // eslint-disable-line react-hooks/exhaustive-deps
  // Keep an offline copy of the study's interactive tasks (device storage in the native app, Cache API
  // in the browser PWA). Runs after sign-up, on every app start and whenever the connection returns,
  // so a download that was cut short is picked up again.
  useEffect(() => {
    if (!study || !userId) return;
    const run = () => void syncStudyTasks(study, getApiRoot());
    run();
    window.addEventListener('online', run);
    return () => window.removeEventListener('online', run);
  }, [study, userId]);
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
    const availability = studyAvailability(study, userId, enrolledAt, Date.now(), loadCompletions(study.id, userId));
    for (const q of study.questionnaires) {
      if (q.title === TRIALS_QN_TITLE) continue;
      map.set(q.internalId, availability(q));
    }
    return map;
    // nowTick forces re-evaluation each minute.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [study, userId, enrolledAt, nowTick]);
  // Re-evaluate each minute, and right when the next window opens: windows can open mid-minute, and the list
  // should not lag behind it.
  useEffect(() => {
    const now = Date.now();
    let delay = 60000;
    for (const av of questionnaireAvailability.values())
      if (av.opensAt != null && av.opensAt > now) delay = Math.min(delay, av.opensAt - now + 250);
    const id = setTimeout(() => setNowTick((t) => t + 1), delay);
    return () => clearTimeout(id);
  }, [questionnaireAvailability]);
  // The list's greeting is a snapshot ("Nothing is open right now — … opens at 16:44"), while the cards follow the
  // schedule live. When a questionnaire opens after the chat said nothing was open, say so, so the chat does not
  // contradict the open card below it.
  useEffect(() => {
    if (phase !== 'list' || !awaitingOpenRef.current || !study) return;
    const open = study.questionnaires.filter((q) => questionnaireAvailability.get(q.internalId)?.state === 'available');
    if (!open.length) return;
    awaitingOpenRef.current = false;
    pushBot(open.length === 1 ? t('pwa_list_open_now_one', open[0].title) : t('pwa_list_open_now_many'));
  }, [phase, study, questionnaireAvailability, pushBot]);

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
      .then(async ({ study, serverVersion, vapidPublicKey, wearableProviders, errorReportsToGithub: reportsToGithub }) => {
        // Remember the code only once it actually loaded a study.
        localStorage.setItem(LAST_KEY_STORE, effectiveKey);
        // Before anything else can send: a local-only study (webLocalOnly) uploads nothing.
        setLocalOnly(study.webLocalOnly === true);
        // The study's language first, so the opening bubbles below are written in it.
        await setStudyLang(study);
        setStudy(study);
        setServerVersion(serverVersion);
        setVapidKey(vapidPublicKey ?? null);
        setWearableProviders(wearableProviders ?? []);
        setErrorReportsToGithub(reportsToGithub === true);
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
        // The ref normally follows the state on the next render, but a returning participant can go straight on to
        // the list in this same tick (enterList reads the ref): without this the list ran for user '' and anchored
        // the schedule to a fresh enrollment on the first return visit.
        userIdRef.current = uid;
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
            pushBot(t('pwa_app_welcome', study.title));
            pushBot(t('pwa_pid_conflict', invitedPid));
            setPhase('pid-conflict');
            return;
          }
        }
        // The app no longer asks for a display name: forget one an earlier version stored here.
        localStorage.removeItem(`esmira_participant_${study.id}`);
        const consented = localStorage.getItem(`esmira_consent_${study.id}`) === '1';
        // A returning participant sees the opening bubbles at once; pacing is for first reads
        // (cleared as soon as the app leaves the loading phase, see below).
        instantRef.current = consented;
        // A description that greets the participant itself replaces the generic welcome line.
        if (!/^welcome\b/i.test(headerText(study.studyDescription))) pushBot(t('pwa_app_welcome', study.title));
        // studyDescription is rich text (HTML) in ESMira; render it formatted.
        // informedConsentForm is a plain-text field — keep it plain (line breaks preserved).
        if (study.studyDescription) pushBot(study.studyDescription, true);
        // Left the study elsewhere? (Signed out on another device, or withdrawn by the research
        // team.) Then this device must not carry on: the Android app would otherwise keep
        // firing the reminders it scheduled itself. Asked in the background so a slow server
        // never delays the start; only an explicit answer counts, so being offline, or an
        // older server, changes nothing.
        if (consented && uid && study.webLocalOnly !== true) {
          void fetchNextNotification(study.id, uid).then((status) => {
            // Switched on/off on another device: follow it here, unless this device has a newer, unsent choice.
            if (status.notificationsOff !== null && !hasUnsyncedChoice(study.id, uid) && status.notificationsOff !== participantOff(study.id, uid)) {
              setParticipantOff(study.id, uid, status.notificationsOff, false);
              setNotifOff(status.notificationsOff);
            }
            if (!status.withdrawn) return;
            pushBot(t('pwa_app_left_study', study.title));
            setPhase('loading'); // no further actions
            setTimeout(() => { void resetDevice({ notifyServer: false, study, userId: uid }); }, 2500);
          });
        }
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
        } else {
          enterStudyGated(study);
        }
      })
      .catch((e: unknown) => {
        // A stored code that no longer resolves shouldn't trap the participant on
        // every launch — forget it so they can enter a fresh one.
        localStorage.removeItem(LAST_KEY_STORE);
        setLoadError(e instanceof Error ? e.message : t('pwa_error_load_study'));
        setPhase('error');
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Auto-scroll to bottom ────────────────────────────────────
  // Choice lists render full-height (no nested scrollbox), so a question card can be
  // taller than the viewport (e.g. the 9-option KSS). Scrolling to the bottom would
  // push the question text off-screen — align the card's top instead so the
  // participant reads the question first, then scrolls down through the options.
  const scrollToLive = useCallback((behavior: ScrollBehavior) => {
    const el = scrollRef.current;
    if (!el) return;
    const live = el.querySelector<HTMLElement>('[data-live-question]');
    if (live && live.offsetHeight > el.clientHeight) {
      const delta = live.getBoundingClientRect().top - el.getBoundingClientRect().top;
      el.scrollTo({ top: el.scrollTop + delta - 12, behavior });
    } else {
      el.scrollTo({ top: el.scrollHeight, behavior });
    }
  }, []);
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    // The smartphone-only invite page is read from the top: its heading and the top of the
    // QR code would otherwise sit under the fixed header on a short laptop screen.
    if (phase === 'phone-only') { el.scrollTo({ top: 0 }); return; }
    scrollToLive(reduceMotion ? 'auto' : 'smooth');
  }, [messages, visibleCount, currentQuestion, keystroker, phase, reduceMotion, scrollToLive]);

  // While a typed answer is written in the chat row, the conversation shrinks as the keyboard
  // opens and the composer grows: keep the question (its prompt first) in view.
  useEffect(() => {
    const el = scrollRef.current;
    if (!keystroker || !el || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => scrollToLive('auto'));
    ro.observe(el);
    return () => ro.disconnect();
  }, [keystroker, scrollToLive]);

  // The writer belongs to its question: once that question is answered, skipped or rewound
  // past (or the questionnaire is left), close it.
  useEffect(() => {
    if (keystroker && (phase !== 'survey' || currentQuestion?.id !== keystroker.id)) setKeystroker(null);
  }, [keystroker, currentQuestion, phase]);

  // ── Paced messages: reveal the next bubble once the previous one has had time to be read ──
  useEffect(() => { shownAtRef.current = Date.now(); }, [visibleCount]);
  useEffect(() => {
    // A rewound thread ("Change response") got shorter: carry on from its new end.
    if (shown > messages.length) { setShown(messages.length); return; }
    if (visibleCount === messages.length) return;
    const delay = revealDelayMs(messages[visibleCount - 1], messages[visibleCount], shownAtRef.current, Date.now(), pacingWpm);
    const t = setTimeout(() => setShown(visibleCount + 1), delay);
    return () => clearTimeout(t);
  }, [shown, messages, visibleCount, pacingWpm]);
  // The launch of a returning participant is over once the app shows its first step.
  useEffect(() => {
    if (phase !== 'loading') instantRef.current = false;
  }, [phase]);

  // ── Consent ──────────────────────────────────────────────────
  const onConsent = (agree: boolean) => {
    if (!study) return;
    if (!agree) {
      pushUser(t('pwa_consent_i_do_not_consent'));
      pushBot(t('pwa_consent_declined_reply'));
      setPhase('loading'); // no further actions
      return;
    }
    pushUser(t('pwa_consent_i_consent'));
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
    enterStudyGated(study);
  };

  // ── Leaving the study: a COMPLETE reset of this device back to first-visit state ──
  // Wipes all local ESMira data (identity, consent, onboarding flags, enrollment,
  // completions, queues, appearance prefs), unsubscribes push, and drops the IndexedDB
  // completion mirror, every Cache Storage entry, and the service worker itself. This
  // guarantees the next sign-up runs the full onboarding again (notifications gate +
  // welcome push + tutorial) instead of being treated as a returning device.
  //
  // With `notifyServer`, the server is told first that the participant has left: it deletes
  // the push subscription (so the per-minute sender stops at once) and records the `quit`
  // event, which withdraws the participant — no web push, Android nudge or chat prompt is
  // sent to them again, on any device, until they re-enrol. Both calls are bounded so a dead
  // connection cannot hold up the reset. Not needed when the server itself reported the
  // withdrawal (see the check in the study-load effect).
  const resetDevice = async (opts: { notifyServer: boolean; study?: EsmiraStudy | null; userId?: string }) => {
    const s = opts.study ?? study;
    const uid = opts.userId ?? userIdRef.current;
    const sid = s ? String(s.id) : '';
    const deviceToken = localStorage.getItem('esmira_device_token') || '';
    const effectiveKey = accessKey || localStorage.getItem(LAST_KEY_STORE) || '';
    if (opts.notifyServer && s && uid) {
      await withTimeout(unsubscribeFromPush({ study: s, serverVersion, userId: uid }), 6000, false);
      await withTimeout(submitQuit({ study: s, serverVersion, accessKey: effectiveKey, userId: uid }), 6000, false);
    }
    // Free the PID lock server-side before wiping the identity it belongs to.
    if (sid && uid && deviceToken) await withTimeout(releasePidLock(sid, uid, deviceToken), 6000, undefined);
    // Drop the push subscription (bound to the SW registration) so no stale endpoint lingers.
    // Not `serviceWorker.ready`: it never settles when no worker is registered (the native
    // Android app ships without one), which would hang sign-out here and never reach the reload.
    try {
      if ('serviceWorker' in navigator) {
        const regs = await withTimeout(navigator.serviceWorker.getRegistrations(), 4000, [] as readonly ServiceWorkerRegistration[]);
        for (const reg of regs) {
          const sub = await withTimeout(reg.pushManager?.getSubscription() ?? Promise.resolve(null), 4000, null);
          if (sub) await withTimeout(sub.unsubscribe(), 4000, false);
        }
      }
    } catch { /* best-effort */ }
    // Native app: cancel the on-device reminders too.
    await clearReminders();
    await cancelServerNudges();
    await cancelPhoneSensing();
    // Clear all web storage.
    try { localStorage.clear(); } catch { /* ignore */ }
    try { sessionStorage.clear(); } catch { /* ignore */ }
    // Delete the completion-mirror IndexedDB.
    try { indexedDB.deleteDatabase('esmira_state'); } catch { /* ignore */ }
    // Voice recordings and keystroke logs still waiting to upload belong to this identity too.
    await Promise.all([clearAudioQueue(), clearKeystrokeQueue()]);
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
    // Restart on the bare app path: a fresh navigation with no SW/caches/storage and no
    // ?key= = first-visit state (the invite-code screen). A plain reload would keep the
    // invite link's key and start the same study again.
    window.location.replace(signedOutUrl(window.location.pathname));
  };

  // ── Sign out = leave the study on this device (and tell the server so). ──
  const signOut = () => {
    if (!window.confirm(t('pwa_signout_confirm'))) return;
    void resetDevice({ notifyServer: true });
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
    refreshNotifPerm();
    setA11yOpen(true);
  };
  const closeSettings = () => {
    setA11yOpen(false);
    setSettingsView('main');
    setUpdateStatus('idle');
  };
  // Open the Settings modal straight onto the wearables panel (its only entry point is the grid menu tile).
  const openWearables = () => {
    setSettingsView('wearables');
    setA11yOpen(true);
  };

  // Phone sensing (Android app): what the study collects from the phone and the permissions it needs.
  const [sensingStatus, setSensingStatus] = useState<PhoneSensingStatus | null>(null);
  const sensing = useMemo(() => (isNativeApp() ? sensingSettings(study) : null), [study]);
  // Opened from Settings (which then offers Back) or straight from its grid tile (which doesn't).
  const [sensingViaSettings, setSensingViaSettings] = useState(false);
  const openSensing = (viaSettings = false) => {
    setSensingViaSettings(viaSettings);
    setSettingsView('sensing');
    setA11yOpen(true);
    void phoneSensingStatus().then((st) => { if (st) setSensingStatus(st); });
  };
  // "Wait for Wi-Fi" in the Phone sensing panel: shown at once, confirmed by the phone's status.
  const changeWaitForWifi = async (on: boolean) => {
    if (!sensing || !study) return;
    setSensingStatus((prev) => (prev ? { ...prev, uploadNetwork: on ? 'wifi_preferred' : 'any' } : prev));
    const st = await chooseUploadNetwork(sensing, study.id, userId, on);
    if (st) setSensingStatus(st);
  };
  const allowActivity = async () => {
    const st = await requestActivityPermission();
    if (st) setSensingStatus(st);
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
      const pending = await flushSubmitQueue();
      const { study: fresh, serverVersion: sv, vapidPublicKey, wearableProviders: wp, errorReportsToGithub: reportsToGithub, fromCache } = await fetchStudy(effectiveKey, studyIdParam);
      setUpdatePending(pending);
      if (fromCache) { setUpdateStatus('offline'); return; } // nothing new was downloaded: don't claim it was
      setLocalOnly(fresh.webLocalOnly === true);
      await setStudyLang(fresh); // before the study is swapped in, so its text renders in the right language
      setStudy(fresh);
      setServerVersion(sv);
      setVapidKey(vapidPublicKey ?? null);
      setWearableProviders(wp ?? []);
      setErrorReportsToGithub(reportsToGithub === true);
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
        t('pwa_notif_welcome_title', s.title),
        t('pwa_notif_on_body'),
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
  // Settings → Notifications status. The Android app's WebView has no Notification API: there the
  // permission is Android's own (shown as 'default' while the system dialog has not been answered).
  const refreshNotifPerm = () => {
    if (isNativeApp()) {
      void reminderPermission().then((p) => setNotifPerm(p === 'prompt' ? 'default' : p));
      return;
    }
    if (typeof Notification !== 'undefined') setNotifPerm(Notification.permission);
  };
  const requestNotifications = async () => {
    if (isNativeApp()) {
      const p = await requestReminderPermission();
      setNotifPerm(p === 'prompt' ? 'default' : p);
      setReminderSyncTick((t) => t + 1);
      return;
    }
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

  // Daily reminder to turn notifications on (lib/notificationNudge.ts): a card above the questionnaire list, at
  // most once a day, while this study sends notifications to the participant but the device does not allow them.
  const [notifNudge, setNotifNudge] = useState<NudgeKind | null>(null);
  const [nudgeBusy, setNudgeBusy] = useState(false);
  const currentNotifPerm = useCallback(async (): Promise<NudgePermission> => {
    if (isNativeApp()) {
      const p = await reminderPermission();
      return p === 'prompt' ? 'default' : p;
    }
    return isPushSupported() && typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';
  }, []);
  const nudgeInstallable = !isNativeApp() && onIOS && !standalone;
  useEffect(() => { setNotifNudge(null); }, [study?.id, userId]);
  useEffect(() => {
    if (phase !== 'list' || !study || !userId || notifNudge) return;
    if (!nudgeEnabled(study) || !notificationsActive(study, userId, isNativeApp()) || !hasPrompts(study)) return;
    if (nudgeShownToday(study.id, userId)) return;
    let cancelled = false;
    void currentNotifPerm().then((perm) => {
      const kind = nudgeKind(perm, nudgeInstallable);
      if (cancelled || !kind) return;
      markNudgeShown(study.id, userId);
      setNotifNudge(kind);
    });
    return () => { cancelled = true; };
  }, [phase, study, userId, notifOff, nowTick, notifNudge, nudgeInstallable, currentNotifPerm]);
  // Notifications are on now (from the card, Settings, or the phone's / browser's settings): plan or subscribe.
  const nudgeGranted = useCallback(() => {
    if (!study) return;
    setNotifNudge(null);
    setNotifPerm('granted');
    localStorage.removeItem(`esmira_push_optout_${study.id}`);
    if (isNativeApp()) setReminderSyncTick((t) => t + 1);
    else void subscribePush();
    pushBot(t('pwa_notif_on_reminder'));
  }, [study, subscribePush, pushBot, setReminderSyncTick]);
  // The permission may change in the settings while the app is in the background: re-check on return.
  useEffect(() => {
    if (!notifNudge) return;
    if (notifPerm === 'granted') { setNotifNudge(null); return; }
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return;
      void currentNotifPerm().then((perm) => {
        if (perm === 'granted') nudgeGranted();
        else setNotifNudge(nudgeKind(perm, nudgeInstallable));
      });
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [notifNudge, notifPerm, nudgeInstallable, currentNotifPerm, nudgeGranted]);
  // The card's main button while the system dialog can still be shown.
  const nudgeAllow = useCallback(async () => {
    if (nudgeBusy) return;
    setNudgeBusy(true);
    try {
      let perm: NudgePermission = 'unsupported';
      if (isNativeApp()) {
        const p = await requestReminderPermission();
        perm = p === 'prompt' ? 'default' : p;
      } else if (typeof Notification !== 'undefined') {
        perm = await Notification.requestPermission();
      }
      if (perm === 'granted') nudgeGranted();
      else {
        setNotifPerm(perm);
        if (perm === 'denied') setNotifNudge('blocked');
      }
    } catch {
      /* dismissed / unsupported */
    } finally {
      setNudgeBusy(false);
    }
  }, [nudgeBusy, nudgeGranted]);
  // Blocked: the Android app opens its notification settings; the browser can only be re-checked.
  const nudgeRecheck = useCallback(async () => {
    if (isNativeApp()) { await openNotificationSettings(); return; }
    const perm = await currentNotifPerm();
    if (perm === 'granted') nudgeGranted();
    else if (perm === 'default') void nudgeAllow();
  }, [currentNotifPerm, nudgeGranted, nudgeAllow]);

  // After the notifications step: studies with voice-recording items explain why the app needs
  // the microphone, and only a tap on "Allow microphone" shows the system prompt (see
  // lib/permissions.ts). Asked once per study; skipped when the researcher turned the step off
  // or access is already granted.
  const continueAfterNotifications = useCallback((s: EsmiraStudy) => {
    void (async () => {
      // Phone sensing (Android app): the Physical activity dialog now; Usage access lives in Android's
      // settings, so it is explained here and offered from the Phone sensing tile (see PhoneSensingPanel).
      const sense = isNativeApp() ? sensingSettings(s) : null;
      if (sense) {
        const missing = missingPermissions(sense, await phoneSensingStatus());
        if (missing.activity) {
          pushBot(t('pwa_onboard_activity_prompt'));
          const st = await requestActivityPermission();
          if (st) setSensingStatus(st);
        }
        if (missing.usage) {
          pushBot(t('pwa_onboard_usage_prompt'));
        }
      }
      if (!micPending(s)) { enterStudy(s); return; }
      if ((await microphonePermission()) === 'granted') {
        localStorage.setItem(micOnboardedKey(s.id), '1');
        enterStudy(s);
        return;
      }
      pushBot(t('pwa_onboard_mic_explain'));
      setMicStatus('ask');
      setPhase('microphone');
    })();
  }, [enterStudy, pushBot]);

  // Whether the mandatory notifications step is already settled for this study/user:
  // push is off for the study, permission is granted + subscribed, or the participant
  // has taken the "continue without notifications" escape hatch.
  const notificationsSettled = useCallback((s: EsmiraStudy): boolean => {
    if (!s.webPushEnabled) return true;
    if (participantOff(s.id, userIdRef.current)) return true; // switched off in Settings: don't ask again
    // Native app: reminders do not use Web Push (the WebView has none), and there is no Home
    // Screen to add the app to. Android notification permission is handled natively instead.
    if (isNativeApp()) return true;
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
    // Native app: reminders are scheduled on the device. While Android's notification permission
    // is undecided (and the participant hasn't tapped "Not now"), the notifications step explains
    // it first and its button shows the system dialog (Android 13+). Declining is allowed: the
    // help panel explains how to turn reminders on later, and the questionnaires stay reachable.
    if (isNativeApp()) {
      void (async () => {
        if (notificationsActive(s, userIdRef.current, true) && hasPrompts(s)
          && localStorage.getItem(`esmira_push_optout_${s.id}`) !== '1'
          && (await reminderPermission()) === 'prompt') {
          pushBot(micPending(s)
            ? t('pwa_onboard_native_two_perms')
            : t('pwa_onboard_native_notif'));
          setPhase('notifications');
          return;
        }
        setReminderSyncTick((t) => t + 1);
        continueAfterNotifications(s);
      })();
      return;
    }
    if (notificationsSettled(s)) { continueAfterNotifications(s); return; }
    if (isPushSupported() && typeof Notification !== 'undefined' && Notification.permission === 'granted') {
      void (async () => {
        await subscribePush();
        await maybeDeliverWelcome(s);
        continueAfterNotifications(s);
      })();
      return;
    }
    pushBot(micPending(s)
      ? t('pwa_onboard_web_two_perms')
      : t('pwa_onboard_web_notif'));
    setPhase('notifications');
  }, [notificationsSettled, continueAfterNotifications, subscribePush, maybeDeliverWelcome, pushBot, setReminderSyncTick]);

  // Notifications step in the native app — primary action: show Android's notification dialog,
  // then carry on whatever the answer (reminders are optional, see enterStudyGated).
  const allowNativeNotifications = useCallback(async () => {
    if (!study || notifBusy) return;
    setNotifBusy(true);
    try {
      const perm = await requestReminderPermission();
      if (perm !== 'granted') markNudgeShown(study.id, userIdRef.current); // just declined: ask again tomorrow
      pushBot(perm === 'granted'
        ? t('pwa_notif_on_reminder')
        : t('pwa_onboard_notif_later_phone'));
      setReminderSyncTick((t) => t + 1);
      continueAfterNotifications(study);
    } finally {
      setNotifBusy(false);
    }
  }, [study, notifBusy, pushBot, setReminderSyncTick, continueAfterNotifications]);

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
        pushBot(t('pwa_onboard_notif_open_only'));
        continueAfterNotifications(study);
        return;
      }
      // Record that a welcome has been dispatched so the silent path won't re-send later.
      localStorage.setItem(`esmira_push_welcomed_${study.id}_${userIdRef.current}`, '1');
      const serverSucceeded = await deliverWelcome(study);
      pushBot(serverSucceeded
        ? t('pwa_onboard_welcome_sent')
        : t('pwa_onboard_welcome_sent_device'));
      pushBot(t('pwa_onboard_welcome_confirm_ask'));
      setAwaitingWelcomeConfirm(true);
    } finally {
      setNotifBusy(false);
    }
  }, [study, notifBusy, subscribePush, deliverWelcome, pushBot, continueAfterNotifications]);

  // Notifications-step confirmation — the participant saw the welcome. Record it (so we
  // never re-welcome or re-prompt) and continue into the study.
  const confirmWelcomeReceived = useCallback(() => {
    if (!study) return;
    const uid = userIdRef.current;
    localStorage.setItem(`esmira_push_welcome_confirmed_${study.id}_${uid}`, '1');
    void reportPushEvent({ studyId: study.id, userId: uid, event: 'welcome_confirmed' });
    pushUser(t('pwa_onboard_yes_got_it'));
    pushBot(t('pwa_onboard_all_set'));
    setAwaitingWelcomeConfirm(false);
    continueAfterNotifications(study);
  }, [study, pushUser, pushBot, continueAfterNotifications]);

  // Notifications-step confirmation — the welcome didn't arrive. Flag it for the research
  // team's Push panel, try one local notification so the participant still sees something,
  // then continue (a delivery problem must never block participation).
  const reportWelcomeMissed = useCallback(async () => {
    if (!study) return;
    const uid = userIdRef.current;
    void reportPushEvent({ studyId: study.id, userId: uid, event: 'welcome_missed' });
    pushUser(t('pwa_onboard_no_nothing'));
    const shown = await showLocalNotification(
      t('pwa_notif_welcome_title', study.title),
      t('pwa_notif_on_body'),
    );
    pushBot(shown
      ? t('pwa_onboard_missed_shown')
      : t('pwa_onboard_missed_not_shown'));
    setAwaitingWelcomeConfirm(false);
    continueAfterNotifications(study);
  }, [study, pushUser, pushBot, continueAfterNotifications]);

  // Settings → Notifications switch. Off: nothing more is planned or sent to this participant (the server
  // is told, so web push stops too); they stay in the study and can still open questionnaires. On: ask for
  // permission if needed and plan / subscribe again.
  const changeNotificationsOff = useCallback(async (off: boolean) => {
    if (!study || !userId) return;
    const native = isNativeApp();
    setParticipantOff(study.id, userId, off, true);
    setNotifOff(off);
    if (!off) {
      localStorage.removeItem(`esmira_push_optout_${study.id}`);
      if (native) {
        await requestReminderPermission();
        setReminderSyncTick((t) => t + 1);
      } else if (typeof Notification !== 'undefined' && Notification.permission !== 'granted') {
        await requestNotifications();
      } else {
        void subscribePush();
      }
    }
    const ok = await setNotificationPreference({ study, serverVersion, userId, off, channel: native ? 'android' : 'web' });
    if (ok) setParticipantOff(study.id, userId, off, false);
  }, [study, userId, serverVersion, subscribePush]); // eslint-disable-line react-hooks/exhaustive-deps

  // Mandatory notifications step — escape hatch. Records the opt-out (so the step
  // isn't shown again for this study) and continues into the study.
  const skipNotifications = useCallback(() => {
    if (!study) return;
    localStorage.setItem(`esmira_push_optout_${study.id}`, '1');
    markNudgeShown(study.id, userIdRef.current); // the daily reminder starts tomorrow
    pushUser(t('pwa_onboard_not_now'));
    pushBot(nudgeEnabled(study)
      ? t('pwa_onboard_skip_tomorrow')
      : t('pwa_onboard_skip_settings'));
    continueAfterNotifications(study);
  }, [study, pushBot, pushUser, continueAfterNotifications]);

  // Microphone step — primary action. Only now is the system prompt shown; access is released
  // straight away (nothing is recorded). A refusal keeps the participant on the step, which
  // then explains how to unblock it and offers to carry on without.
  const allowMicrophone = useCallback(async () => {
    if (!study || micBusy) return;
    setMicBusy(true);
    try {
      const result = await requestMicrophone();
      if (result !== 'granted') { setMicStatus(result); return; }
      localStorage.setItem(micOnboardedKey(study.id), '1');
      pushBot(t('pwa_onboard_mic_ready'));
      enterStudy(study);
    } finally {
      setMicBusy(false);
    }
  }, [study, micBusy, pushBot, enterStudy]);

  // Microphone step — escape hatch. Recorded so the step isn't shown again for this study;
  // the recorder still asks (or explains a block) when a voice question comes up.
  const skipMicrophone = useCallback(() => {
    if (!study) return;
    localStorage.setItem(micOnboardedKey(study.id), '1');
    pushUser(micStatus === 'ask' ? t('pwa_onboard_not_now') : t('pwa_onboard_continue_no_mic'));
    pushBot(micStatus === 'ask'
      ? t('pwa_onboard_mic_skip_ask')
      : t('pwa_onboard_mic_skip_blocked'));
    enterStudy(study);
  }, [study, micStatus, pushBot, pushUser, enterStudy]);

  // Refresh the "Next notification" row whenever the Details panel opens. The native app plans its reminders
  // on the phone, so the row reads the same plan the phone was handed (the next prompt's opening time).
  useEffect(() => {
    if (aboutOpen && isNativeApp() && study && userId && enrolledAt != null) {
      const now = Date.now();
      const plan = planReminders(study, enrolledAt, now, loadCompletions(study.id, userId), {
        exclude: (q) => q.title === TRIALS_QN_TITLE,
        userId,
      });
      setNextNotification(plan.find((r) => r.at > now)?.at ?? null);
      return;
    }
    if (!aboutOpen || !study?.webPushEnabled || !userId) { setNextNotification(null); return; }
    let cancelled = false;
    void fetchNextNotification(study.id, userId).then((r) => { if (!cancelled) setNextNotification(r.next); });
    return () => { cancelled = true; };
  }, [aboutOpen, study, userId, enrolledAt]);

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

  // ── Footer submit (text-question answer) ─────────────────────
  const onFooterSend = () => {
    const value = footerValue.trim();
    if (!value) return;
    if (phase === 'survey' && currentQuestion?.type === 'text') {
      setFooterValue('');
      handleRespond(currentQuestion.id, value);
    }
  };

  // ── Saved progress (lib/pausedCheckin.ts) ────────────────────
  // Keep the running check-in on this device after every step, so closing the app (or the phone killing it)
  // loses nothing: reopening carries on while its window is open. Returns false when storage refuses.
  const saveProgress = (remindLater = false): boolean => {
    const engine = engineRef.current;
    const active = activeQRef.current;
    if (!engine || !study || !active || practiceRef.current) return false;
    return savePaused(study.id, userId, {
      questionnaireId: active.id,
      currentIndex: engine.getState().currentIndex,
      responses: { ...engine.getResponseMap() },
      specifyTexts: { ...engine.getSpecifyTexts() },
      audioIds: [...audioIdsRef.current],
      keystrokeIds: [...keystrokeIdsRef.current],
      keystrokeTexts: { ...keystrokeTextsRef.current },
      keystrokeModes: { ...keystrokeModesRef.current },
      cognitiveIds: engine.session.questions.filter((x) => x.type === 'cognitive').map((x) => x.id),
      order: engine.session.questions.map((x) => x.id),
      fallbacks: engine.getActivatedFallbacks(),
      ...(activeOccKeyRef.current ? { occKey: activeOccKeyRef.current } : {}),
      ...(remindLater ? { remindLater: true } : {}),
      pausedAt: Date.now(),
      startedAt: surveyStartRef.current,
    });
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
          av.state === 'completed' ? t('pwa_list_already_completed')
          : av.state === 'ended' ? t('pwa_list_no_longer_available')
          : t('pwa_list_not_open_yet', av.reason),
        );
        return;
      }
    }
    practiceRef.current = practice;
    // A daily-plan check-in counts against the window it was opened in, even if that closes while answering.
    activeOccKeyRef.current = practice ? undefined : questionnaireAvailability.get(internalId)?.occKey;
    // A check-in saved on this device (closed mid-way, or "Remind me later") picks up where it stopped, but only in
    // the window it was opened in (lib/pausedCheckin.ts). Saved answers from an earlier window are dropped.
    const saved = practice ? null : loadPaused(study.id, userId, internalId);
    let resume = saved && canResume(saved, questionnaireAvailability.get(internalId)) ? saved : null;
    const build = () => {
      const s = adaptQuestionnaire(study.id, q, Date.now(), { practice, battery: resume?.cognitiveIds, order: resume?.order });
      if (resume) {
        s.answered = resume.responses;
        s.current_question_index = resume.currentIndex;
      }
      return { session: s, engine: new OfflineSurveyEngine(s, resume?.fallbacks) };
    };
    let { session, engine } = build();
    // Nothing left to ask (the questionnaire was edited since): start afresh rather than on an empty screen.
    if (resume && !engine.getCurrentQuestion()) {
      resume = null;
      ({ session, engine } = build());
    }
    if (resume) engine.restoreSpecifyTexts(resume.specifyTexts);
    engineRef.current = engine;
    activeQRef.current = { id: q.internalId, name: q.title, changeMode: q.changeResponseMode ?? 'previous' };
    setActiveQTitle(q.title);
    if (!practice) void recordPromptEvent(study, 'opened', q.internalId, q.title);
    surveyStartRef.current = resume?.startedAt ?? Date.now();
    audioIdsRef.current = resume ? [...resume.audioIds] : [];
    keystrokeIdsRef.current = resume ? [...resume.keystrokeIds] : [];
    keystrokeTextsRef.current = resume ? { ...resume.keystrokeTexts } : {};
    keystrokeModesRef.current = resume ? { ...resume.keystrokeModes } : {};
    pvtLinesRef.current = {};
    // A participant's tap shows as their bubble; an automatic start labels the check-in instead.
    if (auto) pushSection(q.title);
    else pushUser(q.title);
    if (resume) {
      // Show what was answered before the pause, then carry on from the saved position.
      for (const done of session.questions) {
        const value = resume.responses[done.id];
        if (value === undefined) continue;
        if (done.type === 'cognitive') {
          pushBot(done.title || t('pwa_survey_cognitive_task'));
          pushUser(t('pwa_survey_completed'));
        } else {
          pushBot(settledQuestionHtml(done), !!done.is_html);
          pushUser(formatAnswer(done, value));
        }
      }
      pushBot(t('pwa_survey_welcome_back'));
    }
    if (practice) pushSection(t('pwa_survey_practice_run'));
    // Saved from the start, so a check-in closed before its first answer still reopens (with the same task battery).
    // This also replaces any saved answers the window check above turned down.
    else saveProgress();
    setPhase('survey');
    const transition = engine.getSectionTransition();
    if (transition?.description) pushSection(transition.description);
    setCurrentQuestion(engine.getCurrentQuestion());
    setProgress(engine.getProgress());
  };

  // PWA: hold an app update's reload while a check-in is open or being sent (lib/reloadGate.ts).
  useEffect(() => {
    updateReload.hold(phase === 'survey' || submitting);
  }, [phase, submitting]);

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
      // A typed (keystroke) answer's value is its log's identifier: echo what was written.
      const typed = q.type === 'keystroke_text' ? keystrokeTextsRef.current[q.id] : undefined;
      pushUser(typed ?? formatAnswer(q, value), questionId);
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
    saveProgress();
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
    if (q.type === 'cognitive') pushBot(q.title || t('pwa_survey_cognitive_task'), false, qid);
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
    const active = activeQRef.current;
    if (active && !practiceRef.current) void recordPromptEvent(study, 'closed', active.id, active.name);
    engineRef.current = null;
    activeQRef.current = null;
    setCurrentQuestion(null);
    setSpecify(null);
    setProgress(0);
    setPhase('list');
  };

  // Task-card "Remind me later" (webTaskActions): park the answers so far on this device, to resume from the
  // list. No push is scheduled, so the message promises a saved place, not a reminder.
  const handleRemindLater = () => {
    if (!engineRef.current || !study || !activeQRef.current) return;
    pushUser(t('pwa_survey_remind_later'));
    const saved = saveProgress(true);
    if (!saved) clearPaused(study.id, userId, activeQRef.current.id);
    pushBot(saved
      ? t('pwa_survey_remind_saved')
      : t('pwa_survey_remind_failed'));
    leaveSurvey();
  };

  // Task-card "Skip check-in" (webTaskActions): drop the whole questionnaire, nothing is submitted.
  const handleSkipCheckin = () => {
    if (!study) return;
    pushUser(t('pwa_survey_skip_checkin'));
    if (activeQRef.current) clearPaused(study.id, userId, activeQRef.current.id);
    pushBot(t('pwa_survey_skip_checkin_done'));
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
      saveProgress();
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
    pushUser(t('pwa_survey_skipped'), qid);
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
      pushBot(t('pwa_survey_practice_complete'));
      engineRef.current = null;
      activeQRef.current = null;
      practiceRef.current = false;
      setPhase('tutorial');
      return;
    }
    // Handed over for sending (or the offline queue) now, so it is no longer an unfinished check-in.
    clearPaused(study.id, userId, active.id);
    setSubmitting(true);
    void recordPromptEvent(study, 'submitted', active.id, active.name);
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
        recordCompletion(study.id, userId, q.internalId, submittedAt, activeOccKeyRef.current);
        // Mirror to IndexedDB so the SW suppresses further prompts for this questionnaire.
        const rec = loadCompletions(study.id, userId)[q.internalId];
        if (rec) void mirrorCompletion(study.id, userId, q.internalId, rec.lastAt, rec.count, rec.occ);
      }
      setNowTick((t) => t + 1);
      setReminderSyncTick((t) => t + 1);
    }
    if (ok) setPlanRefreshTick((t) => t + 1);
    setSubmitting(false);
    // Studies that opt in (webSnapshot) get a snapshot, the participant's own answers and a thank-you.
    const messages = snapshotMessages(
      study, engine.session.questions, engine.getResponseMap(),
      Object.values(pvtLinesRef.current).find((line): line is string => !!line) ?? null,
    );
    if (study.webLocalOnly) {
      // Nothing was sent, so never claim the responses were recorded.
      if (!messages) pushBot(t('pwa_complete_local_only'));
    } else {
      pushBot(ok
        ? t('pwa_complete_recorded')
        : t(unsentAnswersNotSaved() ? 'pwa_complete_not_saved' : 'pwa_complete_offline'));
    }
    messages?.forEach((message) => pushBot(message));
    engineRef.current = null;
    activeQRef.current = null;
    // A study with an end snapshot (webSnapshot) or marked single-run (webSingleRun) ends on its
    // thank-you: no offer of another questionnaire and no list. Reopening the app returns to the list.
    if (messages || study.webSingleRun) {
      setPhase('done');
      return;
    }
    // Only offer another questionnaire if one is actually completable right now —
    // otherwise the prompt dead-ends into a locked card (e.g. "Opens tomorrow 08:00"),
    // which reads as a bug rather than as there simply being nothing left to do today.
    const availability = studyAvailability(study, userId, anchor, Date.now(), loadCompletions(study.id, userId));
    const anotherAvailable = study.questionnaires.some((q) => q.title !== TRIALS_QN_TITLE && availability(q).state === 'available');
    if (anotherAvailable) {
      pushBot(t('pwa_complete_another'));
    }
    awaitingOpenRef.current = !anotherAvailable;
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
      pushBot(q.title || t('pwa_survey_cognitive_task'), false, qid);
      // Participant sees only a neutral confirmation — no performance feedback.
      // The full summary (trials, duration, correctness) is still stored below.
      pushUser(t('pwa_survey_completed'), qid);
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
      case 'phone-only':
      case 'pid-conflict': return t('pwa_app_status_sign_up');
      case 'consent': return t('pwa_app_status_consent');
      case 'notifications': return t('pwa_notifications');
      case 'microphone': return t('pwa_app_status_microphone');
      case 'tutorialOffer':
      case 'tutorial': return t('pwa_app_status_tutorial');
      case 'survey': return activeQTitle || t('pwa_app_status_survey');
      case 'done': return t('pwa_app_done');
      case 'list': {
        const DAY_MS = 24 * 60 * 60 * 1000;
        const avValues = [...questionnaireAvailability.values()];
        const period = study ? studyPeriod(study) : null;

        // All questionnaires ended → study over
        if (avValues.length > 0 && avValues.every(av => av.state === 'ended')) {
          if (enrolledAt && period && period.last >= 0) {
            const d = new Date(enrolledAt + period.last * DAY_MS); // the last study day
            return t('pwa_app_status_ended', d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }));
          }
          return t('pwa_app_status_study_ended');
        }
        if (study && enrolledAt) {
          // None available yet → the study starts when the first one opens
          const startsAt = avValues.length > 0 && avValues.every(av => av.state === 'upcoming')
            ? avValues.map(av => av.opensAt).filter((at): at is number => at != null).sort((a, b) => a - b)[0] ?? null
            : null;
          const label = studyDayLabel(study, enrolledAt, Date.now(), startsAt);
          if (label) return label;
        }
        return t('pwa_app_status_questionnaires');
      }
      case 'loading': return t('pwa_app_status_loading');
      case 'error': return t('pwa_app_status_error');
      default: return t('pwa_app_status_survey');
    }
  }, [phase, activeQTitle, questionnaireAvailability, enrolledAt, study, lang]);

  // The most recently answered question shows a "Change response" pill (t-1).
  const lastAnswerId = useMemo(() => {
    let id: string | undefined;
    for (const m of messages) if (m.sender === 'user' && m.qid) id = m.id;
    return id;
  }, [messages]);
  // A record_keystrokes answer is being written in the chat row (KeystrokeComposer).
  const writing = phase === 'survey' && keystroker !== null && currentQuestion?.id === keystroker.id;
  const footerActive = !pacing && phase === 'survey' && currentQuestion?.type === 'text';
  const [footerHintOpen, setFooterHintOpen] = useState(false);
  useEffect(() => { setFooterHintOpen(false); }, [footerActive, phase]);
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
    // The native app ships once for every server, so the participant says which one.
    if (isNativeApp() && !setServerBase(serverInput)) { setServerError(true); return; }
    window.location.assign(enrollUrl(window.location.pathname, code, pidInput));
  };

  // Quick-actions grid menu at the start of the chat row (shared by the plain input and the
  // keystroke composer, which swaps in while a typed answer is being written).
  const quickActionsMenu = (
    <div className="relative shrink-0">
      <button
        onClick={() => setGridMenuOpen((o) => !o)}
        disabled={!quickActionsEnabled}
        className="p-3 text-on-surface-variant hover:bg-surface-container-high dark:hover:bg-surface-container-highest rounded-full transition-colors disabled:opacity-40 disabled:hover:bg-transparent disabled:cursor-not-allowed"
        aria-label={t('pwa_app_quick_actions')}
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
              ref={gridMenuRef} tabIndex={-1} role="group" aria-label={t('pwa_app_quick_actions_menu')}
              initial={reduceMotion ? { opacity: 1 } : { opacity: 0, scale: 0.95, y: 10 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.95, y: 10 }}
              transition={{ duration: reduceMotion ? 0 : 0.15 }}
              className={cn('absolute bottom-full left-0 mb-4 p-4 bg-white dark:bg-surface-container-lowest rounded-2xl shadow-xl z-50 border origin-bottom-left w-64', highContrast ? 'border-2 border-black' : 'border-slate-100 dark:border-outline-variant/30')}
            >
              <div className="grid grid-cols-2 gap-3">
                {[
                  { key: 'settings', display: t('pwa_settings'), icon: Settings, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-500/10', onSelect: openSettings },
                  { key: 'about', display: t('pwa_app_details'), icon: Info, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-500/10', onSelect: () => { setAboutView('main'); setAboutOpen(true); } },
                  ...(offeredWearables.length > 0
                    ? [{ key: 'wearables', display: t('pwa_app_wearables'), icon: Watch, color: 'text-teal-500', bg: 'bg-teal-50 dark:bg-teal-500/10', onSelect: openWearables }]
                    : []),
                  ...(sensing
                    ? [{ key: 'sensing', display: t('pwa_phone_sensing'), icon: Activity, color: 'text-rose-500', bg: 'bg-rose-50 dark:bg-rose-500/10', onSelect: () => openSensing() }]
                    : []),
                  { key: 'contact', display: t('pwa_contact_tile'), icon: MessageSquare, color: 'text-purple-500', bg: 'bg-purple-50 dark:bg-purple-500/10', onSelect: openContact },
                  ...(phase === 'survey' && currentQuestion
                    ? [{ key: 'skip', display: t('pwa_survey_skip'), icon: SkipForward, color: 'text-yellow-500', bg: 'bg-yellow-50 dark:bg-yellow-500/10', onSelect: handleSkip }]
                    : []),
                  { key: 'signout', display: t('pwa_signout_tile'), icon: LogOut, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-500/10', onSelect: signOut },
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
  );

  // ── Render ───────────────────────────────────────────────────
  // `relative` on the shell and the conversation: an absolutely positioned descendant (e.g. a `sr-only` span in
  // a locked questionnaire card) is otherwise placed against the page itself, so neither overflow clips it and it
  // stretches the document below the shell; scrolling past the end of the conversation then scrolled the whole
  // page, taking the header off screen and lifting the chat row off the bottom (lib/shellLayout.test.mts).
  return (
    <div className={cn(
      'app-shell relative flex flex-col h-dvh bg-surface overflow-hidden transition-colors duration-300',
      dark && 'dark', highContrast && 'high-contrast',
    )}>
      {/* Wearable connect/disconnect result toast (auto-dismisses). */}
      {wearableFlash && (
        <div role="status" aria-live="polite" aria-atomic="true" className="fixed top-3 left-1/2 -translate-x-1/2 z-[120] max-w-[92%]">
          <div className={cn('flex items-center gap-2 px-4 py-2.5 rounded-full shadow-lg text-sm font-semibold text-white',
            wearableFlash.ok ? 'bg-green-600' : 'bg-red-600')}>
            {wearableFlash.ok ? <CheckCircle size={16} aria-hidden="true" /> : <X size={16} aria-hidden="true" />}
            {t(wearableFlash.ok ? 'pwa_app_wearable_connected' : 'pwa_app_wearable_failed', WEARABLE_LABEL[wearableFlash.provider] ?? wearableFlash.provider)}
          </div>
        </div>
      )}

      {/* Header */}
      <header aria-label={study?.title ?? t('pwa_app_default_title')} className="app-header shrink-0 relative z-50 bg-[#075E54] dark:bg-surface-container-lowest text-white dark:text-on-surface shadow-md flex items-center justify-between px-4 py-3">
        <div className="flex items-center gap-3 min-w-0">
          <img src={`${import.meta.env.BASE_URL}esmira-logo.svg`} alt="" aria-hidden="true" className="w-10 h-10 rounded-full shrink-0 object-cover" />
          <div className="flex flex-col min-w-0">
            <h1 className="text-base font-bold leading-none truncate">{study?.title ?? t('pwa_app_default_title')}</h1>
            <span className="text-[12px] opacity-80 uppercase tracking-widest font-semibold truncate">
              {headerStatus}
            </span>
          </div>
        </div>
        {phase !== 'phone-only' && <InstallPrompt variant="compact" className="ml-2" />}
      </header>

      {/* Native app: a newer build is on the server (lib/appUpdate.ts). */}
      {appUpdate && (
        <div role="status" className="app-update shrink-0 relative z-40 bg-amber-50 dark:bg-amber-500/10 text-amber-900 dark:text-amber-200 border-b border-amber-200 dark:border-amber-500/30 px-4 py-2 flex items-center justify-between gap-3 text-sm">
          <span>{appUpdate.versionName ? t('pwa_app_update_available_version', appUpdate.versionName) : t('pwa_app_update_available')}</span>
          <button type="button" onClick={() => void openAppUpdate(appUpdate)}
            className="shrink-0 rounded-full bg-[#075E54] text-white px-4 py-1.5 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[#075E54]">
            {t('pwa_app_update_button')}
          </button>
        </div>
      )}

      {/* Chat area */}
      <main ref={scrollRef} role="log" aria-label={t('pwa_survey_conversation')} aria-live="polite" tabIndex={0} className="relative flex-1 min-h-0 chat-wallpaper overflow-y-auto px-4 py-6 flex flex-col gap-4 no-scrollbar focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
        <div className="flex justify-center my-2">
          <span className="bg-slate-700 dark:bg-surface-container-highest text-white dark:text-on-surface px-4 py-1.5 rounded-full text-[0.75rem] font-bold uppercase tracking-wider shadow-sm">{t('pwa_app_today')}</span>
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
                msg.sender === 'user' ? 'bg-primary text-on-primary dark:bg-primary-container dark:text-on-surface rounded-tr-none'
                  : 'bg-white dark:bg-surface-container-lowest text-on-surface rounded-tl-none border border-slate-200 dark:border-outline-variant/30')}>
                {msg.html
                  ? <div className={cn('leading-relaxed font-medium esmira-rich', textSizeClass)} dangerouslySetInnerHTML={{ __html: sanitizeHtml(msg.content) }} />
                  : <p className={cn('leading-relaxed font-medium whitespace-pre-wrap', textSizeClass)}>{msg.content}</p>}
                <div className="flex justify-end items-center gap-1 mt-1">
                  <span className={cn('text-[0.75rem] font-bold', msg.sender === 'user' ? 'text-on-primary dark:text-on-surface' : 'text-on-surface-variant')}>{nowTime()}</span>
                  {msg.sender === 'user' && <CheckCircle size={12} className="text-on-primary dark:text-on-surface opacity-70" aria-hidden="true" />}
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
                  <RotateCcw size={12} aria-hidden="true" /> {t('pwa_survey_change_response')}
                </button>
              )}
            </motion.div>
          ))}
        </AnimatePresence>
        {/* Paced messages: typing dots until the next bubble; no exit animation, so they never sit above it.
            The steps below wait for the last bubble, so their buttons never appear above text still to come. */}
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
              {t('pwa_error_enter_different_code')}
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
                    <h2 className="text-lg font-bold text-on-surface leading-snug">{t('pwa_onboard_install_title')}</h2>
                    <p className="text-sm text-on-surface-variant leading-relaxed mt-0.5">
                      {t('pwa_onboard_install_body')}
                    </p>
                  </div>
                </div>
                <ol className="flex flex-col gap-2.5 text-sm">
                  {funnelSteps(justInstalled).map((step) => (
                    <li key={step.n} className={cn('flex items-center gap-2.5', step.state === 'active' ? 'font-semibold text-on-surface' : 'text-on-surface-variant')}>
                      <span className={cn('flex items-center justify-center w-5 h-5 rounded-full text-[0.75rem] font-bold shrink-0',
                        step.state === 'active' ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant')}>
                        {step.state === 'done' ? <Check size={12} aria-label={t('pwa_app_done')} /> : step.n}
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
                <h2 className="text-lg font-bold text-on-surface">{t('pwa_onboard_enter_code_title')}</h2>
                <p className="text-sm text-on-surface-variant leading-relaxed">
                  {t('pwa_onboard_enter_code_body')}
                </p>
                {isNativeApp() && (
                  <>
                    <input
                      type="url"
                      inputMode="url"
                      autoCapitalize="none"
                      autoCorrect="off"
                      spellCheck={false}
                      value={serverInput}
                      onChange={(e) => { setServerInput(e.target.value); setServerError(false); }}
                      onKeyDown={(e) => { if (e.key === 'Enter') submitAccessKey(); }}
                      placeholder={t('pwa_onboard_server_address')}
                      aria-label={t('pwa_onboard_server_address')}
                      aria-invalid={serverError}
                      aria-describedby={serverError ? 'server-error' : undefined}
                      className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-outline-variant bg-surface text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                    {serverError && (
                      <p id="server-error" role="alert" className="text-sm text-red-600 dark:text-red-400">
                        {t('pwa_onboard_server_error')}
                      </p>
                    )}
                  </>
                )}
                <input
                  type="text"
                  inputMode="text"
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  autoFocus={!isNativeApp()}
                  value={keyInput}
                  onChange={(e) => setKeyInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') submitAccessKey(); }}
                  placeholder={t('pwa_onboard_invite_code')}
                  aria-label={t('pwa_onboard_invite_code_label')}
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
                  placeholder={t('pwa_onboard_pid_placeholder')}
                  aria-label={t('pwa_onboard_pid_label')}
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-outline-variant bg-surface text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <button
                  onClick={submitAccessKey}
                  disabled={!keyInput.trim() || (isNativeApp() && !serverInput.trim())}
                  className="w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-50 disabled:active:scale-100"
                >
                  {t('pwa_onboard_continue')}
                </button>
              </div>
            )}
          </div>
        )}

        {/* Loading */}
        {phase === 'loading' && messages.length === 0 && (
          <div className="self-center text-on-surface-variant text-sm">{t('pwa_app_loading_study')}</div>
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
        {phase === 'pid-conflict' && study && !pacing && (
          <div role="group" aria-label={t('pwa_pid_in_use')} className="self-start w-[85%] flex flex-col gap-3">
            <button
              onClick={async () => {
                const deviceToken = getOrCreateDeviceToken();
                const status = await claimPidLock(String(study.id), pidConflict!, deviceToken, true);
                if (status === 'claimed') {
                  pushUser(t('pwa_pid_transfer'));
                  setPidConflict(null);
                  if (study.studyDescription) pushBot(study.studyDescription, true);
                  const consented = localStorage.getItem(`esmira_consent_${study.id}`) === '1';
                  if (study.informedConsentForm && !consented) {
                    pushBot(study.informedConsentForm);
                    setPhase('consent');
                  } else {
                    enterStudyGated(study);
                  }
                }
              }}
              className="w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all"
            >
              {t('pwa_pid_transfer')}
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
              {t('pwa_pid_use_different')}
            </button>
          </div>
        )}

        {/* Consent actions */}
        {phase === 'consent' && !pacing && (
          <div className="self-start w-[85%] flex gap-3">
            <button onClick={() => onConsent(true)} className="flex-1 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">{t('pwa_consent_i_consent')}</button>
            <button onClick={() => onConsent(false)} className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">{t('pwa_consent_i_do_not_consent')}</button>
          </div>
        )}

        {/* Tutorial offer (yes/no) — shown after consent + name for tutorial-enabled studies */}
        {phase === 'tutorialOffer' && !pacing && (
          <div className="self-start w-[85%] flex gap-3">
            <button onClick={acceptTutorial} className="flex-1 inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
              <PlayCircle size={18} aria-hidden="true" /> {t('pwa_app_tutorial_yes')}
            </button>
            <button onClick={declineTutorial} className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">{t('pwa_app_tutorial_no')}</button>
          </div>
        )}

        {/* Notifications step in the native app: explain first, then Android's own dialog */}
        {phase === 'notifications' && study && isNativeApp() && !pacing && (
          <div role="group" aria-label={t('pwa_onboard_turn_on_notifications')} aria-live="polite" className="self-start w-[85%] flex flex-col gap-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-4">
            <div className="flex items-start gap-2">
              <BellRing size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
              <div>
                <p className="font-bold text-on-surface">{t('pwa_onboard_turn_on_notifications')}</p>
                <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                  {t('pwa_onboard_native_notif_body')}
                </p>
              </div>
            </div>
            <button onClick={() => void allowNativeNotifications()} disabled={notifBusy}
              className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 disabled:active:scale-100">
              <BellRing size={16} aria-hidden="true" /> {notifBusy ? t('pwa_onboard_waiting_answer') : t('pwa_onboard_allow_notifications')}
            </button>
            <button onClick={skipNotifications} disabled={notifBusy}
              className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors disabled:opacity-60">
              {t('pwa_onboard_not_now')}
            </button>
          </div>
        )}

        {/* Mandatory notifications step (push-enabled studies, after consent + name) */}
        {phase === 'notifications' && study && !isNativeApp() && !pacing && (() => {
          const supported = isPushSupported() && typeof Notification !== 'undefined';
          const denied = supported && notifPerm === 'denied';
          return (
          <div role="group" aria-label={t('pwa_onboard_turn_on_notifications')} aria-live="polite" className="self-start w-[85%] flex flex-col gap-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-4">
            {awaitingWelcomeConfirm ? (
              // A welcome was dispatched — ask the participant to confirm it actually showed
              // up (server push delivery can't be verified). "No" flags the research team.
              <>
                <div className="flex items-start gap-2">
                  <BellRing size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
                  <div>
                    <p className="font-bold text-on-surface">{t('pwa_onboard_arrive_title')}</p>
                    <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                      {t('pwa_onboard_arrive_body', t('pwa_onboard_yes_got_it'), t('pwa_onboard_no_nothing'))}
                    </p>
                  </div>
                </div>
                <div className="flex gap-3">
                  <button onClick={confirmWelcomeReceived}
                    className="flex-1 inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
                    <Check size={16} aria-hidden="true" /> {t('pwa_onboard_yes_got_it')}
                  </button>
                  <button onClick={() => void reportWelcomeMissed()}
                    className="flex-1 bg-surface-container-high text-on-surface font-bold py-3 rounded-full active:scale-95 transition-all">
                    {t('pwa_onboard_no_nothing')}
                  </button>
                </div>
              </>
            ) : (
            <>
            <div className="flex items-start gap-2">
              <BellRing size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
              <div>
                <p className="font-bold text-on-surface">{t('pwa_onboard_turn_on_notifications')}</p>
                <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                  {supported && !denied ? t('pwa_onboard_web_notif_body_allow') : t('pwa_onboard_web_notif_body')}
                </p>
              </div>
            </div>

            {!supported ? (
              // Push isn't available in this context (typically iOS Safari before the PWA
              // is installed) — guide them to add it to the Home Screen, then reopen.
              <>
                <p className={cn('text-on-surface-variant', textSizeClass)}>
                  {t('pwa_onboard_add_home_screen')}
                </p>
                <InstallPrompt variant="card" />
                {/* iOS gives the installed app its own empty storage, so the invite code from
                    this tab won't carry over — show it so they can type it in afterwards. */}
                {onIOS && !standalone && (
                  <InviteCodeNote code={accessKey || localStorage.getItem(LAST_KEY_STORE) || ''} participantId={invitedParticipantId(params)} />
                )}
                <button onClick={skipNotifications}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
                  {t('pwa_onboard_continue_no_notifications')}
                </button>
              </>
            ) : denied ? (
              // Permission was blocked — can't be re-prompted programmatically; explain how
              // to unblock, offer a re-check, and keep the escape hatch.
              <>
                <p className={cn('text-on-surface-variant', textSizeClass)}>
                  {t('pwa_onboard_blocked_site')}
                </p>
                <button
                  onClick={() => {
                    const perm = typeof Notification !== 'undefined' ? Notification.permission : 'denied';
                    setNotifPerm(perm);
                    if (perm !== 'denied') void enableNotifications();
                  }}
                  className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
                  <RefreshCw size={16} aria-hidden="true" /> {t('pwa_onboard_try_again')}
                </button>
                <button onClick={skipNotifications}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
                  {t('pwa_onboard_continue_no_notifications')}
                </button>
              </>
            ) : (
              <>
                <button onClick={() => void enableNotifications()} disabled={notifBusy}
                  className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 disabled:active:scale-100">
                  <BellRing size={16} aria-hidden="true" /> {notifBusy ? t('pwa_onboard_enabling') : t('pwa_onboard_enable_notifications')}
                </button>
                <button onClick={skipNotifications} disabled={notifBusy}
                  className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors disabled:opacity-60">
                  {t('pwa_onboard_continue_no_notifications')}
                </button>
              </>
            )}
            </>
            )}
          </div>
          );
        })()}

        {/* Microphone step (studies with voice-recording items, after notifications) */}
        {phase === 'microphone' && study && !pacing && (
          <div role="group" aria-label={t('pwa_onboard_mic_title')} aria-live="polite" className="self-start w-[85%] flex flex-col gap-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-4">
            <div className="flex items-start gap-2">
              <Mic size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
              <div>
                <p className="font-bold text-on-surface">{t('pwa_onboard_mic_title')}</p>
                <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                  {micStatus === 'denied'
                    ? t(isNativeApp() ? 'pwa_onboard_mic_blocked_app' : 'pwa_onboard_mic_blocked_web')
                    : micStatus === 'unsupported'
                      ? t('pwa_onboard_mic_unsupported')
                      : micStatus === 'error'
                        ? t('pwa_onboard_mic_error')
                        : t(isNativeApp() ? 'pwa_onboard_mic_prompt_app' : 'pwa_onboard_mic_prompt_web')}
                </p>
              </div>
            </div>
            {micStatus !== 'unsupported' && (
              <button onClick={() => void allowMicrophone()} disabled={micBusy}
                className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 disabled:active:scale-100">
                {micStatus === 'ask'
                  ? <><Mic size={16} aria-hidden="true" /> {micBusy ? t('pwa_onboard_waiting_answer') : t('pwa_onboard_allow_microphone')}</>
                  : <><RefreshCw size={16} aria-hidden="true" /> {t('pwa_onboard_try_again')}</>}
              </button>
            )}
            <button onClick={skipMicrophone} disabled={micBusy}
              className={micStatus === 'unsupported'
                ? 'w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all'
                : 'w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors disabled:opacity-60'}>
              {micStatus === 'ask' ? t('pwa_onboard_not_now') : micStatus === 'unsupported' ? t('pwa_onboard_continue') : t('pwa_onboard_continue_no_mic')}
            </button>
          </div>
        )}

        {/* Daily reminder to turn notifications on while they are off (lib/notificationNudge.ts) */}
        {phase === 'list' && study && !pacing && autoStartId == null && notifNudge && !notifOff && (
          <div role="group" aria-label={t('pwa_notif_off_title')} aria-live="polite" className="self-start w-[85%] flex flex-col gap-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-4">
            <div className="flex items-start gap-2">
              <BellOff size={20} className="text-primary shrink-0 mt-0.5" aria-hidden="true" />
              <div>
                <p className="font-bold text-on-surface">{t('pwa_notif_off_title')}</p>
                <p className={cn('text-on-surface-variant mt-0.5', textSizeClass)}>
                  {t('pwa_app_join_sentences', t('pwa_notif_off_lead'),
                    notifNudge === 'ask'
                      ? t(isNativeApp() ? 'pwa_notif_off_ask_app' : 'pwa_notif_off_ask_web')
                      : notifNudge === 'install'
                        ? t('pwa_notif_off_install')
                        : isNativeApp()
                          ? t('pwa_notif_off_blocked_app')
                          : onIOS
                            ? t('pwa_notif_off_blocked_ios')
                            : t('pwa_notif_off_blocked_web'))}
                </p>
              </div>
            </div>
            {notifNudge === 'install' ? (
              <>
                <InstallPrompt variant="card" />
                {/* iOS gives the installed app its own empty storage: show the code to type in there. */}
                <InviteCodeNote code={accessKey || localStorage.getItem(LAST_KEY_STORE) || ''} participantId={invitedParticipantId(params)} />
              </>
            ) : notifNudge === 'ask' ? (
              <button onClick={() => void nudgeAllow()} disabled={nudgeBusy}
                className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 disabled:active:scale-100">
                <BellRing size={16} aria-hidden="true" /> {nudgeBusy ? t('pwa_onboard_waiting_answer') : t('pwa_onboard_turn_on_notifications')}
              </button>
            ) : (
              <button onClick={() => void nudgeRecheck()}
                className="w-full inline-flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
                {isNativeApp()
                  ? <><Settings size={16} aria-hidden="true" /> {t('pwa_notif_open_settings')}</>
                  : <><RefreshCw size={16} aria-hidden="true" /> {t('pwa_onboard_try_again')}</>}
              </button>
            )}
            <button onClick={() => setNotifNudge(null)} disabled={nudgeBusy}
              className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors disabled:opacity-60">
              {t('pwa_onboard_not_now')}
            </button>
          </div>
        )}

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
          return (
          <div className="self-start w-[85%] flex flex-col gap-2">
            {items.map(({ q, av }) => {
              const open = !av || av.state === 'available';
              return open ? (
                <button key={q.internalId} onClick={() => startQuestionnaire(q.internalId)}
                  className="w-full flex items-center justify-between px-4 py-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 hover:bg-surface-container-high rounded-xl transition-colors text-left shadow-sm message-shadow">
                  <span className="min-w-0">
                    <span className={cn('block font-semibold', textSizeClass)}>{q.title}</span>
                    {userId && (() => { const saved = loadPaused(study.id, userId, q.internalId); return saved && canResume(saved, av); })() && (
                      <span className="block text-xs text-on-surface-variant mt-0.5">{t('pwa_list_paused')}</span>
                    )}
                  </span>
                  <ChevronRight size={18} className="text-outline-variant" aria-hidden="true" />
                </button>
              ) : (
                // Named by its visible text (title, then why it is locked). No aria-label: one that differs from the
                // visible text fails WCAG 2.5.3 Label in Name (axe label-content-name-mismatch).
                <button type="button" disabled key={q.internalId}
                  className="w-full flex items-center justify-between gap-3 px-4 py-3 bg-surface-container-high/40 dark:bg-surface-container-highest/40 border border-dashed border-outline-variant/40 rounded-xl text-left opacity-70">
                  <div className="min-w-0">
                    <span className={cn('font-semibold text-on-surface-variant', textSizeClass)}>{q.title}</span>
                    {av?.reason && <span className="sr-only">, </span>}
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
        {phase === 'tutorial' && study && !pacing && (
          <div className="self-start w-[85%] flex flex-col gap-2">
            {tutorialQuestionnaires(study).map((q) => {
              const n = countQuestions(q);
              return (
                <div key={q.internalId}
                  className="w-full flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 py-3 bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-xl shadow-sm message-shadow">
                  {/* Wraps the Practice button onto its own line once the title no longer fits beside
                      it (larger text sizes), instead of squeezing the title underneath it. */}
                  <div className="flex-1 basis-32 max-w-full break-words">
                    <p className={cn('font-semibold', textSizeClass)}>{q.title}</p>
                    <p className="text-xs text-on-surface-variant mt-0.5">{n === 1 ? t('pwa_app_tutorial_question_one', n) : t('pwa_app_tutorial_question_other', n)}</p>
                  </div>
                  <button onClick={() => startQuestionnaire(q.internalId, true)}
                    aria-label={t('pwa_app_tutorial_practice_label', q.title)}
                    className="shrink-0 inline-flex items-center gap-1.5 bg-secondary-container text-on-secondary-container font-semibold text-sm px-3 py-2 rounded-full active:scale-95 hover:brightness-95 transition-all">
                    <PlayCircle size={16} aria-hidden="true" /> {t('pwa_app_tutorial_practice')}
                  </button>
                </div>
              );
            })}
            <button onClick={finishTutorial}
              className="mt-2 w-full bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all">
              {t('pwa_app_tutorial_ready')}
            </button>
            <button onClick={finishTutorial}
              className="w-full text-on-surface-variant font-semibold py-2 rounded-full text-sm active:scale-95 transition-colors">
              {t('pwa_app_tutorial_skip')}
            </button>
          </div>
        )}

        {/* Active survey question */}
        {phase === 'survey' && currentQuestion && !pacing && (
          <SurveyInputs
            question={currentQuestion}
            progress={progress}
            textSizeClass={textSizeClass}
            reduceMotion={reduceMotion}
            onRespond={handleRespond}
            onContinueInfo={handleContinueInfo}
            onOpenWebview={(url, title) => {
              const qid = currentQuestion?.id;
              // Native app: run the offline copy when there is one (falls back to the network URL).
              void resolveTaskUrl(url).then((u) => setWebview({ url: u, title, qid }));
            }}
            onOpenRecorder={() => setRecorder(currentQuestion)}
            onSkipAudio={handleAudioSkip}
            onOpenKeystroke={() => setKeystroker(currentQuestion)}
            writing={writing}
            taskActions={study?.webTaskActions && !practiceRef.current
              ? { onRemindLater: handleRemindLater, onSkipCheckin: handleSkipCheckin }
              : undefined}
          />
        )}

        {submitting && <div role="status" aria-live="polite" className="self-center text-on-surface-variant text-sm">{t('pwa_survey_saving')}</div>}
      </main>

      {/* Footer. Not on the smartphone-only invite page: its menu actions (contact, error report,
          notifications, wearables) all need a participant id, which that page never creates. */}
      {phase !== 'phone-only' && (
      <footer aria-label={t('pwa_app_message_input')} className="app-footer shrink-0 relative z-50 bg-white/85 dark:bg-surface-container-lowest/85 rounded-t-2xl shadow-[0_-4px_12px_rgba(0,0,0,0.05)] px-4 py-3">
        {/* The blur sits on its own layer: a backdrop-filter on the footer itself would make it the
            containing block for `fixed` children, shrinking the quick-actions menu's tap-outside
            backdrop to the footer strip so taps on the conversation or header left the menu open. */}
        <div aria-hidden="true" className="absolute inset-0 -z-10 rounded-[inherit] backdrop-blur-md pointer-events-none" />
        {writing && keystroker ? (
          <KeystrokeComposer key={keystroker.id} question={keystroker} menu={quickActionsMenu} onSend={handleSaveKeystrokes} />
        ) : (
        <div className="flex items-center gap-2">
          {quickActionsMenu}
          {footerActive ? (
            <input
              type="text"
              value={footerValue}
              onChange={(e) => setFooterValue(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && onFooterSend()}
              aria-label={t('pwa_survey_type_response')}
              placeholder={t('pwa_survey_type_response_placeholder')}
              className="flex-1 min-w-0 bg-surface-container-high rounded-full px-4 py-3 text-[15px] text-ellipsis text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50"
            />
          ) : (
            // Nothing to type: the field is a hint. Cut off with an ellipsis on narrow screens; a tap
            // shows it in full (the row grows for as long as it is open).
            <button type="button" onClick={() => setFooterHintOpen((o) => !o)} aria-expanded={footerHintOpen}
              className={cn('flex-1 min-w-0 bg-surface-container-high px-4 py-3 text-[15px] text-left text-on-surface-variant focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50',
                footerHintOpen ? 'rounded-2xl whitespace-normal' : 'rounded-full truncate')}>
              {t('pwa_survey_tap_option')}
            </button>
          )}
          <button
            onClick={onFooterSend}
            disabled={!footerActive || !footerValue.trim()}
            className="bg-primary text-on-primary p-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100 shrink-0"
            aria-label={t('pwa_app_send')}
          >
            <Send size={18} aria-hidden="true" />
          </button>
        </div>
        )}
      </footer>
      )}

      {/* Settings modal — appearance + app-level features (error report, notifications, update, about) */}
      <AnimatePresence>
        {a11yOpen && (() => {
          const settingsTitle = settingsView === 'about' ? t('pwa_about_iemabot')
            : settingsView === 'notifications' ? t('pwa_notifications')
            : settingsView === 'errorReport' ? t('pwa_send_error_report')
            : settingsView === 'wearables' ? t('pwa_connect_wearables')
            : settingsView === 'sensing' ? t('pwa_phone_sensing')
            : t('pwa_settings');
          return (
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: reduceMotion ? 0 : 0.2 }}
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <motion.div ref={settingsDialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="settings-dialog-title" initial={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={reduceMotion ? { scale: 1 } : { scale: 0.95, y: 20 }}
              className="w-full max-w-md max-h-[85dvh] bg-white dark:bg-surface-container-lowest rounded-2xl shadow-2xl overflow-hidden flex flex-col text-on-surface">
              <div className="flex items-center justify-between gap-2 px-5 py-4 border-b border-outline-variant/30 shrink-0">
                <div className="flex items-center gap-2 min-w-0">
                  {/* Wearables, and Phone sensing opened from its grid tile, have no Settings page to go back to. */}
                  {settingsView !== 'main' && settingsView !== 'wearables' && (settingsView !== 'sensing' || sensingViaSettings) && (
                    <button onClick={() => setSettingsView('main')} aria-label={t('pwa_back')} className="min-w-11 min-h-11 -ml-2 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><ChevronLeft size={20} aria-hidden="true" /></button>
                  )}
                  <h2 id="settings-dialog-title" className="font-bold text-lg flex items-center gap-2 truncate">
                    {settingsView === 'main' && <Settings size={20} aria-hidden="true" />}{settingsTitle}
                  </h2>
                </div>
                <button onClick={closeSettings} aria-label={t('pwa_settings_close')} className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><X size={20} aria-hidden="true" /></button>
              </div>

              {settingsView === 'main' ? (
                <div className="p-5 flex flex-col gap-5 overflow-y-auto custom-scrollbar">
                  {/* Live preview — reflects theme, contrast and text size */}
                  <div>
                    <div className="text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">{t('pwa_settings_preview')}</div>
                    <div className="rounded-xl p-4 flex flex-col gap-3 chat-wallpaper border border-outline-variant/30 overflow-hidden bg-surface-container-low">
                      <div className="self-start max-w-[85%]">
                        <div className="px-3 py-2 rounded-2xl message-shadow bg-white dark:bg-surface-container-lowest text-on-surface rounded-tl-none border border-slate-200 dark:border-outline-variant/30">
                          <p className={cn('leading-relaxed font-medium', textSizeClass)}>{t('pwa_settings_preview_question')}</p>
                        </div>
                      </div>
                      <div className="self-end max-w-[85%]">
                        <div className="px-3 py-2 rounded-2xl message-shadow bg-primary text-on-primary dark:bg-primary-container dark:text-on-surface rounded-tr-none">
                          <p className={cn('leading-relaxed font-medium', textSizeClass)}>{t('pwa_settings_preview_answer')}</p>
                        </div>
                      </div>
                    </div>
                  </div>
                  <Toggle icon={<Moon size={18} aria-hidden="true" />} label={t('pwa_dark_mode')} on={dark} onClick={() => setDark((v) => !v)} />
                  <Toggle icon={<Contrast size={18} aria-hidden="true" />} label={t('pwa_high_contrast')} on={highContrast} onClick={() => setHighContrast((v) => !v)} />
                  <Toggle icon={<Sun size={18} aria-hidden="true" />} label={t('pwa_reduce_motion')} on={reduceMotion} onClick={() => setReduceMotion((v) => !v)} />
                  <div>
                    <div className="flex items-center gap-2 mb-2"><Type size={18} aria-hidden="true" /><span className="font-semibold text-sm">{t('pwa_text_size')}</span></div>
                    <div role="group" aria-label={t('pwa_text_size')} className="grid grid-cols-4 gap-2">
                      {(['normal', 'large', 'xlarge', 'xxlarge'] as TextSize[]).map((s) => (
                        <button key={s} onClick={() => setTextSize(s)} aria-pressed={textSize === s}
                          className={cn('py-2 rounded-full text-xs font-bold transition-colors',
                            textSize === s ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface')}>{t(TEXT_SIZE_KEY[s])}</button>
                      ))}
                    </div>
                  </div>
                  {/* App-level actions (mirrors the native ESMira app menu) */}
                  <div>
                    <div className="text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-1">{t('pwa_settings_app')}</div>
                    <div className="flex flex-col">
                      <AboutLinkButton icon={Bug} label={t('pwa_send_error_report')} onClick={() => { setErrorStatus('idle'); setErrorText(''); setSettingsView('errorReport'); }} />
                      <AboutLinkButton icon={BellRing} label={t('pwa_notifications')} onClick={() => { refreshNotifPerm(); setSettingsView('notifications'); }} />
                      {sensing && <AboutLinkButton icon={Activity} label={t('pwa_phone_sensing')} onClick={() => openSensing(true)} />}
                      <button onClick={updateStudies} disabled={updateStatus === 'updating'}
                        className="w-full flex items-center gap-3 px-2 py-3 rounded-xl hover:bg-surface-container-high dark:hover:bg-surface-container-highest transition-colors text-left disabled:opacity-70">
                        <span className="p-2 rounded-full bg-surface-container-high dark:bg-surface-container-highest text-on-surface-variant shrink-0"><Download size={18} aria-hidden="true" /></span>
                        <span className="flex-1 font-semibold text-sm text-on-surface">{t('pwa_update_studies')}</span>
                        {updateStatus === 'updating' && <RefreshCw size={16} className="animate-spin text-on-surface-variant shrink-0" aria-hidden="true" />}
                        {updateStatus === 'done' && updatePending === 0 && <span className="text-xs font-semibold text-green-600 dark:text-green-400 shrink-0">{t('pwa_updated')}</span>}
                        {(updateStatus === 'offline' || (updateStatus === 'done' && updatePending > 0)) && (
                          <span className="text-xs font-semibold text-amber-700 dark:text-amber-400 shrink-0 text-right">
                            {updateStatus === 'offline' ? t('pwa_offline') : t('pwa_updated')}{updatePending > 0 && ` · ${t('pwa_waiting_to_send', updatePending)}`}
                          </span>
                        )}
                        {updateStatus === 'error' && <span className="text-xs font-semibold text-red-600 dark:text-red-400 shrink-0">{t('pwa_failed')}</span>}
                        {updateStatus === 'idle' && <ChevronRight size={18} className="text-outline-variant shrink-0" aria-hidden="true" />}
                      </button>
                      <AboutLinkButton icon={Info} label={t('pwa_about_iemabot')} onClick={() => setSettingsView('about')} />
                    </div>
                  </div>
                </div>
              ) : settingsView === 'about' ? (
                <AboutEsmiraPanel serverVersion={serverVersion} />
              ) : settingsView === 'notifications' ? (
                <NotificationsPanel perm={notifPerm} onEnable={requestNotifications}
                  channelOn={!!study && channelEnabled(study, isNativeApp())} off={notifOff}
                  onSwitch={(off) => void changeNotificationsOff(off)}
                  onTest={() => { if (study) void sendTestPush(study); }}
                  testStatus={testPushStatus} canTest={!!study && !!vapidKey && !isNativeApp()}
                  onReportError={() => { setErrorStatus('idle'); setErrorText(''); setSettingsView('errorReport'); }} />
              ) : settingsView === 'sensing' && sensing ? (
                <PhoneSensingPanel
                  settings={sensing}
                  status={sensingStatus}
                  onAllowActivity={() => { void allowActivity(); }}
                  onOpenUsageAccess={() => { void openUsageAccessSettings(); }}
                  onWaitForWifi={(on) => { void changeWaitForWifi(on); }}
                />
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
                  sharedWithDevelopers={errorReportsToGithub}
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
            const ts = Number(joinedRaw);
            const d = Number.isFinite(ts) && ts > 0 ? new Date(ts) : new Date(joinedRaw);
            if (!Number.isNaN(d.getTime())) joinedAt = d;
          }
          const completedCount = study ? Number(localStorage.getItem(`esmira_completed_${study.id}_${userId}`) || '0') : 0;
          const detailTitle = aboutView === 'description' ? t('pwa_about_study_description')
            : aboutView === 'consent' ? t('pwa_about_informed_consent')
            : t('pwa_about_upload_protocol');
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
                      <button onClick={() => setAboutView('main')} aria-label={t('pwa_back')} className="min-w-11 min-h-11 -ml-2 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full shrink-0"><ChevronLeft size={20} aria-hidden="true" /></button>
                    )}
                    <h2 id="about-dialog-title" className="font-bold text-lg truncate">{aboutView === 'main' ? t('pwa_app_details') : detailTitle}</h2>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    {aboutView === 'protocol' && (
                      <button
                        onClick={() => { void flushSubmitQueue().then(() => setProtocolTick((t) => t + 1)); }}
                        aria-label={t('pwa_about_refresh_protocol')}
                        className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"
                      >
                        <RefreshCw size={18} aria-hidden="true" />
                      </button>
                    )}
                    <button onClick={() => setAboutOpen(false)} aria-label={t('pwa_app_close')} className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"><X size={20} aria-hidden="true" /></button>
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
                      <InfoRow label={t('pwa_about_user_id')} value={userId || '—'} mono />
                      <InfoRow label={t('pwa_about_server_url')} value={serverRootUrl()} mono />
                      <InfoRow label={t('pwa_about_joined_at')} value={joinedAt ? joinedAt.toLocaleString() : t('pwa_about_not_joined')} />
                      <InfoRow label={t('pwa_about_completed')} value={completedCount} />
                      <InfoRow label={t('pwa_about_next_notification')} value={
                        <InfoRowIconValue icon={Bell} text={
                          !study || !channelEnabled(study, isNativeApp())
                            ? t('pwa_about_notif_off_study')
                            : notifOff
                              ? t('pwa_about_notif_off_you')
                              : isNativeApp() && notifPerm === 'denied'
                                ? t('pwa_about_notif_blocked')
                                : nextNotification
                                  ? new Date(nextNotification).toLocaleString()
                                  : t('pwa_about_not_scheduled')
                        } />
                      } />
                      {study?.wearablesEnabled && (
                        <InfoRow label={t('pwa_app_wearables')} value={
                          <InfoRowIconValue icon={Watch} text={
                            wearableStatus.length > 0
                              ? wearableStatus.map((w) => WEARABLE_LABEL[w.provider] ?? w.provider).join(', ')
                              : t('pwa_about_none_connected')
                          } />
                        } />
                      )}
                    </div>
                    {/* Detail navigation */}
                    <div className="px-3 pb-4 pt-1 flex flex-col">
                      <AboutLinkButton icon={FileText} label={t('pwa_about_study_description')} onClick={() => setAboutView('description')} />
                      <AboutLinkButton icon={ShieldCheck} label={t('pwa_about_informed_consent')} onClick={() => setAboutView('consent')} />
                      <AboutLinkButton icon={UploadCloud} label={t('pwa_about_upload_protocol')} onClick={() => setAboutView('protocol')} />
                    </div>
                    <div className="px-5 pb-4 pt-1 text-center text-xs text-on-surface-variant border-t border-outline-variant/20">
                      {t('pwa_about_powered_by')}
                    </div>
                  </div>
                ) : aboutView === 'protocol' ? (
                  <div className="overflow-y-auto custom-scrollbar px-3 py-2">
                    {protocolEntries.length === 0 ? (
                      <p className="px-2 py-4 text-sm text-on-surface-variant">
                        {t('pwa_about_no_uploads')}
                      </p>
                    ) : (
                      <ul key={protocolTick} className="flex flex-col">
                        {protocolEntries.map((e) => <ProtocolRow key={e.id} entry={e} />)}
                      </ul>
                    )}
                    <p className="px-2 pt-3 pb-1 text-xs text-on-surface-variant">
                      {t('pwa_about_protocol_note')}
                    </p>
                  </div>
                ) : (
                  // tabIndex={0} so keyboard users can scroll this text-only region (WCAG 2.1.1).
                  <div className="p-5 overflow-y-auto custom-scrollbar focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary" tabIndex={0} role="region" aria-label={detailTitle}>
                    {detailContent
                      ? (aboutView === 'description'
                          // Rich text (HTML) — render formatted.
                          ? <div className={cn('leading-relaxed esmira-rich', textSizeClass)} dangerouslySetInnerHTML={{ __html: sanitizeHtml(detailContent) }} />
                          // Plain text — preserve line breaks exactly like the chat stream.
                          : <p className={cn('leading-relaxed whitespace-pre-wrap', textSizeClass)}>{detailContent}</p>)
                      : <p className="text-sm text-on-surface-variant">{t('pwa_about_not_provided')}</p>}
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
                <h2 id="contact-dialog-title" className="font-bold text-lg flex items-center gap-2"><MessageSquare size={20} aria-hidden="true" /> {t('pwa_contact_title')}</h2>
                <button onClick={() => setContactOpen(false)} aria-label={t('pwa_app_close')} className="min-w-11 min-h-11 inline-flex items-center justify-center hover:bg-surface-container-high rounded-full"><X size={20} aria-hidden="true" /></button>
              </div>
              <div className="p-5 flex flex-col gap-4 overflow-y-auto custom-scrollbar">
                {contactStatus === 'sent' ? (
                  <div className="flex flex-col items-center text-center gap-3 py-4">
                    <span className="p-3 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400"><CheckCircle size={28} aria-hidden="true" /></span>
                    <p className="font-semibold">{t('pwa_contact_sent_title')}</p>
                    <p className="text-sm text-on-surface-variant">{t('pwa_contact_sent_body')}</p>
                    <div className="flex gap-2 mt-2">
                      <button onClick={() => setContactStatus('idle')} className="px-4 py-2 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all">{t('pwa_app_send_another')}</button>
                      <button onClick={() => setContactOpen(false)} className="px-4 py-2 rounded-full bg-primary text-on-primary font-semibold text-sm active:scale-95 hover:brightness-110 transition-all">{t('pwa_app_close')}</button>
                    </div>
                  </div>
                ) : (
                  <>
                    <p className="text-sm text-on-surface-variant">
                      {/* The id sits in the sentence as a styled value: split the text where its %s is. */}
                      {(() => {
                        const [before, after = ''] = t('pwa_contact_intro', '%s').split('%s');
                        return <>{before}<span className="font-mono text-on-surface break-all">{userId || '—'}</span>{after}</>;
                      })()}
                    </p>
                    <textarea
                      value={contactText}
                      onChange={(e) => setContactText(e.target.value)}
                      rows={5}
                      disabled={contactStatus === 'sending'}
                      placeholder={t('pwa_contact_placeholder')}
                      aria-label={t('pwa_contact_label')}
                      className={cn('w-full resize-none rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:opacity-60', textSizeClass)}
                    />
                    {contactStatus === 'error' && (
                      <p className="text-sm text-red-600 dark:text-red-400">{t('pwa_contact_error')}</p>
                    )}
                    <button
                      onClick={sendContact}
                      disabled={contactText.trim().length < 2 || contactStatus === 'sending'}
                      className="w-full flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
                    >
                      {contactStatus === 'sending' ? t('pwa_app_sending') : <><Send size={18} aria-hidden="true" /> {t('pwa_contact_send')}</>}
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
                aria-label={t('pwa_app_close')}
              >
                <X size={18} aria-hidden="true" /> {t('pwa_app_close')}
              </button>
            </header>
            <iframe
              src={webview.url}
              title={webview.title}
              className="flex-1 w-full border-0"
              sandbox={TASK_IFRAME_SANDBOX}
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
            placeholder={t('pwa_survey_type_answer')}
            className={cn('w-full rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50', textSizeClass)}
          />
          <div className="flex gap-2">
            <button
              onClick={onBack}
              className="flex-1 py-3 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all hover:bg-surface-container-highest"
            >
              {t('pwa_back')}
            </button>
            <button
              onClick={submit}
              disabled={!trimmed}
              className="flex-1 py-3 rounded-full bg-primary text-on-primary font-bold text-sm active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
            >
              {t('pwa_app_done')}
            </button>
          </div>
        </div>
      </motion.div>
    </motion.div>
  );
}

/** A label / value row in the About · Study information panel. */
/** Values stay on one line and end in "…" when they don't fit; a plain-text value
 *  keeps its full text in the tooltip. */
function InfoRow({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  const full = typeof value === 'string' || typeof value === 'number' ? String(value) : undefined;
  return (
    <div className="flex items-center justify-between gap-4 py-2.5 border-b border-outline-variant/20 last:border-0">
      <span className="text-sm text-on-surface-variant shrink-0 whitespace-nowrap">{label}</span>
      <span title={full} className={cn('min-w-0 text-sm font-medium text-on-surface text-right truncate', mono && 'font-mono')}>{value}</span>
    </div>
  );
}

/** An icon + text value for an InfoRow that truncates the text, not the icon. */
function InfoRowIconValue({ icon: Icon, text }: { icon: React.ElementType; text: string }) {
  return (
    <span className="flex items-center justify-end gap-1 min-w-0 text-on-surface-variant" title={text}>
      <Icon size={13} aria-hidden="true" className="shrink-0" />
      <span className="truncate">{text}</span>
    </span>
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
  const failed = entry.status === 'failed';
  // Completed offline and sent later: show both times, so the delay is visible.
  const sentLater = sent && entry.sentAt != null && formatProtocolTime(entry.sentAt) !== formatProtocolTime(entry.time);
  return (
    <li className="flex items-start justify-between gap-3 px-2 py-3 border-b border-outline-variant/20 last:border-0">
      <div className="flex items-start gap-3 min-w-0">
        <span className={cn('p-2 rounded-full shrink-0',
          sent ? 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400'
            : failed ? 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400'
              : 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400')}>
          {sent ? <CheckCircle size={16} aria-hidden="true" /> : failed ? <XCircle size={16} aria-hidden="true" /> : <Clock size={16} aria-hidden="true" />}
        </span>
        <div className="min-w-0">
          <p className="font-semibold text-sm text-on-surface break-words">{entry.label}</p>
          {sentLater ? (
            <>
              <p className="text-xs text-on-surface-variant">{t('pwa_about_protocol_completed', formatProtocolTime(entry.time))}</p>
              <p className="text-xs text-on-surface-variant">{t('pwa_about_protocol_sent_at', formatProtocolTime(entry.sentAt!))}</p>
            </>
          ) : (
            <p className="text-xs text-on-surface-variant">{formatProtocolTime(entry.time)}</p>
          )}
        </div>
      </div>
      <span className={cn('text-xs font-semibold shrink-0 mt-1',
        sent ? 'text-green-700 dark:text-green-400'
          : failed ? 'text-red-700 dark:text-red-400'
            : 'text-amber-700 dark:text-amber-400')}>
        {sent ? t('pwa_about_protocol_sent') : failed ? t('pwa_about_protocol_not_accepted') : t('pwa_about_protocol_pending')}
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

/** About iEMAbot sub-panel: plain-language intro, version, server, and source links. */
function AboutEsmiraPanel({ serverVersion }: { serverVersion: number }) {
  const linkCls = 'inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline';
  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <img src={`${import.meta.env.BASE_URL}esmira-logo.svg`} alt="" className="w-12 h-12 shrink-0" />
        <div className="min-w-0">
          <p className="font-bold text-lg leading-none">iEMAbot</p>
          <p className="text-xs text-on-surface-variant mt-1">{t(isNativeApp() ? 'pwa_about_tagline_app' : 'pwa_about_tagline_web')}</p>
        </div>
      </div>
      <div className="flex flex-col gap-2 text-sm text-on-surface-variant leading-relaxed">
        <p>{t('pwa_about_intro')}</p>
        <p>{t('pwa_about_credits')}</p>
        {isMachineTranslated(getCurrentLang()) && <p className="italic">{t('pwa_machine_translated_note')}</p>}
      </div>
      <div>
        <InfoRow label={t('pwa_about_app_version')} value={APP_VERSION} />
        <InfoRow label={t('pwa_about_server_version')} value={serverVersion} />
        <InfoRow label={t('pwa_about_server')} value={serverRootUrl()} mono />
      </div>
      <div className="flex flex-col gap-2">
        <a href="https://github.com/andreifoldes/iEMAbot" target="_blank" rel="noreferrer noopener" className={linkCls}>
          <ExternalLink size={16} aria-hidden="true" /> {t('pwa_about_source_code')}
        </a>
        <a href="https://github.com/KL-Psychological-Methodology/ESMira-web" target="_blank" rel="noreferrer noopener" className={linkCls}>
          <ExternalLink size={16} aria-hidden="true" /> {t('pwa_about_esmira_source')}
        </a>
      </div>
    </div>
  );
}

/** Notifications troubleshooting sub-panel: current permission + guidance. */
function NotificationsPanel({ perm, onEnable, onTest, testStatus, canTest, channelOn, off, onSwitch, onReportError }: {
  perm: NotificationPermission | 'unsupported';
  onEnable: () => void;
  onTest: () => void;
  testStatus: 'idle' | 'sending' | 'sent' | 'local' | 'error';
  canTest: boolean;
  /** The research team sends notifications to this app for the study. */
  channelOn: boolean;
  /** The participant switched them off here. */
  off: boolean;
  onSwitch: (off: boolean) => void;
  /** Opens the "Send error report" panel. */
  onReportError: () => void;
}) {
  const native = isNativeApp();
  const reportItem = (
    <li>
      {t('pwa_notif_help_report_lead')}{' '}
      <button type="button" onClick={onReportError}
        className="font-semibold text-primary underline underline-offset-2 hover:brightness-110">{t('pwa_send_error_report')}</button>
      {' '}{t('pwa_notif_help_report_tail')}
    </li>
  );
  const status = perm === 'granted'
    ? { icon: <CheckCircle size={18} aria-hidden="true" />, text: t('pwa_notif_status_enabled'), cls: 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400' }
    : perm === 'denied'
      ? { icon: <BellOff size={18} aria-hidden="true" />, text: native ? t('pwa_notif_status_blocked_app') : t('pwa_notif_status_blocked_site'), cls: 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400' }
      : perm === 'unsupported'
        ? { icon: <Bell size={18} aria-hidden="true" />, text: native ? t('pwa_notif_status_unsupported_app') : t('pwa_notif_status_unsupported_web'), cls: 'bg-surface-container-high text-on-surface-variant' }
        : { icon: <Bell size={18} aria-hidden="true" />, text: t('pwa_notif_status_not_enabled'), cls: 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400' };
  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      {channelOn ? (
        <div className="flex flex-col gap-1">
          <Toggle icon={<Bell size={18} aria-hidden="true" />} label={t('pwa_notif_switch_label')} on={!off} onClick={() => onSwitch(!off)} />
          <p className="text-xs text-on-surface-variant" aria-live="polite">
            {off
              ? t('pwa_notif_switch_off')
              : t('pwa_notif_switch_on')}
          </p>
        </div>
      ) : (
        <p className="text-sm text-on-surface-variant">{t('pwa_notif_channel_off')}</p>
      )}
      {channelOn && !off && (
      <div className="flex items-center gap-3">
        <span className={cn('p-2 rounded-full shrink-0', status.cls)}>{status.icon}</span>
        <p className="text-sm font-semibold">{status.text}</p>
      </div>
      )}
      {channelOn && !off && perm === 'default' && (
        <button onClick={onEnable}
          className="self-start inline-flex items-center gap-2 bg-primary text-on-primary font-bold px-4 py-2.5 rounded-full active:scale-95 hover:brightness-110 transition-all">
          <BellRing size={16} aria-hidden="true" /> {t('pwa_onboard_enable_notifications')}
        </button>
      )}
      {channelOn && !off && perm === 'denied' && (
        <p className="text-sm text-on-surface-variant">
          {native
            ? t('pwa_notif_blocked_help_app')
            : t('pwa_notif_blocked_help_web')}
        </p>
      )}
      {channelOn && !off && perm === 'granted' && canTest && (
        <div className="flex flex-col gap-1.5" aria-live="polite">
          <button onClick={onTest} disabled={testStatus === 'sending'}
            className="self-start inline-flex items-center gap-2 bg-primary text-on-primary font-bold px-4 py-2.5 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-60">
            <BellRing size={16} aria-hidden="true" /> {testStatus === 'sending' ? t('pwa_app_sending') : t('pwa_notif_send_test')}
          </button>
          {testStatus === 'sent' && <p className="text-sm text-green-600 dark:text-green-400">{t('pwa_notif_test_sent')}</p>}
          {testStatus === 'local' && <p className="text-sm text-on-surface-variant">{t('pwa_notif_test_local')}</p>}
          {testStatus === 'error' && <p className="text-sm text-red-600 dark:text-red-400">{t('pwa_notif_test_error')}</p>}
        </div>
      )}
      <div>
        <p className="font-semibold text-sm mb-2">{t('pwa_notif_troubleshoot_title')}</p>
        {isNativeApp() ? (
          <ul className="list-disc pl-5 text-sm text-on-surface-variant space-y-1.5">
            <li>{t('pwa_notif_help_app_1')}</li>
            <li>{t('pwa_notif_help_app_2')}</li>
            <li>{t('pwa_notif_help_app_3')}</li>
            <li>{t('pwa_notif_help_app_4')}</li>
            {reportItem}
          </ul>
        ) : (
          <ul className="list-disc pl-5 text-sm text-on-surface-variant space-y-1.5">
            <li>{t('pwa_notif_help_web_1')}</li>
            <li>{t('pwa_notif_help_web_2')}</li>
            <li>{t('pwa_notif_help_web_3')}</li>
            <li>{t('pwa_notif_help_web_4')}</li>
            <li>{t('pwa_notif_help_web_5')}</li>
            {reportItem}
          </ul>
        )}
      </div>
      {!isNativeApp() && (
        <p className="text-xs text-on-surface-variant">
          {t('pwa_notif_android_hint')}
        </p>
      )}
    </div>
  );
}

/** Send error report sub-panel: optional note + a transparent diagnostics preview. */
function ErrorReportPanel({ sharedWithDevelopers, canSend, text, status, preview, textSizeClass, onChange, onSend, onReset, onClose }: {
  /** The server also passes the report on to the developers on GitHub: say so before it is sent. */
  sharedWithDevelopers: boolean;
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
        <p className="font-semibold">{t('pwa_error_report_sent')}</p>
        <p className="text-sm text-on-surface-variant">{t('pwa_error_report_sent_body')}</p>
        <div className="flex gap-2 mt-2">
          <button onClick={onReset} className="px-4 py-2 rounded-full bg-surface-container-high text-on-surface font-semibold text-sm active:scale-95 transition-all">{t('pwa_app_send_another')}</button>
          <button onClick={onClose} className="px-4 py-2 rounded-full bg-primary text-on-primary font-semibold text-sm active:scale-95 hover:brightness-110 transition-all">{t('pwa_app_close')}</button>
        </div>
      </div>
    );
  }
  return (
    <div className="p-5 flex flex-col gap-4 overflow-y-auto custom-scrollbar">
      <p className="text-sm text-on-surface-variant">
        {t('pwa_error_report_intro')}
      </p>
      {sharedWithDevelopers && <p className="text-sm text-on-surface-variant">{t('pwa_error_report_github')}</p>}
      <textarea
        value={text}
        onChange={(e) => onChange(e.target.value)}
        rows={4}
        disabled={status === 'sending'}
        placeholder={t('pwa_error_report_placeholder')}
        aria-label={t('pwa_error_report_label')}
        className={cn('w-full resize-none rounded-xl bg-surface-container-high px-4 py-3 text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:opacity-60', textSizeClass)}
      />
      <details className="text-sm">
        <summary className="cursor-pointer font-semibold text-on-surface-variant select-none">{t('pwa_error_report_included')}</summary>
        <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-container-high p-3 text-xs text-on-surface-variant font-mono">{preview}</pre>
      </details>
      {!canSend && <p className="text-sm text-amber-600 dark:text-amber-400">{t('pwa_error_report_no_study')}</p>}
      {status === 'error' && <p className="text-sm text-red-600 dark:text-red-400">{t('pwa_error_report_failed')}</p>}
      <button
        onClick={onSend}
        disabled={!canSend || status === 'sending'}
        className="w-full flex items-center justify-center gap-2 bg-primary text-on-primary font-bold py-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100"
      >
        {status === 'sending' ? t('pwa_app_sending') : <><Bug size={18} aria-hidden="true" /> {t('pwa_error_report_send')}</>}
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
