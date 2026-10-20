/**
 * Completion mirror — a small IndexedDB copy of the localStorage completion log
 * (`esmira_qcompletions_*`, see availability.ts) so the SERVICE WORKER, which cannot
 * read localStorage, can suppress reminders for questionnaires the participant has
 * already completed.
 *
 * localStorage stays the source of truth. This is a write-through read cache: the app
 * mirrors each completion here as it happens, and backfills the whole log on startup
 * for participants who completed surveys before this cache existed. The read path
 * (`getCompletion`) touches IndexedDB only, so it is safe to call inside the SW.
 */

const DB_NAME = 'esmira_state';
const STORE = 'completions';

export interface CompletionMirror {
  /** `${studyId}:${userId}:${qid}` — the object store keyPath. */
  key: string;
  /** Last completion time (epoch ms). */
  lastAt: number;
  /** Total completions of this questionnaire. */
  count: number;
  /** Per-occurrence completion times, keyed by window key `${dayIndex}:${startTimeOfDay}`
   *  (see availability.ts). Lets the SW suppress a completed once-per-notification signal. */
  occ?: Record<string, number>;
}

function keyOf(studyId: number, userId: string, qid: number): string {
  return `${studyId}:${userId}:${qid}`;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    // v2 adds the 'shown' store used by the service worker's display ledger (see sw.ts).
    // Both openers must agree on the version + create any missing stores in the upgrade.
    const req = indexedDB.open(DB_NAME, 2);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'key' });
      if (!db.objectStoreNames.contains('shown')) db.createObjectStore('shown', { keyPath: 'key' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('IndexedDB open failed'));
  });
}

async function tx<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb();
  return new Promise<T>((resolve, reject) => {
    const t = db.transaction(STORE, mode);
    const req = run(t.objectStore(STORE));
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('IndexedDB request failed'));
    t.oncomplete = () => db.close();
  });
}

/** Read one questionnaire's mirrored completion. SW-safe (IndexedDB only, no DOM). */
export async function getCompletion(studyId: number, userId: string, qid: number): Promise<CompletionMirror | null> {
  try {
    const rec = await tx<CompletionMirror | undefined>(
      'readonly',
      (s) => s.get(keyOf(studyId, userId, qid)) as IDBRequest<CompletionMirror | undefined>,
    );
    return rec ?? null;
  } catch {
    return null;
  }
}

/** Write-through: mirror one questionnaire's completion state. Best-effort. */
export async function mirrorCompletion(
  studyId: number,
  userId: string,
  qid: number,
  lastAt: number,
  count: number,
  occ?: Record<string, number>,
): Promise<void> {
  try {
    await tx('readwrite', (s) => s.put({ key: keyOf(studyId, userId, qid), lastAt, count, occ } satisfies CompletionMirror));
  } catch {
    /* storage unavailable — SW suppression falls back to the server's own heuristic */
  }
}

/** Backfill the whole localStorage completion log for a participant (called on app start). */
export async function backfillCompletions(
  studyId: number,
  userId: string,
  completions: Record<number, { lastAt: number; count: number; occ?: Record<string, number> }>,
): Promise<void> {
  try {
    await Promise.all(
      Object.entries(completions).map(([qid, rec]) =>
        mirrorCompletion(studyId, userId, Number(qid), rec.lastAt, rec.count, rec.occ)),
    );
  } catch {
    /* ignore */
  }
}
