/**
 * Audio upload store & queue for `record_audio` voice memos.
 *
 * ESMira links an uploaded audio file to its CSV row via an integer *identifier*:
 * the questionnaire response value for the audio question is that identifier, and
 * the bytes are POSTed separately to api/file_uploads.php. The server only allows
 * the file upload AFTER the dataset has been processed (it writes a "pending
 * upload" marker while saving the dataset), so ordering matters:
 *
 *   1. save the recording locally (survives reloads / offline)  → status 'awaiting_dataset'
 *   2. submit the questionnaire dataset (identifier is a response value)
 *   3. once that dataset reached the server, flip the recording → status 'ready'
 *   4. upload the bytes to file_uploads.php; on success, drop the local copy
 *
 * Blobs are held in IndexedDB (localStorage can't store binary), keyed by the
 * identifier. Uploads retry on reconnect/visibility alongside the dataset queue.
 */

const API_ROOT = (import.meta.env.VITE_API_ROOT as string | undefined) || '/esmira/';

const DB_NAME = 'esmira_audio';
const STORE = 'recordings';
const MAX_ATTEMPTS = 20;

export interface AudioRecord {
  /** Non-zero integer upload identifier; also the questionnaire response value. */
  identifier: number;
  studyId: number;
  userId: string;
  blob: Blob;
  /** 'awaiting_dataset' until its dataset reaches the server, then 'ready' to upload. */
  status: 'awaiting_dataset' | 'ready';
  attempts: number;
  createdAt: number;
}

/** Generate a unique non-zero 31-bit identifier (safe for PHP `(int)` casts). */
export function newAudioIdentifier(): number {
  const c = typeof crypto !== 'undefined' ? crypto : undefined;
  if (c?.getRandomValues) {
    const buf = new Uint32Array(1);
    c.getRandomValues(buf);
    return (buf[0] % 2147483646) + 1; // 1 .. 2^31-1
  }
  return Math.floor(Math.random() * 2147483646) + 1;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'identifier' });
      }
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

function getAll(): Promise<AudioRecord[]> {
  return tx<AudioRecord[]>('readonly', (s) => s.getAll() as IDBRequest<AudioRecord[]>);
}

/** Persist a freshly captured recording (before the dataset is submitted). */
export async function saveRecording(rec: Omit<AudioRecord, 'status' | 'attempts' | 'createdAt'>): Promise<void> {
  try {
    await tx('readwrite', (s) =>
      s.put({ ...rec, status: 'awaiting_dataset', attempts: 0, createdAt: Date.now() } satisfies AudioRecord));
  } catch {
    /* storage unavailable — the memo can't be persisted; upload will simply not happen */
  }
}

/** Flip the given identifiers to 'ready' once their dataset has reached the server. */
export async function markAudioReady(studyId: number, userId: string, identifiers: number[]): Promise<void> {
  if (!identifiers.length) return;
  const wanted = new Set(identifiers);
  try {
    const all = await getAll();
    await Promise.all(
      all
        .filter((r) => r.studyId === studyId && r.userId === userId && wanted.has(r.identifier) && r.status !== 'ready')
        .map((r) => tx('readwrite', (s) => s.put({ ...r, status: 'ready' } satisfies AudioRecord))),
    );
  } catch {
    /* ignore — flush will retry */
  }
}

/** Number of recordings not yet uploaded (queued or awaiting their dataset). */
export async function pendingAudioCount(): Promise<number> {
  try {
    return (await getAll()).length;
  } catch {
    return 0;
  }
}

async function uploadOne(rec: AudioRecord): Promise<void> {
  const form = new FormData();
  form.append('studyId', String(rec.studyId));
  form.append('userId', rec.userId);
  form.append('dataType', 'Audio');
  // The file's *name* is the integer identifier — file_uploads.php reads it as
  // `(int)$fileData['name']` to match the pending-upload marker.
  form.append('upload', rec.blob, String(rec.identifier));

  const resp = await fetch(`${API_ROOT}api/file_uploads.php`, { method: 'POST', body: form });
  if (!resp.ok) throw new Error(`file_uploads.php returned ${resp.status}`);
  const env = await resp.json();
  if (!env.success) throw new Error(env.error || 'Audio upload rejected');
}

let flushing = false;

/**
 * Upload every 'ready' recording. On success the local copy is dropped; on
 * failure attempts are bumped and the recording is abandoned after MAX_ATTEMPTS.
 * Guarded so concurrent triggers (submit + online + visibility) don't overlap.
 */
export async function flushAudioQueue(): Promise<void> {
  if (flushing) return;
  flushing = true;
  try {
    const all = await getAll();
    for (const rec of all) {
      if (rec.status !== 'ready') continue;
      if (rec.attempts >= MAX_ATTEMPTS) {
        await tx('readwrite', (s) => s.delete(rec.identifier)); // give up — avoid an unbounded queue
        continue;
      }
      try {
        await uploadOne(rec);
        await tx('readwrite', (s) => s.delete(rec.identifier));
      } catch {
        await tx('readwrite', (s) => s.put({ ...rec, attempts: rec.attempts + 1 } satisfies AudioRecord));
      }
    }
  } catch {
    /* IndexedDB unavailable — nothing to flush */
  } finally {
    flushing = false;
  }
}
