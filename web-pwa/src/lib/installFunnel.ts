/**
 * The three-step signup funnel shown on the invite-code screen in a plain
 * browser tab: install → open the installed app → enter the invite code.
 *
 * `installed` is true once the browser reported `appinstalled` this session: step 1
 * is then done and step 2 ("open it from your Home Screen") becomes the active one.
 */
export interface FunnelStep {
  n: number;
  label: string;
  state: 'done' | 'active' | 'todo';
}

export function funnelSteps(installed: boolean): FunnelStep[] {
  return [
    { n: 1, label: 'Install this app', state: installed ? 'done' : 'active' },
    { n: 2, label: 'Open the installed app', state: installed ? 'active' : 'todo' },
    { n: 3, label: 'Enter your invite code', state: 'todo' },
  ];
}
