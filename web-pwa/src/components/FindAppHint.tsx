import { Search } from 'lucide-react';
import type { HintOS } from '../lib/pwaInstall';

const APP_NAME = 'iEMAbot';
const OPEN_FROM_THERE = 'Open it from there to continue.';

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
          <strong className="text-on-surface">Can&apos;t see the app after adding it?</strong>{' '}
          iOS puts the icon in the first free spot on your Home Screen. If your first page is
          full, it goes on a later page, so swipe left to find{' '}
          <strong className="text-on-surface">{APP_NAME}</strong>. You can also swipe all the way
          left to the App Library, or pull down on the Home Screen and search for
          &ldquo;{APP_NAME}&rdquo;. {OPEN_FROM_THERE}
        </span>
      ) : os === 'android' ? (
        <span>
          <strong className="text-on-surface">Can&apos;t see the app after installing?</strong>{' '}
          If your first Home Screen page is full, Android may put the icon on another page, so
          swipe left to look through your pages. You can also swipe up to open your app list and
          find <strong className="text-on-surface">{APP_NAME}</strong> there. {OPEN_FROM_THERE}
        </span>
      ) : (
        <span>
          <strong className="text-on-surface">Can&apos;t see the app after adding it?</strong>{' '}
          If your first Home Screen page is full, the icon may be on a later page, so swipe left
          to look through your pages. You can also search for{' '}
          <strong className="text-on-surface">{APP_NAME}</strong>: pull down on the Home Screen on
          iPhone, or swipe up to open the app list on Android. {OPEN_FROM_THERE}
        </span>
      )}
    </p>
  );
}
