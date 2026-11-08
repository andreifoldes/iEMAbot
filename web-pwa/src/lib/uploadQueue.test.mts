import { test } from 'node:test';
import assert from 'node:assert/strict';

const { drainQueue, oneAtATime } = await import('./uploadQueue.ts');

interface Item { id: string; attempts: number }

function memoryStore(items: Item[]) {
  let saved = items;
  return { load: () => saved, save: (q: Item[]) => { saved = q; } };
}

function steps(overrides: Record<string, unknown> = {}) {
  return {
    skip: () => false,
    claim: (item: Item) => item,
    send: async () => {},
    onSent: () => {},
    afterFailure: (item: Item) => ({ ...item, attempts: item.attempts + 1 }),
    pause: async () => {},
    ...overrides,
  };
}

const items = (...ids: string[]) => ids.map((id) => ({ id, attempts: 0 }));

test('everything is sent once, and the queue empties', async () => {
  const store = memoryStore(items('a', 'b', 'c'));
  const sent: string[] = [];
  assert.equal(await drainQueue(store, steps({ send: async (i: Item) => { sent.push(i.id); } })), 0);
  assert.deepEqual(sent, ['a', 'b', 'c']);
});

test('what is queued during a run survives it', async () => {
  const store = memoryStore(items('a', 'b'));
  await drainQueue(store, steps({
    send: async (i: Item) => { if (i.id === 'a') store.save([...store.load(), ...items('new')]); },
  }));
  assert.deepEqual(store.load().map((i) => i.id), ['new']);
});

test('after each send it is gone from storage: an app closed halfway does not send it again', async () => {
  const store = memoryStore(items('a', 'b'));
  const left: string[][] = [];
  await drainQueue(store, steps({ onSent: () => { left.push(store.load().map((i) => i.id)); } }));
  assert.deepEqual(left, [['b'], []]);
});

test('a failure stays queued with its count, and the others still go', async () => {
  const store = memoryStore(items('a', 'b'));
  const left = await drainQueue(store, steps({ send: async (i: Item) => { if (i.id === 'a') throw new Error('no'); } }));
  assert.equal(left, 1);
  assert.deepEqual(store.load(), [{ id: 'a', attempts: 1 }]);
});

test('nothing leaves the queue except by being sent', async () => {
  const store = memoryStore(items('refused', 'skipped'));
  await drainQueue(store, steps({
    skip: (i: Item) => i.id === 'skipped',
    send: async () => { throw new Error('refused'); },
  }));
  assert.deepEqual(store.load().map((i) => i.id), ['refused', 'skipped']);
});

test('an item is marked as being sent in storage before it goes out', async () => {
  const store = memoryStore(items('a'));
  let seen: unknown;
  await drainQueue(store, steps({
    claim: (i: Item) => ({ ...i, attempts: 99 }),
    send: async () => { seen = store.load()[0].attempts; },
  }));
  assert.equal(seen, 99);
});

test('calls during a run do not start a second one beside it, but one more after it', async () => {
  let running = 0;
  let most = 0;
  let runs = 0;
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => { release = resolve; });
  const flush = oneAtATime(async () => {
    running++; runs++; most = Math.max(most, running);
    await gate;
    running--;
    return runs;
  });
  const first = flush();
  const second = flush();
  const third = flush();
  release();
  await Promise.all([first, second, third]);
  assert.equal(most, 1, 'never two at once');
  assert.equal(runs, 2, 'the calls during the first run share one more');
  await flush();
  assert.equal(runs, 3, 'and it works again afterwards');
});
