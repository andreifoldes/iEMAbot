/**
 * Tests for the three-step signup funnel on the invite-code screen
 * (install → open the installed app → enter the invite code).
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { funnelSteps } from './installFunnel.ts';

describe('funnelSteps', () => {
  it('before install: step 1 is active, the rest are pending', () => {
    assert.deepEqual(
      funnelSteps(false).map((s) => [s.n, s.state]),
      [[1, 'active'], [2, 'todo'], [3, 'todo']],
    );
  });

  it('after install: step 1 is done and step 2 ("open it") becomes active', () => {
    assert.deepEqual(
      funnelSteps(true).map((s) => [s.n, s.state]),
      [[1, 'done'], [2, 'active'], [3, 'todo']],
    );
  });

  it('keeps the participant-facing labels in order', () => {
    assert.deepEqual(
      funnelSteps(false).map((s) => s.label),
      ['Install this app', 'Open the installed app', 'Enter your invite code'],
    );
  });

  it('never activates the invite-code step from this tab (it unlocks only in the installed app)', () => {
    for (const installed of [false, true]) {
      assert.equal(funnelSteps(installed)[2].state, 'todo');
    }
  });
});
