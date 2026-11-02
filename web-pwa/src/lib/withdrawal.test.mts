/** Tests for the pure parts of leaving a study (see withdrawal.ts). */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { buildQuitPayload, withTimeout, withdrawnFromStatus } from './withdrawal.ts';

describe('buildQuitPayload', () => {
  it('is a quit event in the shape CreateDataSet expects, keyed to the participant', () => {
    const p = buildQuitPayload({
      studyId: 42, studyVersion: 3, studySubVersion: 1, studyLang: 'de', serverVersion: 12,
      accessKey: 'key1', userId: 'p-001', model: 'UA', now: 1700000000000,
    });
    assert.equal(p.userId, 'p-001');
    assert.equal(p.serverVersion, 12);
    assert.equal(p.dataset.length, 1);
    const d = p.dataset[0];
    assert.equal(d.eventType, 'quit');
    assert.equal(d.studyId, 42);
    assert.equal(d.accessKey, 'key1');
    assert.equal(d.studyVersion, 3);
    assert.equal(d.studySubVersion, 1);
    assert.equal(d.studyLang, 'de');
    assert.equal(d.responseTime, 1700000000000);
    assert.equal(d.questionnaireName, null);
    assert.equal(d.questionnaireInternalId, null);
    assert.deepEqual(d.responses, { model: 'UA' });
  });

  it('defaults the study version fields like the joined event does', () => {
    const d = buildQuitPayload({ studyId: 1, serverVersion: 12, accessKey: 'k', userId: 'u', model: '', now: 1 }).dataset[0];
    assert.equal(d.studyVersion, 0);
    assert.equal(d.studySubVersion, 0);
    assert.equal(d.studyLang, 'en');
  });

  it('reports the web app type outside the native shell', () => {
    assert.equal(buildQuitPayload({ studyId: 1, serverVersion: 12, accessKey: 'k', userId: 'u', model: '', now: 1 }).appType, 'Web');
  });
});

describe('withTimeout', () => {
  it('passes a prompt result through', async () => {
    assert.equal(await withTimeout(Promise.resolve('ok'), 50, 'late'), 'ok');
  });
  it('falls back when the promise is slow or rejects', async () => {
    assert.equal(await withTimeout(new Promise<string>(() => {}), 10, 'late'), 'late');
    assert.equal(await withTimeout(Promise.reject(new Error('boom')), 50, 'failed'), 'failed');
  });
});

describe('withdrawnFromStatus', () => {
  it('only an explicit withdrawn: true on a success envelope counts', () => {
    assert.equal(withdrawnFromStatus({ success: true, dataset: { withdrawn: true } }), true);
    assert.equal(withdrawnFromStatus({ success: true, dataset: { withdrawn: false } }), false);
    assert.equal(withdrawnFromStatus({ success: true, dataset: { nextNotification: null } }), false); // older server
    assert.equal(withdrawnFromStatus({ success: false, error: 'x', dataset: { withdrawn: true } }), false);
    assert.equal(withdrawnFromStatus(null), false);
    assert.equal(withdrawnFromStatus('nope'), false);
  });
});
