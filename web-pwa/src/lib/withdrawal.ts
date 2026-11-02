/**
 * Leaving a study. Signing out (and a researcher withdrawing a participant) must stop every
 * reminder: the server's web push, the Android app's own alarms and its background poll. The
 * server learns about it from a `quit` dataset (the same event ESMira's native apps send), which
 * marks the participant as withdrawn so nothing is sent to them any more. The pure parts live
 * here so they can be unit-tested; the network calls are in esmiraApi.ts.
 */

import { clientAppType } from './platform.ts';

export interface QuitPayloadArgs {
  studyId: number;
  studyVersion?: number;
  studySubVersion?: number;
  studyLang?: string;
  serverVersion: number;
  accessKey: string;
  userId: string;
  /** Device description recorded with the event (navigator.userAgent in the app). */
  model: string;
  /** When the participant left (epoch ms). */
  now: number;
}

/**
 * The `quit` dataset, shaped like the `joined` event the app already sends, so the server's
 * CreateDataSet records it in events.csv and withdraws the participant.
 */
export function buildQuitPayload(args: QuitPayloadArgs) {
  return {
    userId: args.userId,
    appType: clientAppType(),
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset: [{
      studyId: args.studyId,
      studyVersion: args.studyVersion ?? 0,
      studySubVersion: args.studySubVersion ?? 0,
      studyLang: args.studyLang ?? 'en',
      accessKey: args.accessKey,
      dataSetId: 0,
      questionnaireName: null,
      questionnaireInternalId: null,
      eventType: 'quit' as const,
      responseTime: args.now,
      responses: { model: args.model },
    }],
  };
}

/**
 * Resolve to `fallback` if `promise` has not settled within `ms`. Sign-out must never hang on a
 * slow or absent connection: the server calls are best-effort, the local reset always happens.
 */
export function withTimeout<T>(promise: Promise<T>, ms: number, fallback: T): Promise<T> {
  return new Promise<T>((resolve) => {
    const timer = setTimeout(() => resolve(fallback), ms);
    promise.then(
      (v) => { clearTimeout(timer); resolve(v); },
      () => { clearTimeout(timer); resolve(fallback); },
    );
  });
}

/** What the server's participant status says about this participant. */
export interface ParticipantStatus {
  withdrawn: boolean;
}

/**
 * Read the `withdrawn` flag out of a push_status.php envelope. Only an explicit `true` counts:
 * an older server (no flag), an error envelope or a network failure must never reset a device.
 */
export function withdrawnFromStatus(envelope: unknown): boolean {
  if (!envelope || typeof envelope !== 'object') return false;
  const env = envelope as { success?: unknown; dataset?: { withdrawn?: unknown } | null };
  return env.success === true && env.dataset?.withdrawn === true;
}
