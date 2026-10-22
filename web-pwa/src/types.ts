// ── Engine-facing question/session types ────────────────────────
// (Consumed by surveyEngine.ts, ported from the iEMAbot PWA.)

export interface ShowIf {
  question_id: string;
  operator: 'equals' | 'not_equals' | 'gte';
  value: string;
}

/** Internal question type the chat UI renders. */
export type QType =
  | 'likert'
  | 'choice'
  | 'multi_choice'
  | 'yesno'
  | 'text'
  | 'number'
  | 'time'
  | 'duration'
  | 'date'
  | 'va_scale'
  | 'audio'
  | 'keystroke_text'
  | 'cognitive'
  | 'info';

export interface PreloadedQuestion {
  id: string; // == ESMira input `name` (unique study-wide → response key)
  text: string; // question text — authored in the rich-text editor, so may contain HTML
  // Optional explanatory helper text shown beneath `text`, de-emphasised (muted, regular
  // weight). Rich HTML like `text`. Distinct from `description` below (cognitive cards).
  subtext?: string;
  // Researcher-supplied accessibility content (see EsmiraInput).
  aria_label?: string; // announced instead of the visible question text
  spoken_text?: string; // enables the "Listen" button
  type: QType;
  required: boolean;
  options?: string[] | null;
  // likert / va_scale
  scale_min?: number;
  scale_max?: number;
  scale_min_label?: string;
  scale_max_label?: string;
  max_value?: number; // va_scale upper bound (default 100)
  // yesno (ESMira `binary`: left=value0/label, right=value1/label)
  no_label?: string;
  no_value?: string;
  yes_label?: string;
  yes_value?: string;
  // render `text` as raw HTML (set on every question — text is rich-text-authored)
  is_html?: boolean;
  // cognitive/link-out tasks: launch this URL in an in-app iframe overlay
  title?: string;
  description?: string;
  launch_url?: string;
  launch_label?: string;
  // number input hint
  decimal?: boolean;
  // duration input constraints
  max_hours?: number;   // upper bound for the hours wheel (default 24)
  minute_step?: number; // step between minute options (default 1)
  // audio (record_audio): max recording length in seconds (default 300 = 5 min)
  max_recording_seconds?: number;
  show_if?: ShowIf | null;
  other_specify?: { options: string[]; prompt: string } | null;
  // Skip-fallback branch (audio → keystroke_text): set on an `audio` question, the id of the
  // adjacent `keystroke_text` question to reveal when the memo is skipped.
  skip_fallback_id?: string;
  // Set on a `keystroke_text` question that is a skip fallback: hidden in normal flow, revealed
  // only when its paired audio question is skipped (surveyEngine.activateFallback).
  is_fallback?: boolean;
  // keystroke_text: soft minimum typed length in characters. Folded into the writing
  // nudge (progress bar completes only once reached) — never blocks Save.
  min_length?: number;
}

export interface PreloadedSection {
  id: string;
  name: string;
  description: string;
  start: number;
  length: number;
  allow_change_all: boolean;
}

export interface PreloadedSession {
  session_id: string;
  survey_id: string;
  survey_name: string;
  survey_description: string;
  questions: PreloadedQuestion[];
  sections: PreloadedSection[] | null;
  question_order: number[] | null;
  allow_response_editing: boolean;
  allow_change_all: boolean;
  answered: Record<string, string> | null;
  current_question_index: number;
}

// ── Raw ESMira study JSON (from api/studies.php) ─────────────────

export type EsmiraResponseType =
  | 'binary'
  | 'date'
  | 'duration'
  | 'dynamic_input'
  | 'image'
  | 'likert'
  | 'list_multiple'
  | 'list_single'
  | 'number'
  | 'photo'
  | 'record_audio'
  | 'record_keystrokes'
  | 'text'
  | 'text_input'
  | 'time'
  | 'va_scale'
  | 'video'
  | 'webapp'
  | string;

export interface EsmiraInput {
  name: string;
  responseType: EsmiraResponseType;
  text?: string;
  /** Optional explanatory text shown beneath the question (rich HTML, web/PWA only). */
  description?: string;
  /** "image" type: text alternative for the picture (web/PWA only). */
  imageAlt?: string;
  /** Read by screen readers in place of the question text (web/PWA only). */
  screenReaderLabel?: string;
  /** Read aloud by the question's "Listen" button (web/PWA only). */
  spokenText?: string;
  /**
   * ESMira relevance condition — the input only shows when it holds. Native apps
   * evaluate this as a full Merlin script; this PWA supports the single-comparison
   * subset parsed by esmiraAdapter.parseRelevance (e.g. `eveningNapCount != 0`).
   */
  relevance?: string;
  required?: boolean;
  likertSteps?: number;
  leftSideLabel?: string;
  rightSideLabel?: string;
  listChoices?: string[];
  maxValue?: number;
  numberHasDecimal?: boolean;
  other?: boolean;
  url?: string;
  /** "webapp" type: instructions shown on the launch card (the item `text` is the title). */
  webappDescription?: string;
  /** "record_audio" type: max recording length in seconds (falls back to 300 = 5 min). */
  maxLength?: number;
  /** "record_keystrokes" type: soft minimum typed length in characters (nudge only). */
  minLength?: number;
}

export interface EsmiraPage {
  header?: string;
  inputs: EsmiraInput[];
  randomized?: boolean;
}

/** A single notification time within a schedule (ms since local midnight). */
export interface EsmiraSignalTime {
  startTimeOfDay: number;
  endTimeOfDay?: number;
  random?: boolean;
  frequency?: number;
}

