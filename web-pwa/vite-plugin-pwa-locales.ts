import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import type { Plugin } from 'vite';

/**
 * Serves the participant app's interface text from its own translation files
 * (`src/locales/pwa/<code>.json`, translated on Crowdin). Only keys starting with `pwa_` are taken, so
 * nothing else ever reaches the participant bundle.
 *
 * Virtual modules:
 *  - `virtual:pwa-locales`        English text (`en`, the fallback) and `loaders`, one lazy
 *                                 import per other language that has at least one `pwa_` key.
 *  - `virtual:pwa-locale/<code>`  the `pwa_` keys of one language.
 *
 * `machine` (also exported by `virtual:pwa-locales`) lists the languages in `machineFile`
 * (`src/locales/meta/pwa-machine-translated.json`) that are machine-translated and not yet reviewed.
 *
 * Each language becomes its own chunk, precached by the service worker and bundled into the
 * Android app, so a translation works offline without a runtime fetch.
 */
const PREFIX = 'pwa_';
const INDEX_ID = 'virtual:pwa-locales';
const ONE_PREFIX = 'virtual:pwa-locale/';

export function pwaLocales(localesDir: string, machineFile?: string): Plugin {
  const read = (code: string): Record<string, string> => {
    const file = path.join(localesDir, `${code}.json`);
    if (!existsSync(file)) return {};
    const all = JSON.parse(readFileSync(file, 'utf-8')) as Record<string, string>;
    return Object.fromEntries(Object.entries(all).filter(([k, v]) => k.startsWith(PREFIX) && typeof v === 'string' && v !== ''));
  };
  const readMachine = (): string[] => {
    if (!machineFile || !existsSync(machineFile)) return [];
    try {
      const list: unknown = JSON.parse(readFileSync(machineFile, 'utf-8'));
      return Array.isArray(list) ? list.filter((c): c is string => typeof c === 'string' && /^[A-Za-z_]+$/.test(c)) : [];
    } catch { return []; }
  };
  const codes = () => readdirSync(localesDir).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5));

  return {
    name: 'pwa-locales',
    resolveId(id) {
      if (id === INDEX_ID || id.startsWith(ONE_PREFIX)) return `\0${id}`;
      return null;
    },
    load(id) {
      if (id === `\0${INDEX_ID}`) {
        for (const code of codes()) this.addWatchFile(path.join(localesDir, `${code}.json`));
        const others = codes().filter((c) => c !== 'en' && Object.keys(read(c)).length > 0);
        const loaders = others.map((c) => `  ${JSON.stringify(c)}: () => import(${JSON.stringify(ONE_PREFIX + c)}).then((m) => m.default),`);
        if (machineFile) this.addWatchFile(machineFile);
        const machine = readMachine().filter((c) => others.includes(c));
        return `export const en = ${JSON.stringify(read('en'))};\nexport const machine = ${JSON.stringify(machine)};\nexport const loaders = {\n${loaders.join('\n')}\n};\n`;
      }
      if (id.startsWith(`\0${ONE_PREFIX}`)) {
        const code = id.slice(`\0${ONE_PREFIX}`.length);
        if (!/^[A-Za-z_]+$/.test(code)) return null;
        this.addWatchFile(path.join(localesDir, `${code}.json`));
        return `export default ${JSON.stringify(read(code))};\n`;
      }
      return null;
    },
  };
}
