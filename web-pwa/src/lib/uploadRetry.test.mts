import { test } from 'node:test';
import assert from 'node:assert/strict';

const { checkUploadReply, rejectionCost, UploadRejected } = await import('./uploadRetry.ts');

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
