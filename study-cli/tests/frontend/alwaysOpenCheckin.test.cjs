'use strict'
// The check-in a study's first questionnaire gets in the dashboard, and how the schedule page recognises it so it can offer to replace it.
const { test } = require('node:test')
const assert = require('node:assert')
const { createInEditor } = require('./support/uiSession.cjs')

/** A new study with one questionnaire, set up as the dashboard does it, handed to `check`. */
async function withNewStudy(check) {
	await createInEditor('Study', async (study, loader) => {
		const questionnaire = await loader.addQuestionnaire(study, { title: 'Daily', pages: [{}] })
		loader.openIfFirstCheckin(study, questionnaire)
		await check(study, loader, questionnaire)
	})
}

test('a first questionnaire is open at any time, and recognised as such', async () => {
	await withNewStudy((study, _loader, questionnaire) => {
		const [checkin] = study.schedule.checkins.get()
		assert.strictEqual(study.schedule.checkins.get().length, 1)
		assert.strictEqual(checkin.questionnaireId.get(), questionnaire.internalId.get())
		assert.strictEqual(study.schedule.getOnlyAlwaysOpen(), checkin)
	})
})

test('a second questionnaire does not get a check-in of its own', async () => {
	await withNewStudy(async (study, loader) => {
		const second = await loader.addQuestionnaire(study, { title: 'Evening', pages: [{}] })
		loader.openIfFirstCheckin(study, second)
		assert.strictEqual(study.schedule.checkins.get().length, 1)
	})
})

test('it is not recognised once the researcher has changed it', async () => {
	await withNewStudy((study) => {
		study.schedule.checkins.get()[0].limit.set('day')
		assert.strictEqual(study.schedule.getOnlyAlwaysOpen(), null)
	})
})

test('it is not recognised next to another check-in', async () => {
	await withNewStudy((study) => {
		study.schedule.checkins.push({ startSegment: 1, endSegment: 2 })
		assert.strictEqual(study.schedule.getOnlyAlwaysOpen(), null)
	})
})

test('a planned check-in is not taken for it', async () => {
	await createInEditor('Study', async (study, loader) => {
		const questionnaire = await loader.addQuestionnaire(study, { title: 'Daily', pages: [{}] })
		study.schedule.checkins.push({ questionnaireId: questionnaire.internalId.get() })
		assert.strictEqual(study.schedule.getOnlyAlwaysOpen(), null)
	})
})