/** Recurrence for a set of signal times. */
export interface EsmiraSchedule {
  dailyRepeatRate?: number; // 1 = every day, 2 = every other day, …
  weekdays?: number;        // bitfield, bit0=Sun … bit6=Sat; 0 = every weekday
  dayOfMonth?: number;      // 1–31, 0 = any
  skipFirstInLoop?: boolean;
  startDayOne?: boolean;
  signalTimes?: EsmiraSignalTime[];
}

/** A trigger's action. Only the notifying kinds matter to the participant app (type 1 = invitation, 3 = reminder-style; a missing `type` means 1). */
export interface EsmiraAction {
  type?: number;
  /** Notification text written by the study author. */
  msgText?: string;
}

export interface EsmiraActionTrigger {
  schedules?: EsmiraSchedule[];
  actions?: EsmiraAction[];
}

export interface EsmiraQuestionnaire {
  internalId: number;
  title: string;
  pages: EsmiraPage[];
  // ── Scheduling / availability (served verbatim by api/studies.php) ──
  /** Activation delay: not available until this many days after joining (0 = immediately). */
  durationStartingAfterDays?: number;
  /** Active for this many days after joining (0 = no expiry). */
  durationPeriodDays?: number;
  /** Absolute availability window (epoch ms); 0 = unbounded. */
  durationStart?: number;
  durationEnd?: number;
  /** Completion constraints. */
  completableOnce?: boolean;
  /** At most one completion per local calendar day (any signal count / passive). */
  completableOncePerDay?: boolean;
  completableOncePerNotification?: boolean;
  completableMinutesAfterNotification?: number;
  completableAtSpecificTime?: boolean;
  completableAtSpecificTimeStart?: number; // ms since midnight, -1 = off
  completableAtSpecificTimeEnd?: number;
  /** Web push only: include a "Complete by HH:MM" deadline in the reminder body. */
  notificationIncludeDeadline?: boolean;
  limitCompletionFrequency?: boolean;
  completionFrequencyMinutes?: number;
  actionTriggers?: EsmiraActionTrigger[];
  /** Which answered items show a "Change response" affordance in the chat flow:
   *  'previous' (only the last, default), 'any' (every prior item), or 'none'
   *  (disabled — e.g. cognitive tasks, which must not be re-attempted). */
  changeResponseMode?: 'previous' | 'any' | 'none';
}

export interface EsmiraStudy {
  id: number;
  version?: number;
  subVersion?: number;
  lang?: string;
  title: string;
  studyDescription?: string;
  informedConsentForm?: string;
  postInstallInstructions?: string;
  accessKeys?: string[];
  /** When true (set by the researcher in the designer), first-time web participants
   *  see a tutorial overview with optional no-submit practice runs before starting. */
  enableTutorialMode?: boolean;
  /** Researcher-customised tutorial prompts (empty = the PWA's built-in defaults). */
  tutorialOffer?: string;
  tutorialIntro?: string;
  /** When true, the researcher has enabled server-sent web push reminders for PWA
   *  participants. The client requests notification permission after consent and
   *  registers a push subscription; the server schedules/sends the reminders. */
  webPushEnabled?: boolean;
  /** When true, the researcher restricted the web app to smartphones. Other devices see
   *  an invite page with a QR code instead of the study (see lib/phoneGate.ts). */
  webPhoneOnly?: boolean;
  /** When true, the app skips the "what name would you like to go by?" step, so the
   *  participant stays identified only by their generated study id. */
  webSkipName?: boolean;
  /** Names an end-of-questionnaire read-out of the participant's own answers (see
   *  lib/snapshot.ts). Source-edit only; empty/unknown = none. */
  webSnapshot?: string;
  /** When true, the app sends nothing to the server: no answers, join or other events,
   *  telemetry, push or PID lock (see `setLocalOnly` in lib/esmiraApi.ts). Source-edit only. */
  webLocalOnly?: boolean;
  /** When true, a cognitive task card offers Start task / Skip task / Remind me later / Skip
   *  check-in (see lib/pausedCheckin.ts) instead of just Start and Skip. Source-edit only. */
  webTaskActions?: boolean;
  /** When true, the welcome, description and consent bubbles of a first visit, and the closing
   *  bubbles after a check-in, appear one by one, each after the previous one's reading time
   *  (see lib/pacing.ts). Source-edit only. */
  webPacedMessages?: boolean;
  /** When true, the researcher has enabled wearable data sharing. The PWA offers
   *  the intersection of `wearablesProviders` and the server's `wearableProviders`
   *  (those with OAuth credentials configured) as connectable devices. */
  wearablesEnabled?: boolean;
  /** Provider slugs the researcher offers for this study (e.g. ["withings","googlehealth"]). */
  wearablesProviders?: string[];
  questionnaires: EsmiraQuestionnaire[];
}

export interface StudiesEnvelope {
  success: boolean;
  serverVersion: number;
  dataset: EsmiraStudy[];
  error?: string;
  /** Base64url VAPID public key, present only when a study in the set has web push
   *  enabled. The client passes it to `pushManager.subscribe(applicationServerKey)`. */
  vapidPublicKey?: string;
  /** Wearable providers with OAuth credentials configured on the server. The PWA only
   *  offers those that are also listed in a study's `wearablesProviders`. */
  wearableProviders?: string[];
}

/** A connected wearable as reported by api/wearables_status.php. */
export interface WearableStatus {
  provider: string;
  /** UTC ms of the last day the server synced for this provider, or null if none yet. */
  lastSync: number | null;
  /** The provider rejected the stored link (revoked, or replaced by linking the same account again). */
  needsReconnect?: boolean;
}
