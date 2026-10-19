/**
 * Settings sub-panel for connecting wearables (Fitbit / Withings / Oura).
 *
 * Lists the providers the study offers (already intersected with the providers the
 * server has credentials for) and, for each, a Connect or Disconnect action plus the
 * last day the server synced. Connecting is a top-level browser navigation to the
 * provider's OAuth page (providers forbid being framed), handled by the parent.
 */

import { Watch, Check, RefreshCw, Link as LinkIcon, Unlink, AlertTriangle } from 'lucide-react';
import { cn } from '../lib/utils';
import type { WearableStatus } from '../types';

const PROVIDER_LABEL: Record<string, string> = {
  fitbit: 'Fitbit',
  withings: 'Withings',
  oura: 'Oura Ring',
  googlehealth: 'Google Health',
};

function providerLabel(p: string): string {
  return PROVIDER_LABEL[p] ?? p.charAt(0).toUpperCase() + p.slice(1);
}

export interface WearablesPanelProps {
  /** Offered provider slugs (study ∩ server-configured). */
  providers: string[];
  /** Connected providers + last sync time, from api/wearables_status.php. */
  status: WearableStatus[];
  /** Provider whose connect/disconnect is in flight (disables its buttons). */
  busy: string | null;
  onConnect: (provider: string) => void;
  onDisconnect: (provider: string) => void;
}

export function WearablesPanel({ providers, status, busy, onConnect, onDisconnect }: WearablesPanelProps) {
  const byProvider = new Map(status.map((s) => [s.provider, s]));

  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <p className="text-sm text-on-surface-variant leading-relaxed">
        Connect a wearable to share its health data with this study. You'll be taken to the
        provider's site to sign in and approve. You can disconnect at any time.
      </p>

      {providers.length === 0 ? (
        <p className="text-sm text-on-surface-variant">No wearables are available for this study.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {providers.map((provider) => {
            const connected = byProvider.get(provider);
            const needsReconnect = connected?.needsReconnect === true;
            const isBusy = busy === provider;
            return (
              <div key={provider}
                className="flex items-center gap-3 px-3 py-3 rounded-xl bg-surface-container-high dark:bg-surface-container-highest">
                <span className={cn('p-2 rounded-full shrink-0',
                  needsReconnect
                    ? 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400'
                    : connected
                      ? 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400'
                      : 'bg-surface-container-highest dark:bg-surface-container-low text-on-surface-variant')}>
                  {needsReconnect ? <AlertTriangle size={18} aria-hidden="true" />
                    : connected ? <Check size={18} aria-hidden="true" /> : <Watch size={18} aria-hidden="true" />}
                </span>
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-sm text-on-surface">{providerLabel(provider)}</p>
                  <p className="text-xs text-on-surface-variant">
                    {needsReconnect
                      ? 'Connection expired · please reconnect so your data keeps syncing'
                      : connected
                      ? connected.lastSync
                        ? `Connected · last sync ${new Date(connected.lastSync).toLocaleDateString()}`
                        : 'Connected · no data yet'
                      : 'Not connected'}
                  </p>
                </div>
                {connected && !needsReconnect ? (
                  <button onClick={() => onDisconnect(provider)} disabled={isBusy} aria-busy={isBusy}
                    className="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-full text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors disabled:opacity-60 shrink-0">
                    {isBusy ? <RefreshCw size={15} className="animate-spin" aria-hidden="true" /> : <Unlink size={15} aria-hidden="true" />}
                    Disconnect
                  </button>
                ) : (
                  <button onClick={() => onConnect(provider)} disabled={isBusy} aria-busy={isBusy}
                    className="inline-flex items-center gap-1.5 text-sm font-bold px-3 py-2 rounded-full bg-primary text-on-primary active:scale-95 hover:brightness-110 transition-all disabled:opacity-60 shrink-0">
                    {isBusy ? <RefreshCw size={15} className="animate-spin" aria-hidden="true" /> : <LinkIcon size={15} aria-hidden="true" />}
                    Connect
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      <p className="text-xs text-on-surface-variant">
        Your data is collected periodically by the research server while your wearable stays connected.
      </p>
    </div>
  );
}
