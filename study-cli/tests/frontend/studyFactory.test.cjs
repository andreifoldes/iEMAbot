'use strict'
// Proof that studies made by the study-cli module are the studies the admin editor makes.
//
// The fixtures in study-cli/tests/fixtures are shared with the PHP tests (study-cli/tests/php):
//  - ui-defaults.json   every structure of a study with all its default values, read from the real TypeScript classes. The PHP
//                       schema (StudySchema) must say the same.
//  - <case>.ui.json     what the editor posts to CreateStudy for the study in support/cases.cjs. The PHP factory must turn
//                       <case>.spec.json into exactly this.
//  - <case>.stored.json what the server stored for that spec (written by the PHP tests). The editor must open it without
//                       repairing anything, and saving it again must send the same configs back.
// Run with UPDATE_FIXTURES=1 (npm run test:editor) to rewrite ui-defaults.json and the .ui.json files after changing the editor's data classes.
const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('fs')
const path = require('path')
const model = require('./support/studyModel.cjs')
const { SERVER_VERSION, PACKAGE_VERSION, createInEditor, reopenAndSave } = require('./support/uiSession.cjs')
const cases = require('./support/cases.cjs')

const FIXTURES = path.resolve(__dirname, '../fixtures')
const UPDATE = process.env.UPDATE_FIXTURES === '1'

const read = (file) => JSON.parse(fs.readFileSync(path.join(FIXTURES, file), 'utf8'))
const exists = (file) => fs.existsSync(path.join(FIXTURES, file))

function expectFixture(file, actual) {
	if(UPDATE)
		fs.writeFileSync(path.join(FIXTURES, file), JSON.stringify(actual, null, 2) + '\n')
	else {
		assert.ok(exists(file), `${file} is missing. Create it with UPDATE_FIXTURES=1 npm run test:editor`)
		assert.deepStrictEqual(actual, read(file), `${file} is out of date. If the editor's data classes changed on purpose, run UPDATE_FIXTURES=1 npm run test:editor and commit the result`)
	}
}

const fullDefaults = (object) => object.createJson({ dontFilterDefaults: true })

/**
 * Which fields of a structure (and of the structures nested in it, by path: "study.schedule") hold a text that differs per language.
 * A field is a plain value with a default (`primitive`, `translatable`, ...) and is translatable when the editor made it so.
 */
function translatableFields(object, path, found = {}) {
	const fields = object.get()
	const defaults = object.sharedMemory.default
	found[path] = Object.keys(fields).filter((key) => defaults.hasOwnProperty(key) && model.isTranslatable(fields[key])).sort()
	for(const key of Object.keys(fields)) {
		if(fields[key] instanceof model.DataStructure)
			translatableFields(fields[key], `${path}.${key}`, found)
	}
	return found
}

test('every structure keeps the defaults the PHP schema expects', () => {
	const structures = {
		study: new model.Study({}, null, 0, null),
		questionnaire: new model.Questionnaire({}, null),
		page: new model.Page({}, null, 'page'),
		input: new model.Input({}, null, 'input'),
		checkin: new model.ScheduleCheckin({}, null, 'checkin'),
		sumScore: new model.SumScore({}, null, 'sumScore'),
	}
	const defaults = {}
	let translatable = {}
	for(const [name, object] of Object.entries(structures)) {
		defaults[name] = fullDefaults(object)
		translatable = translatableFields(object, name, translatable)
	}
	assert.ok(translatable.study.includes('faq') && translatable.input.includes('listChoices'), 'the translatable fields were not found')
	expectFixture('ui-defaults.json', { ...defaults, translatable })
})

for(const c of cases) {
	test(`${c.name}: the editor posts what the fixture says`, async () => {
		const payload = await createInEditor(c.title, c.edit)
		assert.ok(payload._.packageVersion, 'the editor always sends packageVersion')
		expectFixture(`${c.name}.ui.json`, payload)
	})

	test(`${c.name}: the stored study opens without repair and saves back unchanged`, async (t) => {
		if(!exists(`${c.name}.stored.json`))
			return t.skip(`${c.name}.stored.json is written by the PHP tests (UPDATE_FIXTURES=1 phpunit -c study-cli/phpunit.xml)`)
		const stored = read(`${c.name}.stored.json`)

		// What the editor does on load: Study(config) with the repair step. A repair prints a notice and changes the config.
		const config = JSON.parse(JSON.stringify(stored.config))
		const logged = []
		const log = console.log
		console.log = (...args) => logged.push(args.join(' '))
		try {
			assert.equal(new model.RepairStudy(SERVER_VERSION, PACKAGE_VERSION).repairStudy(config), true)
		}
		finally {
			console.log = log
		}
		assert.deepStrictEqual(logged, [], 'the editor repaired the stored study')
		assert.deepStrictEqual(config, stored.config, 'the repair step changed the stored study')

		// What it does on save: every language's config, without the fields still at their default.
		const saved = await reopenAndSave(stored)
		const expected = { _: withoutServerOnly(stored.config) }
		for(const [code, langConfig] of Object.entries(stored.languages))
			expected[code] = withoutServerOnly(langConfig)
		assert.deepStrictEqual(withoutServerOnly(saved._), expected._)
		assert.deepStrictEqual(Object.keys(saved).sort(), Object.keys(expected).sort())
		for(const code of Object.keys(expected))
			assert.deepStrictEqual(withoutServerOnly(saved[code]), expected[code], `language ${code}`)
	})
}

/**
 * The server adds a few things the editor does not write back: `subVersion` while it is 0 (the editor's default) and the lists of
 * observed variables it derives from the charts (not part of the editor's data classes).
 */
function withoutServerOnly(config) {
	const copy = JSON.parse(JSON.stringify(config))
	if(copy.subVersion === 0)
		delete copy.subVersion
	for(const statistics of [copy.publicStatistics, copy.personalStatistics])
		delete statistics?.observedVariables
	return copy
}
