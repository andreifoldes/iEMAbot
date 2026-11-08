'use strict'
// Drives the admin editor's real StudyLoader against a pretend server, so a test sees exactly what the editor sends.
const { StudyLoader, Requests, PromiseCache } = require('./studyModel.cjs')

const SERVER_VERSION = 12
const PACKAGE_VERSION = '9.9.9'
const STUDY_ID = 1111
const FIRST_QUESTIONNAIRE_ID = 20001

/**
 * Starts a loader whose requests are answered here: GetNewId hands out fixed ids, saves are recorded, and GetFullStudy returns
 * `stored` when given. Requests.loadJson is replaced for the life of the process (the tests run one at a time).
 */
function pretendServer(stored) {
	PromiseCache.remove(`study${STUDY_ID}`) // the editor keeps loaded studies in a cache of its own; every session starts without
	const server = { saves: [], requests: [] }
	let nextQuestionnaireId = FIRST_QUESTIONNAIRE_ID
	Requests.loadJson = async (url, _type, body) => {
		server.requests.push(url)
		if(url.includes('type=GetNewId'))
			return url.includes('for=questionnaire') ? nextQuestionnaireId++ : STUDY_ID
		if(url.includes('type=CreateStudy') || url.includes('type=SaveStudy')) {
			server.saves.push({ type: url.includes('type=CreateStudy') ? 'CreateStudy' : 'SaveStudy', body: JSON.parse(body) })
			return { metaData: { owner: 'tester', lastSavedBy: 'tester', lastSavedAt: 1, createdTimestamp: 1 }, json: JSON.parse(body) }
		}
		if(url.includes('type=GetFullStudy') && stored)
			return JSON.parse(JSON.stringify(stored))
		if(url.includes('type=CheckChanged'))
			return 1
		throw new Error('The pretend server does not know ' + url)
	}
	server.loader = new StudyLoader(SERVER_VERSION, PACKAGE_VERSION, {})
	return server
}

/** What the editor posts when a researcher creates a study with this title, edits it with `edit` and presses save. */
async function createInEditor(title, edit) {
	const server = pretendServer(null)
	const id = await server.loader.addStudy({ title })
	const study = await server.loader.loadFullStudy(id)
	await edit(study, server.loader)
	await server.loader.saveStudy(study)
	const [save] = server.saves
	if(save.type !== 'CreateStudy')
		throw new Error('A new study must be saved with CreateStudy, not ' + save.type)
	return save.body
}

/** What the editor posts when a researcher opens a stored study and saves it without changing anything. */
async function reopenAndSave(stored) {
	const server = pretendServer(stored)
	const study = await server.loader.loadFullStudy(stored.config.id)
	await server.loader.saveStudy(study)
	return server.saves[0].body
}

module.exports = { SERVER_VERSION, PACKAGE_VERSION, STUDY_ID, FIRST_QUESTIONNAIRE_ID, createInEditor, reopenAndSave }
