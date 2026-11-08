/**
 * The three-step signup funnel shown on the invite-code screen in a plain
 * browser tab: install → open the installed app → enter the invite code.
 *
 * `installed` is true once the browser reported `appinstalled` this session: step 1
 * is then done and step 2 ("open it from your Home Screen") becomes the active one.
 */
import { t } from './i18n.ts';

export interface FunnelStep {
  n: number;
  label: string;
  state: 'done' | 'active' | 'todo';
}

export function funnelSteps(installed: boolean): FunnelStep[] {
  return [
    { n: 1, label: t('pwa_install_this_app'), state: installed ? 'done' : 'active' },
    { n: 2, label: t('pwa_install_funnel_open'), state: installed ? 'active' : 'todo' },
    { n: 3, label: t('pwa_install_funnel_code'), state: 'todo' },
  ];
}
