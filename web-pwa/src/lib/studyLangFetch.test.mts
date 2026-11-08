import { test } from 'node:test';
import assert from 'node:assert/strict';

const store = new Map<string, string>();
(globalThis as Record<string, unknown>).localStorage = {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
};

const { fetchStudy, studyLangToRequest } = await import('./studyFetch.ts');

const study = (extra: object) => ({ id: 7, title: 'S', questionnaires: [], ...extra });
const envelope = (s: object) => new Response(JSON.stringify({ success: true, serverVersion: 12, dataset: [s] }), { status: 200 });

test('studyLangToRequest: only a translated language that the study did not arrive in', () => {
  assert.equal(studyLangToRequest({ webLanguage: 'de', langCodes: ['en', 'de'], lang: 'en' }), 'de');
  assert.equal(studyLangToRequest({ webLanguage: 'DE', langCodes: ['en', 'de'] }), 'de');
  assert.equal(studyLangToRequest({ webLanguage: 'nb-NO', langCodes: ['nb_NO'] }), 'nb_NO');
  assert.equal(studyLangToRequest({ webLanguage: 'de', langCodes: ['en', 'de'], lang: 'de' }), null); // already in it
  assert.equal(studyLangToRequest({ webLanguage: 'de', langCodes: ['en'], lang: 'en' }), null);       // not translated
  assert.equal(studyLangToRequest({ webLanguage: '', langCodes: ['en', 'de'] }), null);               // automatic
  assert.equal(studyLangToRequest({ langCodes: ['en', 'de'] }), null);
  assert.equal(studyLangToRequest({ webLanguage: 'de' }), null);
});

test('the study is fetched again in the researcher\'s language, with study_lang and no lang cookie parameter', async () => {
  store.clear();
  const urls: string[] = [];
  globalThis.fetch = (async (u: string) => {
    urls.push(u);
    return u.includes('study_lang=de')
      ? envelope(study({ lang: 'de', title: 'Titel', webLanguage: 'de', langCodes: ['en', 'de'] }))
      : envelope(study({ lang: 'en', title: 'Title', webLanguage: 'de', langCodes: ['en', 'de'] }));
  }) as unknown as typeof fetch;
  const fetched = await fetchStudy('k');
  assert.equal(fetched.study.title, 'Titel');
  assert.equal(urls.length, 2);
  assert.ok(urls[1].includes('&study_lang=de'));
  assert.ok(!urls.some((u) => /[?&]lang=/.test(u)));
  // the German copy is the one kept for offline use
  globalThis.fetch = (async () => { throw new TypeError('offline'); }) as unknown as typeof fetch;
  assert.equal((await fetchStudy('k')).study.title, 'Titel');
});

test('if the second request fails or answers badly, the study stays in its default language', async () => {
  const first = study({ lang: 'en', title: 'Title', webLanguage: 'de', langCodes: ['en', 'de'] });
  for (const second of [
    async () => { throw new TypeError('Failed to fetch'); },
    async () => new Response('x', { status: 500 }),
  ]) {
    store.clear();
    let n = 0;
    globalThis.fetch = (async () => (n++ === 0 ? envelope(first) : second())) as unknown as typeof fetch;
    const fetched = await fetchStudy('k');
    assert.equal(fetched.study.title, 'Title');
  }
});

test('a study without webLanguage is fetched once', async () => {
  store.clear();
  let n = 0;
  globalThis.fetch = (async () => { n++; return envelope(study({ lang: 'en', langCodes: ['en', 'de'] })); }) as unknown as typeof fetch;
  await fetchStudy('k');
  assert.equal(n, 1);
});
