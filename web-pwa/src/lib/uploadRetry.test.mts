import { test } from 'node:test';
import assert from 'node:assert/strict';

const { MAX_REJECTIONS, REFUSED_RETRY_MS, alreadyStored, checkUploadReply, dueForRetry, rejectionCost, UploadRejected, UploadTooSoon, UploadWrongDevice } = await import('./uploadRetry.ts');
const { clearWrongDevice, deviceContext, isWrongDevice, scopeKey } = await import('./deviceBinding.ts');
const device = () => deviceContext(1, 'P1');

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

/** The cost a reply adds to the upload's rejection count (0 = accepted or a transient failure). */
async function cost(resp: Response | Promise<Response>): Promise<number> {
  try {
    await checkUploadReply(await resp, 'datasets.php', device());
    return 0;
  } catch (err) {
    return rejectionCost(err);
  }
}

test('an accepted upload passes', async () => {
  await checkUploadReply(json({ success: true }), 'datasets.php', device());
});

test('being offline never counts toward giving up', async () => {
  const offline = Promise.reject(new TypeError('Failed to fetch'));
  assert.equal(await cost(offline), 0);
});

test('server errors, timeouts, rate limits and captive-portal pages are transient', async () => {
  assert.equal(await cost(json({}, 500)), 0);
  assert.equal(await cost(json({}, 503)), 0);
  assert.equal(await cost(json({}, 408)), 0);
  assert.equal(await cost(json({}, 429)), 0);
  assert.equal(await cost(new Response('<html>Sign in to the Wi-Fi</html>', { status: 200 })), 0);
});

test('a definite refusal from the server counts', async () => {
  assert.equal(await cost(json({}, 400)), 1);
  assert.equal(await cost(json({}, 403)), 1);
  assert.equal(await cost(json({ success: false, error: 'Study is closed' })), 1);
  await assert.rejects(() => checkUploadReply(json({ success: false, error: 'Study is closed' }), 'x', device()),
    (err: unknown) => err instanceof UploadRejected && /Study is closed/.test((err as Error).message));
});

// datasets.php answers 200 and success:true whatever happened, and says what became of each dataset in `states`.
const dataset = (state: Record<string, unknown>) => json({ success: true, dataset: { states: [{ dataSetId: 0, ...state }], tokens: {} } });

test('a dataset the server kept passes', async () => {
  assert.equal(await cost(dataset({ success: true })), 0);
});

test('a dataset refused for arriving too soon stays queued without counting, and is told apart', async () => {
  const reply = dataset({ success: false, error: 'Too many requests in succession' });
  assert.equal(await cost(reply.clone()), 0);
  await assert.rejects(() => checkUploadReply(reply, 'datasets.php', device()), (err: unknown) => err instanceof UploadTooSoon);
});

test('any other refusal is not sent again: the row may have been stored, and a resend would store it once more', async () => {
  const realError = console.error;
  const logged: unknown[] = [];
  console.error = (message: unknown) => { logged.push(message); };
  try {
    assert.equal(await cost(dataset({ success: false, error: 'Saving failed' })), 0);
    await checkUploadReply(dataset({ success: false, error: 'Saving failed' }), 'datasets.php', device());
    assert.ok(logged.length >= 2, 'but it is logged, so it shows up in error reports');
  } finally {
    console.error = realError;
  }
});

test('an upload the server keeps refusing is not given up on: it is tried once a day', () => {
  const now = 10 * REFUSED_RETRY_MS;
  assert.equal(dueForRetry(MAX_REJECTIONS - 1, now - 1, now), true, 'until then, whenever');
  assert.equal(dueForRetry(MAX_REJECTIONS, now - 1, now), false);
  assert.equal(dueForRetry(MAX_REJECTIONS + 50, now - REFUSED_RETRY_MS, now), true);
  assert.equal(dueForRetry(MAX_REJECTIONS, undefined, now), true);
});

test('a file the server already holds counts as done', () => {
  assert.equal(alreadyStored(new UploadRejected('File already exists')), true);
  assert.equal(alreadyStored(new UploadRejected('Not allowed')), false);
  assert.equal(alreadyStored('File already exists'), false);
});

test('an upload refused because another device holds the participant id waits: it costs nothing and is told apart', async () => {
  clearWrongDevice();
  const reply = json({ success: false, errorCode: 409, error: 'This participant ID is active on another device.' });
  assert.equal(await cost(reply.clone()), 0);
  clearWrongDevice();
  await assert.rejects(() => checkUploadReply(reply, 'datasets.php', device()), (err: unknown) => err instanceof UploadWrongDevice && !(err instanceof UploadRejected));
  assert.equal(isWrongDevice(scopeKey(1, 'P1')), true, 'the app is told, so that it can offer the transfer and pause its queues');
  clearWrongDevice();
});

test('it never uses up the attempts, however often it is refused', () => {
  let attempts = 0;
  for (let i = 0; i < 100; i++) attempts += rejectionCost(new UploadWrongDevice('another device'));
  assert.equal(attempts, 0);
  assert.equal(dueForRetry(attempts, Date.now(), Date.now()), true);
});

test('other refusals with an error code are still ordinary refusals', async () => {
  clearWrongDevice();
  assert.equal(await cost(json({ success: false, errorCode: 403, error: 'not on the list' })), 1);
  assert.equal(isWrongDevice(scopeKey(1, 'P1')), false);
});
