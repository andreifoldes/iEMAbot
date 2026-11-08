import { KeyRound } from 'lucide-react';
import { t } from '../lib/i18n';

/**
 * "Write this down" card for what the participant may need to type into the installed app: the
 * invite code and, for a personalised link, the participant ID. Installing on iOS gives the
 * app its own empty storage, so what the link carried doesn't survive the install. Renders
 * nothing when there is nothing to show.
 */
export function InviteCodeNote({ code, participantId }: { code: string; participantId?: string }) {
  const rows = [
    { id: 'code', label: t('pwa_invite_code_label'), value: code },
    { id: 'participantId', label: t('pwa_invite_participant_id'), value: participantId ?? '' },
  ].filter((r) => r.value);
  if (!rows.length) return null;
  return (
    <div className="w-full bg-surface-container dark:bg-surface-container-high rounded-xl p-3 text-left">
      <p className="flex items-start gap-2 text-xs text-on-surface-variant leading-relaxed">
        <KeyRound size={14} className="shrink-0 mt-0.5" aria-hidden="true" />
        <span>
          {rows.length > 1
            ? t('pwa_invite_write_down_many')
            : t('pwa_invite_write_down_one')}
        </span>
      </p>
      <dl className="mt-2 space-y-1.5">
        {rows.map((r) => (
          <div key={r.id} className="flex items-baseline justify-between gap-4">
            <dt className="text-xs text-on-surface-variant shrink-0">{r.label}</dt>
            <dd className="font-mono text-xl font-semibold tracking-wider text-on-surface break-all text-right select-all">
              {r.value}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
