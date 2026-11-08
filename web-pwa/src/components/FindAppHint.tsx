import { Search } from 'lucide-react';
import type { HintOS } from '../lib/pwaInstall';
import { t } from '../lib/i18n';
import { rich } from './richText';

const APP_NAME = 'iEMAbot';

/**
 * "Can't see the app after adding it?" help. The icon is placed in the first
 * free Home Screen slot, so on a full first page it lands on a later page (or,
 * on iOS, only in the App Library). Worded per OS; `os` falls back to a
 * combined message when the platform can't be told apart.
 */
export function FindAppHint({ os }: { os: HintOS }) {
  return (
    <p className="flex items-start gap-2 text-xs text-on-surface-variant bg-surface-container dark:bg-surface-container-high rounded-xl p-2.5 leading-relaxed">
      <Search size={14} className="shrink-0 mt-0.5" aria-hidden="true" />
      {os === 'ios' ? (
        <span>
          <strong className="text-on-surface">{t('pwa_install_find_ios_q')}</strong>{' '}
          {rich(t('pwa_install_find_ios', APP_NAME, APP_NAME), { strongClass: 'text-on-surface' })}
        </span>
      ) : os === 'android' ? (
        <span>
          <strong className="text-on-surface">{t('pwa_install_find_android_q')}</strong>{' '}
          {rich(t('pwa_install_find_android', APP_NAME), { strongClass: 'text-on-surface' })}
        </span>
      ) : (
        <span>
          <strong className="text-on-surface">{t('pwa_install_find_ios_q')}</strong>{' '}
          {rich(t('pwa_install_find_generic', APP_NAME), { strongClass: 'text-on-surface' })}
        </span>
      )}
    </p>
  );
}
