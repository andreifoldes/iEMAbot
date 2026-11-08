import { test } from 'node:test';
import assert from 'node:assert/strict';

const { MAX_REJECTIONS, REFUSED_RETRY_MS, alreadyStored, checkUploadReply, dueForRetry, rejectionCost, UploadRejected, UploadTooSoon } = await import('./uploadRetry.ts');

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

/** The cost a reply adds to the upload's rejection count (0 = accepted or a transient failure). */
async function cost(resp: Response | Promise<Response>): Promise<number> {
  try {
    await checkUploadReply(await resp, 'datasets.php');
    return 0;
  } catch (err) {
    return rejectionCost(err);
  }
}

test('an accepted upload passes', async () => {
  await checkUploadReply(json({ success: true }), 'datasets.php');
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
  await assert.rejects(() => checkUploadReply(json({ success: false, error: 'Study is closed' }), 'x'),
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
  await assert.rejects(() => checkUploadReply(reply, 'datasets.php'), (err: unknown) => err instanceof UploadTooSoon);
});

test('any other refusal is not sent again: the row may have been stored, and a resend would store it once more', async () => {
  const realError = console.error;
  const logged: unknown[] = [];
  console.error = (message: unknown) => { logged.push(message); };
  try {
    assert.equal(await cost(dataset({ success: false, error: 'Saving failed' })), 0);
    await checkUploadReply(dataset({ success: false, error: 'Saving failed' }), 'datasets.php');
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
