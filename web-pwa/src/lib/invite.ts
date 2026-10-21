/**
 * Invite-link helpers.
 *
 * iOS gives an installed home-screen app its own empty storage, so the invite code and a
 * personalised link's participant ID (`pid`) don't survive the install. The participant
 * writes both down and types them into the installed app; these helpers keep that path
 * identical to opening a link that carried them.
 */

/** Participant ID a personalised invite link carries (same names and precedence as the user id). */
export function invitedParticipantId(params: URLSearchParams): string {
  return (params.get('pid') ?? params.get('uid') ?? params.get('user_id') ?? params.get('userId') ?? '').trim();
}

/**
 * The address the code-entry screen sends the participant to: the invite code, plus the
 * participant ID when one was typed. Reloading there runs the normal load flow, which
 * reads `key` and `pid` exactly as it would from an invite link.
 */
/**
 * Where sign-out sends the participant: the app's own path with no query or hash. Reloading
 * the current address would keep `?key=` (and `pid`) from the invite link, and the load flow
 * would start the same study again instead of showing the invite-code screen.
 */
export function signedOutUrl(pathname: string): string {
  return pathname;
}

export function enrollUrl(pathname: string, code: string, participantId: string): string {
  const pid = participantId.trim();
  return `${pathname}?key=${encodeURIComponent(code.trim())}${pid ? `&pid=${encodeURIComponent(pid)}` : ''}`;
}
