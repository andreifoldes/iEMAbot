/**
 * Tests for the invite helpers: which participant ID a personalised link carries, and the
 * link the code-entry screen builds so a code and ID typed into the installed app (iOS
 * keeps no state across the install) behave exactly like a link that carried them.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { enrollUrl, invitedParticipantId } from './invite.ts';

describe('invitedParticipantId', () => {
  const ids = (qs: string) => invitedParticipantId(new URLSearchParams(qs));

  it('reads pid, then uid, user_id, userId — the same precedence the app uses for the user id', () => {
    assert.equal(ids('pid=A&uid=B&user_id=C&userId=D'), 'A');
    assert.equal(ids('uid=B&user_id=C&userId=D'), 'B');
    assert.equal(ids('user_id=C&userId=D'), 'C');
    assert.equal(ids('userId=D'), 'D');
  });

  it('trims, and is empty when the link carries none', () => {
    assert.equal(ids('pid=%20P-001%20'), 'P-001');
    assert.equal(ids('key=demo'), '');
  });
});

describe('enrollUrl', () => {
  it('carries just the code when no participant ID was typed', () => {
    assert.equal(enrollUrl('/pwa/', 'demo', ''), '/pwa/?key=demo');
    assert.equal(enrollUrl('/pwa/', 'demo', '   '), '/pwa/?key=demo');
  });

  it('carries the participant ID as pid, the name the invite links use', () => {
    assert.equal(enrollUrl('/pwa/', 'demo', '1155'), '/pwa/?key=demo&pid=1155');
  });

  it('trims both and encodes anything unsafe', () => {
    assert.equal(enrollUrl('/pwa/', ' demo ', ' P 0/1&x '), '/pwa/?key=demo&pid=P%200%2F1%26x');
  });

  it('round-trips through the same parser the app reads links with', () => {
    const params = new URL(enrollUrl('/pwa/', 'ssrc', 'P-007'), 'https://x.test').searchParams;
    assert.equal(params.get('key'), 'ssrc');
    assert.equal(invitedParticipantId(params), 'P-007');
  });
});
