/**
 * "Who may join" study setting: which participant id this device uses.
 *
 * A study is open (`pidMode` "open", the default) or restricted to a list the researcher made (`pidMode` "list").
 * An open study works as it always did: the id comes from the invite link, else the one stored here, else a random one.
 * In a restricted study the id has to be on the list, so the app never makes one up: it takes the id from the link
 * (unless the researcher turned `pidFromUrl` off), else the one it stored after the server accepted it, else it asks
 * the participant to type it. Nothing is stored or sent for an id until the server has said it is allowed
 * (api/check_pid.php). The server refuses unlisted ids on its own as well, so this is the friendly part, not the guard.
 */

export type PidMode = 'open' | 'list';

export interface PidSettings {
  mode: PidMode;
  /** Whether a `pid` in the invite link is used (otherwise the participant always types it). */
  fromUrl: boolean;
}

export interface PidSettingsSource {
  pidMode?: string;
  pidFromUrl?: boolean;
  webLocalOnly?: boolean;
}

/** A local-only study sends nothing to the server, so there is nothing to restrict. */
export function pidSettings(study: PidSettingsSource): PidSettings {
  return {
    mode: study.pidMode === 'list' && study.webLocalOnly !== true ? 'list' : 'open',
    fromUrl: study.pidFromUrl !== false,
  };
}

export type PidDecision =
  | { kind: 'open' }
  /** Ask the participant to type their id. */
  | { kind: 'prompt' }
  /** Use this id. `check` is false when the server accepted this very id on this device before. */
  | { kind: 'use'; pid: string; source: 'url' | 'stored'; check: boolean };

/**
 * @param urlPid the id the invite link carries ('' if none)
 * @param stored the id stored on this device for the study ('' if none)
 * @param validated the id the server last accepted on this device ('' if none)
 */
export function decidePid(settings: PidSettings, urlPid: string, stored: string, validated: string): PidDecision {
  if (settings.mode === 'open') return { kind: 'open' };
  const fromLink = settings.fromUrl ? urlPid.trim() : '';
  if (fromLink) return { kind: 'use', pid: fromLink, source: 'url', check: fromLink !== validated };
  const kept = stored.trim();
  if (kept) return { kind: 'use', pid: kept, source: 'stored', check: kept !== validated };
  return { kind: 'prompt' };
}

/** Where the study's participant id is kept on this device (the same key an open study uses). */
export function participantIdKey(studyId: number | string): string {
  return `esmira_userid_${studyId}`;
}

/** The id the server accepted for this study on this device: a returning participant is not asked again, even offline. */
export function acceptedPidKey(studyId: number | string): string {
  return `esmira_pidok_${studyId}`;
}

const PID_PARAMS = ['pid', 'uid', 'user_id', 'userId'];

/** The address without the participant id parameters, so a typed id is not overruled by the link's on reload. */
export function withoutPidParams(href: string): string {
  const url = new URL(href);
  for (const name of PID_PARAMS) url.searchParams.delete(name);
  return url.toString();
}
