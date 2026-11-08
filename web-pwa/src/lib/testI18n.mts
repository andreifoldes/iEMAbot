/**
 * Test helper: `import './testI18n.mts'` loads the English participant-app text (src/locales/pwa/en.json) so code under test returns real sentences instead of keys.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setDictionaries } from './i18n.ts';

const file = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'src', 'locales', 'pwa', 'en.json');
const all = JSON.parse(readFileSync(file, 'utf-8')) as Record<string, string>;
setDictionaries(Object.fromEntries(Object.entries(all).filter(([k]) => k.startsWith('pwa_'))), {});
