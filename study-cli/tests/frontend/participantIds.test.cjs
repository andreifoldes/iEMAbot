'use strict'
// The rules for the list of participant ids a study is restricted to (src/frontend/ts/helpers/participantIds.ts): what is an
// id, how a pasted or uploaded list is read, the random ids, and the file of personal links. The editor's own TypeScript runs.
const { test } = require('node:test')
const assert = require('node:assert')
const path = require('path')
const { ROOT } = require('./support/studyModel.cjs')
const ids = require(path.join(ROOT, 'helpers/participantIds.ts'))

test('an id is what the server accepts anywhere, up to 128 characters', () => {
	for(const ok of ['P001', 'a', 'Ab-02', 'Ünï cøde-1.2', "o'neil", 'x (1)', 'A'.repeat(128)])
		assert.ok(ids.isValidPid(ok), ok)
	for(const bad of ['', 'bad;id', 'a,b', '<script>', 'a/b', 'a=b', 'A'.repeat(129)])
		assert.ok(!ids.isValidPid(bad), bad)
})

test('a pasted list is read one id per line, and commas, semicolons and tabs separate too', () => {
	assert.deepStrictEqual(ids.parseIdList('P001\nP002\r\nP003'), ['P001', 'P002', 'P003'])
	assert.deepStrictEqual(ids.parseIdList('P001, P002;P003\tP004'), ['P001', 'P002', 'P003', 'P004'])
})

test('ids are trimmed, empty lines dropped, and a repeat in another letter case dropped', () => {
	assert.deepStrictEqual(ids.parseIdList('  P001  \n\n   \np001\nP002\nP001\n'), ['P001', 'P002'])
	assert.deepStrictEqual(ids.parseIdList(''), [])
	assert.deepStrictEqual(ids.parseIdList(' \n , ; '), [])
})

test('invalid ids are named so the researcher can fix them', () => {
	assert.deepStrictEqual(ids.invalidIds(ids.parseIdList('P001\nbad/id\nP002\n<b>')), ['bad/id', '<b>'])
	assert.deepStrictEqual(ids.invalidIds(['P001']), [])
})

test('random ids are 12 characters from the readable alphabet', () => {
	const made = ids.generateIds(200)
	assert.strictEqual(made.length, 200)
	for(const id of made) {
		assert.match(id, /^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}$/)
		assert.ok(ids.isValidPid(id))
	}
	assert.strictEqual(new Set(made).size, 200)
})

test('random ids are unlike one another and unlike the ids already listed, in any letter case', () => {
	// A source that gives the same bytes twice, then new ones: the repeat and the listed id must be skipped.
	const sequence = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], [1, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], [2, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]]
	let call = 0
	const made = ids.generateIds(2, ['bbcdefghjklm'], () => Uint8Array.from(sequence[call++]))
	assert.deepStrictEqual(made, ['ABCDEFGHJKLM', 'CBCDEFGHJKLM'])
	assert.strictEqual(call, 4, 'the repeat and the listed id (bbcdefghjklm, in another case) were each tried and skipped')
})

test('the file of personal links has a row per id and quotes what needs it', () => {
	const csv = ids.inviteLinksCsv(['P001', 'Ab-02'], (id) => `https://x.example/pwa/?key=k&pid=${encodeURIComponent(id)}`)
	assert.strictEqual(csv, 'participant_id;link\r\nP001;https://x.example/pwa/?key=k&pid=P001\r\nAb-02;https://x.example/pwa/?key=k&pid=Ab-02\r\n')
	assert.strictEqual(ids.inviteLinksCsv(['a"b'], () => 'x;y'), 'participant_id;link\r\n"a""b";"x;y"\r\n')
})
