import React from 'react';
import stats from '../generated/fork-stats.json';

/**
 * Numbers for the "Fork divergence" page, computed from git when the site is built
 * (website/scripts/fork-stats.mjs writes ../generated/fork-stats.json). A figure the script could not
 * compute is null and renders as "unavailable", never as a stale value.
 */

type Stats = {
  generatedAt: string;
  forkVersion: string;
  base: { version: string; date: string } | null;
  size: { filesChanged: number; linesAdded: number; linesRemoved: number } | null;
  upstream: { version: string; date: string; commitsAhead: number; filesChanged: number } | null;
};

const s = stats as Stats;
const NA = 'unavailable';

const int = (n: number | null | undefined): string => (n == null ? NA : n.toLocaleString('en-GB'));
/** 63859 -> "63.9k"; small numbers stay exact. */
const lines = (n: number | null | undefined): string =>
  n == null ? NA : n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(n);

const VALUES = {
  asOf: () => s.generatedAt,
  forkVersion: () => s.forkVersion,
  baseVersion: () => s.base?.version ?? NA,
  baseDate: () => s.base?.date ?? NA,
  filesChanged: () => int(s.size?.filesChanged),
  linesAdded: () => lines(s.size?.linesAdded),
  linesRemoved: () => lines(s.size?.linesRemoved),
  upstreamVersion: () => s.upstream?.version ?? NA,
  upstreamDate: () => s.upstream?.date ?? NA,
  upstreamCommitsAhead: () => int(s.upstream?.commitsAhead),
  upstreamFiles: () => int(s.upstream?.filesChanged),
} as const;

export type ForkStatName = keyof typeof VALUES;

/** One figure, inline in prose: <ForkStat name="forkVersion" /> */
export function ForkStat({ name }: { name: ForkStatName }): React.ReactElement {
  return <>{VALUES[name]()}</>;
}

/** The "numbers" table of the Fork divergence page. */
export function ForkNumbers(): React.ReactElement {
  return (
    <table>
      <thead>
        <tr>
          <th>Measure</th>
          <th>Value</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Fork base (last common upstream commit)</td>
          <td>
            upstream <code>{VALUES.baseVersion()}</code>, {VALUES.baseDate()}
          </td>
        </tr>
        <tr>
          <td>Size of the change since the base</td>
          <td>
            {VALUES.filesChanged()} files, +{VALUES.linesAdded()} / −{VALUES.linesRemoved()} lines (lock files
            excluded; includes the Android app, the cognitive-task manifests and these docs)
          </td>
        </tr>
        <tr>
          <td>Fork version</td>
          <td>
            <code>{VALUES.forkVersion()}</code>
          </td>
        </tr>
        <tr>
          <td>Upstream now</td>
          <td>
            <code>{VALUES.upstreamVersion()}</code> ({VALUES.upstreamDate()}), {VALUES.upstreamCommitsAhead()} commits and{' '}
            {VALUES.upstreamFiles()} files ahead of the fork base
          </td>
        </tr>
      </tbody>
    </table>
  );
}
