'use strict'
// Loads the admin editor's real TypeScript data classes (src/frontend/ts) in node, without a bundler: each .ts file is
// transpiled with the repository's own TypeScript when it is first required. Used by the study factory tests, which need the
// classes the editor builds and saves a study with, not a copy of them.
const fs = require('fs')
const path = require('path')
const Module = require('module')
const ts = require('typescript')

const ROOT = path.resolve(__dirname, '../../../../src/frontend/ts')

const compilerOptions = {
	module: ts.ModuleKind.CommonJS,
	target: ts.ScriptTarget.ES2019,
	esModuleInterop: true,
	resolveJsonModule: true,
	jsx: ts.JsxEmit.React,
	jsxFactory: 'm',
	jsxFragmentFactory: 'm.Fragment',
	useDefineForClassFields: false, // class fields are assigned in the constructor, as in the webpack build
}

for(const ext of ['.ts', '.tsx']) {
	if(Module._extensions[ext])
		continue
	Module._extensions[ext] = (module, filename) => {
		const output = ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions, fileName: filename })
		module._compile(output.outputText, filename)
	}
}

// The editor asks mithril to redraw whenever its data changes. There is no page here to draw.
require('mithril').redraw = () => {}

const load = (file) => require(path.join(ROOT, file))

module.exports = {
	ROOT,
	DataStructure: load('data/DataStructure.ts').DataStructure,
	isTranslatable: load('observable/interfaces/Translatable.ts').isTranslatable,
	Study: load('data/study/Study.ts').Study,
	Questionnaire: load('data/study/Questionnaire.ts').Questionnaire,
	Page: load('data/study/Page.ts').Page,
	Input: load('data/study/Input.ts').Input,
	ScheduleCheckin: load('data/study/StudySchedule.ts').ScheduleCheckin,
	SumScore: load('data/study/SumScore.ts').SumScore,
	RepairStudy: load('helpers/RepairStudy.ts').RepairStudy,
	StudyLoader: load('loader/StudyLoader.ts').StudyLoader,
	Requests: load('singletons/Requests.ts').Requests,
	PromiseCache: load('singletons/PromiseCache.ts').PromiseCache,
}
