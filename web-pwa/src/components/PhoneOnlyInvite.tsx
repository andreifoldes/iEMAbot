import { useState } from 'react';
import { Check, Copy, Smartphone } from 'lucide-react';
import { EnlargeableQr } from './EnlargeableQr';
import { InviteCodeNote } from './InviteCodeNote';

export interface PhoneOnlyInviteProps {
  studyTitle?: string;
  /** Full link to carry to the phone: the invite code and any participant ID are in it. */
  url: string;
  /** Invite code to write down: what the installed app asks for (iOS keeps no state across the install). */
  code?: string;
  /** Participant ID a personalised link carried; also written down, since it is typed in again after an iOS install. */
  participantId?: string;
  /** Touch devices (tablets, foldables, a large phone we misread) may carry on here. */
  canContinueHere: boolean;
  onContinueHere: () => void;
}

/**
 * Invite page for studies the researcher restricted to smartphones (`webPhoneOnly`).
 * Shown instead of the study on computers and tablets — before consent and before any
 * participant record exists — so scanning the QR code on a phone starts clean.
 *
 * Layout: what to do, the code to note, the QR code (the main route, tap to enlarge),
 * then the secondary routes underneath, then a browser tip.
 */
export function PhoneOnlyInvite({
  studyTitle,
  url,
  code,
  participantId,
  canContinueHere,
  onContinueHere,
}: PhoneOnlyInviteProps) {
  return (
    <div className="bg-white dark:bg-surface-container-lowest border border-slate-200 dark:border-outline-variant/30 rounded-2xl shadow-sm message-shadow p-5 flex flex-col gap-4">
      <div className="flex items-start gap-3">
        <span className="p-2 rounded-full bg-secondary-container text-on-secondary-container shrink-0">
          <Smartphone size={20} aria-hidden="true" />
        </span>
        <div>
          <h2 className="text-lg font-bold text-on-surface leading-snug">Open this study on your phone</h2>
          <p className="text-sm text-on-surface-variant leading-relaxed mt-0.5">
            {studyTitle ? `${studyTitle} is a smartphone study.` : 'This is a smartphone study.'} This device isn&apos;t a
            phone, so open the link on your <strong className="text-on-surface">smartphone</strong> to install the app and
            join.
          </p>
        </div>
      </div>

      <InviteCodeNote code={code ?? ''} participantId={participantId} />

      <div className="flex flex-col items-center gap-2">
        <EnlargeableQr url={url} />
        <p className="text-xs text-on-surface-variant text-center">
          Scan with your phone camera, or tap the code to enlarge it.
        </p>
      </div>

      <div className="flex flex-col gap-2">
        <p className="text-[0.75rem] font-semibold uppercase tracking-wider text-on-surface-variant">Other ways to open it</p>
        <CopyLinkRow url={url} />
        {canContinueHere && (
          <button
            onClick={onContinueHere}
            className="self-start py-2 text-xs font-semibold text-on-surface-variant hover:text-on-surface underline underline-offset-2 transition-colors"
          >
            On your phone already? I&apos;m on my phone — continue here
          </button>
        )}
      </div>

      <p className="text-[0.75rem] text-on-surface-variant leading-relaxed text-center">
        Tip: open it in <strong>Safari</strong> or <strong>Chrome</strong> (iPhone), or <strong>Chrome</strong> (Android).
      </p>
    </div>
  );
}

/** Shows the link and copies it on tap, for participants who'd rather message it to themselves. */
function CopyLinkRow({ url }: { url: string }) {
  const [copied, setCopied] = useState(false);
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard blocked — the link stays visible below to copy by hand */
    }
  };
  return (
    <div className="bg-surface-container dark:bg-surface-container-high rounded-xl p-3 flex flex-col gap-2">
      <p className="text-xs text-on-surface leading-relaxed">
        <strong>Copy the link</strong> to send it to yourself
      </p>
      <button
        onClick={onCopy}
        className="self-start inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-container-high dark:bg-surface-container-highest text-on-surface font-semibold text-xs active:scale-95 transition-all"
      >
        {copied ? <Check size={14} aria-hidden="true" /> : <Copy size={14} aria-hidden="true" />}
        {copied ? 'Link copied' : 'Copy link'}
      </button>
      <p className="text-[0.75rem] text-on-surface-variant leading-relaxed break-all select-all">{url}</p>
      <span role="status" className="sr-only">{copied ? 'Link copied' : ''}</span>
    </div>
  );
}
