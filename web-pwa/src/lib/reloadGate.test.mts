/**
 * Tests for holding the PWA's update reload while a check-in is open (lib/reloadGate.ts).
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createReloadGate } from './reloadGate.ts';

function gate(hidden = false) {
  const state = { reloads: 0, hidden };
  const g = createReloadGate(() => { state.reloads++; }, () => state.hidden);
  return { g, state };
}

describe('update reload gate', () => {
  it('reloads straight away outside a check-in', () => {
    const { g, state } = gate();
    g.request();
    assert.equal(state.reloads, 1);
  });

  it('waits while a check-in is open, then reloads the next time the app is in the background', () => {
    const { g, state } = gate();
    g.hold(true);
    g.request();
    g.hidden(); // backgrounded mid-check-in: still held
    assert.equal(state.reloads, 0);
    g.hold(false); // check-in over, thank-you on screen: not yet
    assert.equal(state.reloads, 0);
    g.hidden();
    assert.equal(state.reloads, 1);
  });

  it('reloads on release when the app is already in the background', () => {
    const { g, state } = gate(true);
    g.hold(true);
    g.request();
    g.hold(false);
    assert.equal(state.reloads, 1);
  });

  it('a new check-in started before the app went to the background holds it again', () => {
    const { g, state } = gate();
    g.hold(true);
    g.request();
    g.hold(false);
    g.hold(true);
    g.hidden();
    assert.equal(state.reloads, 0);
  });

  it('reloads once', () => {
    const { g, state } = gate();
    g.request();
    g.request();
    g.hidden();
    assert.equal(state.reloads, 1);
  });
});
