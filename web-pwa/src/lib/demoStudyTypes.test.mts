/**
 * What the participant app does with every question type, from the demo study itself. Every type is on one of the two
 * lists, so a new type, or one that stops showing, fails here until someone has decided what should happen to it.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire } from './esmiraAdapter.ts';

const demo = JSON.parse(readFileSync(new URL('../../../demo/demo-study.json', import.meta.url), 'utf8'))._;
const allTypes = demo.questionnaires.find((q: { internalId: number }) => q.internalId === 424202) as EsmiraQuestionnaire;

/** Shown to participants in the web app. */
const SHOWN = ['binary', 'date', 'duration', 'image', 'likert', 'list_multiple', 'list_single', 'number', 'record_audio',
  'record_keystrokes', 'text', 'text_input', 'time', 'va_scale', 'webapp'];
/** The web app has no way to show these: phone sensors for the Android app, and items for other channels. */
const NOT_SHOWN = ['ambient_light', 'app_usage', 'battery_level', 'bluetooth_devices', 'compass', 'countdown', 'dynamic_input',
  'file_upload', 'location', 'noise_level', 'photo', 'share', 'video'];

const items = (allTypes.pages ?? []).flatMap((p) => p.inputs ?? []) as { name: string; responseType?: string }[];

test('the demo study has every type, and each is on exactly one list', () => {
  const types = new Set(items.map((i) => i.responseType ?? 'text_input'));
  assert.deepEqual([...types].sort(), [...SHOWN, ...NOT_SHOWN].sort());
});

test('the app shows the types it should, and only those', () => {
  const shown = new Set(adaptQuestionnaire(1, allTypes, 0).questions.map((q) => q.id));
  for (const item of items) {
    const type = item.responseType ?? 'text_input';
    assert.equal(shown.has(item.name), SHOWN.includes(type), `${item.name} (${type})`);
  }
});
