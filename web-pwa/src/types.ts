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
  // Optional call to action shown after `subtext`, on its own line in regular text colour
  // (e.g. "Count all times from…"). Rich HTML like `text`.
  instruction?: string;
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
  /** Optional call to action shown after the description (rich HTML, web/PWA only). */
  instruction?: string;
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

export interface EsmiraQuestionnaire {
  internalId: number;
  title: string;
  pages: EsmiraPage[];
  /** ESMira action triggers that are not scheduling (in-app messages, other cues); never read by the app. */
  actionTriggers?: unknown[];
  /** Which answered items show a "Change response" affordance in the chat flow:
   *  'previous' (only the last, default), 'any' (every prior item), or 'none'
   *  (disabled — e.g. cognitive tasks, which must not be re-attempted). */
  changeResponseMode?: 'previous' | 'any' | 'none';
  /** Offered as a practice run in the tutorial overview (default true; false leaves it out). */
  includeInTutorial?: boolean;
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
  /** For studies with a voice-recording item: false = do not ask for the microphone during
   *  onboarding, the browser asks when the recorder first opens. Absent/true = onboarding
   *  explains why the microphone is needed and then asks (see lib/permissions.ts). */
  webMicOnboarding?: boolean;
  /** false = do not remind participants once a day to turn notifications on while they are off
   *  (web and Android app; absent = on, see lib/notificationNudge.ts). */
  notificationNudge?: boolean;
  /** Research team's switch for the Android app's notifications (absent = on). */
  androidNotificationsEnabled?: boolean;
  /** The study's one schedule: which check-ins open when and when they are prompted, on every channel
   *  (see lib/schedule.ts and backend/schedule/Schedule.php). Defaults are omitted. */
  schedule?: ScheduleConfig;
  /** When true, the researcher restricted the web app to smartphones. Other devices see
   *  an invite page with a QR code instead of the study (see lib/phoneGate.ts). */
  webPhoneOnly?: boolean;
  /** Names an end-of-questionnaire read-out of the participant's own answers (see
   *  lib/snapshot.ts). Source-edit only; empty/unknown = none. */
  webSnapshot?: string;
  /** When true, the app ends on its thank-you after a questionnaire is submitted: no "Would you
   *  like to complete another questionnaire?" prompt and no list. Source-edit only. */
  webSingleRun?: boolean;
  /** When true, the app sends nothing to the server: no answers, join or other events,
   *  telemetry, push or PID lock (see `setLocalOnly` in lib/esmiraApi.ts). Source-edit only. */
  webLocalOnly?: boolean;
  /** When true, a cognitive task card offers Start task / Skip task / Remind me later / Skip
   *  check-in (see lib/pausedCheckin.ts) instead of just Start and Skip. Source-edit only. */
  webTaskActions?: boolean;
  /** Reading speed (words per minute) at which consecutive bot messages are paced, in every
   *  channel (see lib/pacing.ts). Default 238; 0 = no pacing. Set in the study settings. */
  messagePacingWpm?: number;
  /** When true, the researcher has enabled wearable data sharing. The PWA offers
   *  the intersection of `wearablesProviders` and the server's `wearableProviders`
   *  (those with OAuth credentials configured) as connectable devices. */
  wearablesEnabled?: boolean;
  /** Provider slugs the researcher offers for this study (e.g. ["withings","googlehealth"]). */
  wearablesProviders?: string[];
  /** Phone sensing in the Android app (see lib/phoneSensing.ts): ambient light at each prompt, Google
   *  activity recognition, raw accelerometer around prompts (minutes before / after, samples per second)
   *  and app usage ('' off, 'daily', 'events' or 'both'). Omitted when off / at the default. */
  phoneSensingLight?: boolean;
  phoneSensingActivity?: boolean;
  phoneSensingAccelerometer?: boolean;
  phoneSensingAccelBeforeMin?: number;
  phoneSensingAccelAfterMin?: number;
  phoneSensingAccelHz?: number;
  phoneSensingUsage?: string;
  /** When sensed data is uploaded: 'any', 'wifi_preferred' (default; mobile data once it has waited
   *  phoneSensingWifiFallbackHours, default 24) or 'wifi_only'. phoneSensingParticipantNetwork (default
   *  true): the participant may switch between 'any' and 'wifi_preferred' in the app. */
  phoneSensingUploadNetwork?: string;
  phoneSensingWifiFallbackHours?: number;
  phoneSensingParticipantNetwork?: boolean;
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

type BoundaryType = 'fixed' | 'wake' | 'bed' | 'split' | 'usualWake';

/** One check-in of the study's schedule (see lib/schedule.ts and backend/schedule/Schedule.php). Defaults are omitted. */
export interface ScheduleCheckin {
  questionnaireId?: number;
  // days
  dayFrom?: number;
  dayUntil?: number;
  everyDays?: number;
  weekdays?: number;
  dayOfMonth?: number;
  dateFrom?: number;
  dateUntil?: number;
  // range: a clock time (`fixed`), wake-up / usual wake-up / bedtime plus an offset, or point k of the waking day (`split`)
  startType?: BoundaryType;
  startTime?: number;
  startOffsetMin?: number;
  startSegment?: number;
  startNextDay?: boolean;
  endType?: BoundaryType;
  endTime?: number;
  endOffsetMin?: number;
  endSegment?: number;
  endNextDay?: boolean;
  /** `start` (default), `random` within the range, `wake`, `event` (a wearable event, else its fallback) or `none`. */
  prompt?: string;
  promptGraceMin?: number;
  eventProvider?: string;
  eventKind?: string;
  eventFrom?: number;
  eventUntil?: number;
  eventDelayMin?: number;
  eventDelayMaxMin?: number;
  fallback?: string;
  fallbackTime?: number;
  fallbackVariable?: string;
  fallbackOffsetMin?: number;
  fallbackDefault?: boolean;
  /** When it can be answered: `prompt` (default) for the response window, `range` throughout its range, `day` until the study day ends. */
  available?: string;
  responseOverride?: boolean;
  responseMinutes?: number;
  /** `window` (default), `day`, `once` or `none`. */
  limit?: string;
  minGapMinutes?: number;
  /** Open alongside the other check-ins (no part in one-at-a-time and the cool-down). */
  parallel?: boolean;
  /** The app starts it by itself when the participant reaches the study while it is open. */
  autoStart?: boolean;
  title?: string;
  text?: string;
  reminderCount?: number;
  reminderDelayMin?: number;
  includeDeadline?: boolean;
}

export interface ScheduleConfig {
  /** When a study day starts, ms after midnight (e.g. 04:00). */
  dayStart?: number;
  segments?: number;
  responseMinutes?: number;
  /** Overlapping check-ins: 'defer' (default) the next one until the previous window closes, or 'truncate' that window. */
  overlap?: string;
  /** Minutes after completing a check-in before another one opens; 0 = off. */
  cooldownMinutes?: number;
  wakeSensor?: boolean;
  /** The first check-in can be answered from the start of the wake-up window; answering it marks wake-up. */
  wakeFromCheckin?: boolean;
  wakeProvider?: string;
  wakeEvent?: string;
  wakeWindowStart?: number;
  wakeWindowEnd?: number;
  wakeVariable?: string;
  /** Tier 1 on Saturdays and Sundays (e.g. habitual_wake_time_weekend); empty = wakeVariable. */
  wakeVariableWeekend?: string;
  wakeOffsetMin?: number;
  wakeDefault?: number;
  /** Going to bed from a wearable event (e.g. Withings `bed_in`) inside the bed window. */
  bedSensor?: boolean;
  bedProvider?: string;
  bedEvent?: string;
  bedWindowStart?: number;
  bedWindowEnd?: number;
  bedVariable?: string;
  /** Tier 1 on Friday and Saturday nights (e.g. habitual_bedtime_weekend); empty = bedVariable. */
  bedVariableWeekend?: string;
  bedOffsetMin?: number;
  bedDefault?: number;
  checkins?: ScheduleCheckin[];
}
