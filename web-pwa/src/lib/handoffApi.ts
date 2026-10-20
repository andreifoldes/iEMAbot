/**
 * Client of api/channel_handoff.php — the chat-channel web handoff (see
 * src/backend/channels/handoff/HandoffService.php). A chat (Telegram) sends a link with a
 * signed token for one item it cannot render; this page fetches that item and posts the
 * participant's answer back, after which the chat continues.
 */
import type { EsmiraInput } from '../types';
import type { TrialRow } from './cognitiveTrials';

const API_ROOT = (import.meta.env.VITE_API_ROOT as string | undefined) || '/esmira/';

export interface HandoffItem {
  studyId: number;
  questionnaireName: string;
  kind: 'va_scale' | 'keystroke' | 'cognitive';
  input: EsmiraInput;
  channel: string;
}

export interface HandoffResult {
  value?: string;
  keystrokeText?: string;
  captureMode?: string;
  /** keystroke-dynamics CSV (content free), stored as the item's file */
  keystrokeLog?: string;
  trials?: TrialRow[];
  skip?: boolean;
}

async function call<T>(init?: RequestInit, query = ''): Promise<T> {
  const resp = await fetch(`${API_ROOT}api/channel_handoff.php${query}`, { cache: 'no-store', ...init });
  const json = await resp.json() as { success: boolean; dataset?: T; error?: string };
  if (!json.success) throw new Error(json.error || 'Request failed');
  return json.dataset as T;
}

export function fetchHandoff(token: string): Promise<HandoffItem> {
  return call<HandoffItem>(undefined, `?t=${encodeURIComponent(token)}`);
}

export async function submitHandoff(token: string, result: HandoffResult): Promise<void> {
  await call<unknown>({
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ t: token, ...result }),
  });
}
