/**
 * Settings sub-panel for phone sensing in the Android app (lib/phoneSensing.ts): lists what the study
 * collects from the phone, whether it is working, and offers the Android permission each sensor still
 * needs (Physical activity dialog, Usage access settings), and when the data is sent: the study's upload
 * rule, the participant's "Wait for Wi-Fi" switch where the study offers it, and the last upload.
 */

import { Activity, Check, AlertTriangle, Sun, Smartphone, Move, Settings as SettingsIcon, Wifi } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
import { t } from '../lib/i18n';
import { participantCanChooseNetwork, type PhoneSensingSettings, type PhoneSensingStatus, type UploadNetwork } from '../lib/phoneSensing';

export interface PhoneSensingPanelProps {
  settings: PhoneSensingSettings;
  status: PhoneSensingStatus | null;
  onAllowActivity: () => void;
  onOpenUsageAccess: () => void;
  /** The participant switched "Wait for Wi-Fi" (only offered when the study allows the choice). */
  onWaitForWifi: (on: boolean) => void;
}

function networkRule(network: UploadNetwork, hours: number): string {
  if (network === 'any') return t('pwa_sensing_net_any');
  if (network === 'wifi_only') return t('pwa_sensing_net_wifi_only');
  return hours === 1 ? t('pwa_sensing_net_wifi_preferred_one', hours) : t('pwa_sensing_net_wifi_preferred_other', hours);
}

interface Row {
  key: string;
  icon: ReactNode;
  title: string;
  detail: string;
  ok: boolean;
  /** Why it is not working, shown instead of `detail` */
  problem?: string;
  action?: { label: string; onClick: () => void };
}

export function PhoneSensingPanel({ settings, status, onAllowActivity, onOpenUsageAccess, onWaitForWifi }: PhoneSensingPanelProps) {
  // What the phone follows (the participant's choice included); the study's rule until the phone reports.
  const network = status?.uploadNetwork ?? settings.uploadNetwork;
  const hours = status?.wifiFallbackHours ?? settings.wifiFallbackHours;
  const canChoose = participantCanChooseNetwork(settings);
  const waitForWifi = network !== 'any';
  const rows: Row[] = [];
  if (settings.light) {
    const ok = status?.lightSensor !== false;
    rows.push({
      key: 'light', icon: <Sun size={18} aria-hidden="true" />, title: t('pwa_sensing_light_title'),
      detail: t('pwa_sensing_light_detail'),
      ok, problem: ok ? undefined : t('pwa_sensing_light_problem'),
    });
  }
  if (settings.activity) {
    const noServices = status != null && !status.playServices;
    const granted = status?.activityPermission === 'granted';
    rows.push({
      key: 'activity', icon: <Activity size={18} aria-hidden="true" />, title: t('pwa_sensing_activity_title'),
      detail: t('pwa_sensing_activity_detail'),
      ok: !noServices && granted,
      problem: noServices ? t('pwa_sensing_activity_no_services')
        : granted ? undefined
        : status?.activityPermission === 'denied' ? t('pwa_sensing_activity_denied')
        : t('pwa_sensing_activity_waiting'),
      action: !noServices && !granted ? { label: t('pwa_sensing_allow'), onClick: onAllowActivity } : undefined,
    });
  }
  if (settings.accelerometer) {
    const ok = status?.accelerometer !== false;
    rows.push({
      key: 'accelerometer', icon: <Move size={18} aria-hidden="true" />, title: t('pwa_sensing_motion_title'),
      detail: t('pwa_sensing_motion_detail', settings.accelBeforeMin, settings.accelAfterMin),
      ok, problem: ok ? undefined : t('pwa_sensing_motion_problem'),
    });
  }
  if (settings.usage) {
    const granted = status?.usageAccess === true;
    rows.push({
      key: 'usage', icon: <Smartphone size={18} aria-hidden="true" />, title: t('pwa_sensing_usage_title'),
      detail: settings.usage === 'events'
        ? t('pwa_sensing_usage_events')
        : settings.usage === 'daily'
          ? t('pwa_sensing_usage_daily')
          : t('pwa_sensing_usage_both'),
      ok: granted,
      problem: granted ? undefined : t('pwa_sensing_usage_problem'),
      action: granted ? undefined : { label: t('pwa_sensing_open_settings'), onClick: onOpenUsageAccess },
    });
  }

  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <p className="text-sm text-on-surface-variant leading-relaxed">
        {t('pwa_sensing_intro')}
      </p>
      <ul className="flex flex-col gap-2">
        {rows.map((r) => (
          <li key={r.key} className="flex items-start gap-3 px-3 py-3 rounded-xl bg-surface-container-high dark:bg-surface-container-highest">
            <span className={cn('p-2 rounded-full shrink-0',
              r.ok
                ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400'
                : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400')}>
              {r.icon}
            </span>
            <div className="flex-1 min-w-0">
              <p className="font-semibold text-sm text-on-surface flex items-center gap-1.5">
                {r.title}
                {r.ok
                  ? <><Check size={14} className="text-green-700 dark:text-green-400" aria-hidden="true" /><span className="sr-only">{t('pwa_sensing_working')}</span></>
                  : <><AlertTriangle size={14} className="text-amber-700 dark:text-amber-400" aria-hidden="true" /><span className="sr-only">{t('pwa_sensing_not_working')}</span></>}
              </p>
              <p className="text-xs text-on-surface-variant mt-0.5">{r.problem ?? r.detail}</p>
              {r.action && (
                <button onClick={r.action.onClick}
                  className="mt-2 inline-flex items-center gap-1.5 text-sm font-bold px-3 py-2 min-h-11 rounded-full bg-primary text-on-primary active:scale-95 hover:brightness-110 transition-all">
                  <SettingsIcon size={15} aria-hidden="true" />{r.action.label}
                </button>
              )}
            </div>
          </li>
        ))}
      </ul>
      <div className="px-3 py-3 rounded-xl bg-surface-container-high dark:bg-surface-container-highest flex flex-col gap-2">
        {canChoose ? (
          <button onClick={() => onWaitForWifi(!waitForWifi)} role="switch" aria-checked={waitForWifi}
            className="flex items-center justify-between gap-3 w-full min-h-11 text-left">
            <span className="flex items-center gap-2 font-semibold text-sm text-on-surface"><Wifi size={18} aria-hidden="true" />{t('pwa_sensing_wait_wifi')}</span>
            <span aria-hidden="true" className={cn('w-11 h-6 rounded-full transition-colors relative shrink-0', waitForWifi ? 'bg-primary' : 'bg-outline')}>
              <span className={cn('absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all', waitForWifi ? 'left-[22px]' : 'left-0.5')} />
            </span>
          </button>
        ) : (
          <p className="flex items-center gap-2 font-semibold text-sm text-on-surface"><Wifi size={18} aria-hidden="true" />{t('pwa_sensing_sending')}</p>
        )}
        <p className="text-xs text-on-surface-variant">{networkRule(network, hours)}</p>
        <p className="text-xs text-on-surface-variant">
          {status?.lastUpload ? t('pwa_sensing_last_sent', new Date(status.lastUpload).toLocaleString()) : t('pwa_sensing_nothing_sent')}
        </p>
      </div>
      <p className="text-xs text-on-surface-variant">
        {t('pwa_sensing_footer')}
      </p>
    </div>
  );
}
