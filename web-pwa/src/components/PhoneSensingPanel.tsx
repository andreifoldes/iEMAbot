/**
 * Settings sub-panel for phone sensing in the Android app (lib/phoneSensing.ts): lists what the study
 * collects from the phone, whether it is working, and offers the Android permission each sensor still
 * needs (Physical activity dialog, Usage access settings), and when the data is sent: the study's upload
 * rule, the participant's "Wait for Wi-Fi" switch where the study offers it, and the last upload.
 */

import { Activity, Check, AlertTriangle, Sun, Smartphone, Move, Settings as SettingsIcon, Wifi } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
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
  if (network === 'any') return 'Sent over any connection, Wi-Fi or mobile data.';
  if (network === 'wifi_only') return 'Sent over Wi-Fi only. Connect to Wi-Fi now and then so your data reaches the research team.';
  return `Sent over Wi-Fi, to spare your mobile data. If your phone has not been on Wi-Fi for ${hours} ${hours === 1 ? 'hour' : 'hours'}, mobile data is used.`;
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
      key: 'light', icon: <Sun size={18} aria-hidden="true" />, title: 'Ambient light',
      detail: 'How bright it is around you, measured when you open and finish a questionnaire.',
      ok, problem: ok ? undefined : 'This phone has no light sensor.',
    });
  }
  if (settings.activity) {
    const noServices = status != null && !status.playServices;
    const granted = status?.activityPermission === 'granted';
    rows.push({
      key: 'activity', icon: <Activity size={18} aria-hidden="true" />, title: 'Physical activity',
      detail: 'Whether you are still, walking, running, cycling or in a vehicle, noted by Android throughout the day.',
      ok: !noServices && granted,
      problem: noServices ? 'Needs Google Play services, which this phone does not have.'
        : granted ? undefined
        : status?.activityPermission === 'denied' ? 'Not allowed. Allow "Physical activity" for iEMAbot in Android\'s app settings.'
        : 'Waiting for your permission.',
      action: !noServices && !granted ? { label: 'Allow', onClick: onAllowActivity } : undefined,
    });
  }
  if (settings.accelerometer) {
    const ok = status?.accelerometer !== false;
    rows.push({
      key: 'accelerometer', icon: <Move size={18} aria-hidden="true" />, title: 'Movement',
      detail: `The phone's motion sensor, from ${settings.accelBeforeMin} minutes before each questionnaire until ${settings.accelAfterMin} minutes after it. Android shows a notification while it records.`,
      ok, problem: ok ? undefined : 'This phone has no motion sensor.',
    });
  }
  if (settings.usage) {
    const granted = status?.usageAccess === true;
    rows.push({
      key: 'usage', icon: <Smartphone size={18} aria-hidden="true" />, title: 'App usage',
      detail: settings.usage === 'events'
        ? 'Which apps you open and when the screen turns on, off and unlocks. Only app names are shared, never what you do in them.'
        : settings.usage === 'daily'
          ? 'How long you use each app per day and how often you unlock your phone. Only app names are shared, never what you do in them.'
          : 'Which apps you open and when, and daily totals per app. Only app names are shared, never what you do in them.',
      ok: granted,
      problem: granted ? undefined : 'Switch on "Usage access" for iEMAbot in Android\'s settings.',
      action: granted ? undefined : { label: 'Open settings', onClick: onOpenUsageAccess },
    });
  }

  return (
    <div className="p-5 overflow-y-auto custom-scrollbar flex flex-col gap-4">
      <p className="text-sm text-on-surface-variant leading-relaxed">
        This study collects some information from your phone to understand the moments around your
        questionnaires. Here you can see what is collected and whether it is working.
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
                  ? <><Check size={14} className="text-green-700 dark:text-green-400" aria-hidden="true" /><span className="sr-only">(working)</span></>
                  : <><AlertTriangle size={14} className="text-amber-700 dark:text-amber-400" aria-hidden="true" /><span className="sr-only">(not working)</span></>}
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
            <span className="flex items-center gap-2 font-semibold text-sm text-on-surface"><Wifi size={18} aria-hidden="true" />Wait for Wi-Fi</span>
            <span aria-hidden="true" className={cn('w-11 h-6 rounded-full transition-colors relative shrink-0', waitForWifi ? 'bg-primary' : 'bg-outline')}>
              <span className={cn('absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all', waitForWifi ? 'left-[22px]' : 'left-0.5')} />
            </span>
          </button>
        ) : (
          <p className="flex items-center gap-2 font-semibold text-sm text-on-surface"><Wifi size={18} aria-hidden="true" />Sending your data</p>
        )}
        <p className="text-xs text-on-surface-variant">{networkRule(network, hours)}</p>
        <p className="text-xs text-on-surface-variant">
          {status?.lastUpload ? `Last sent: ${new Date(status.lastUpload).toLocaleString()}` : 'Nothing sent yet.'}
        </p>
      </div>
      <p className="text-xs text-on-surface-variant">
        Collected data is sent to the research server in the background. Your questionnaire answers are
        always sent straight away, over any connection. Leaving the study stops the collection and deletes
        anything not yet sent.
      </p>
    </div>
  );
}
