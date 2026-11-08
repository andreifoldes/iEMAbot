'use strict'
// Each case is one study built the way a researcher builds it in the admin editor (the actions are the editor's own: addQuestionnaire,
// inputs.push, setters, addLanguage ...). study-cli/tests/fixtures/<name>.spec.json is the same study as a spec for the CLI;
// the PHP tests require the factory to turn that spec into exactly the payload the editor produced here.
const HOUR = 3600000

module.exports = [
	{
		name: 'minimal',
		title: 'Minimal study',
		async edit(study, loader) {
			const q = await loader.addQuestionnaire(study, { title: 'Check-in', pages: [{}] })
			q.pages.get()[0].inputs.push({ name: 'note' })
		},
	},
	{
		name: 'full',
		title: 'Sleep and mood study',
		async edit(study, loader) {
			study.published.set(true)
			study.accessKeys.push('sleepmood')
			study.accessKeys.push('sm2026')
			study.studyDescription.set('<p>How you sleep and feel.</p>')
			study.informedConsentForm.set('You agree to take part.')
			study.contactEmail.set('lab@example.org')
			study.webPhoneOnly.set(true)
			study.messagePacingWpm.set(200)
			study.sendMessagesAllowed.set(true) // already the default: left out of the payload
			study.wearablesEnabled.set(true)
			study.wearablesProviders.push('withings')
			study.channels.telegram.enabled.set(true)
			study.eventUploadSettings.notification.set(true)

			const morning = await loader.addQuestionnaire(study, { title: 'Morning check-in', pages: [{}] })
			const page1 = morning.pages.get()[0]
			page1.header.set('Sleep')
			const bed = page1.inputs.push({ name: 'bed_time' })
			bed.responseType.set('time')
			bed.text.set('When did you go to bed?')
			const quality = page1.inputs.push({ name: 'sleep_quality' })
			quality.responseType.set('likert')
			quality.likertSteps.set(7)
			quality.required.set(true)
			quality.leftSideLabel.set('bad')
			quality.rightSideLabel.set('good')
			const note = page1.inputs.push({ name: 'sleep_note' })
			note.maxLength.set(200)
			note.required.set(false) // already the default
			morning.pages.push({})
			const page2 = morning.pages.get()[1]
			page2.relevance.set('sleep_quality > 3')
			const mood = page2.inputs.push({ name: 'mood_today' })
			mood.responseType.set('list_single')
			mood.listChoices.push('Good')
			mood.listChoices.push('Okay')
			mood.listChoices.push('Bad')
			mood.other.set(true)
			const activities = page2.inputs.push({ name: 'activities' })
			activities.responseType.set('list_multiple')
			activities.listChoices.push('Walk')
			activities.listChoices.push('Read')
			morning.sumScores.push({ name: 'total_score' })
			morning.sumScores.get()[0].addList.push('sleep_quality')
			morning.virtualInputs.push('derived_flag')

			const evening = await loader.addQuestionnaire(study, { title: 'Evening', pages: [{}] })
			evening.showPagination.set(false)
			const page = evening.pages.get()[0]
			const dynamic = page.inputs.push({ name: 'dyn_item' })
			dynamic.responseType.set('dynamic_input')
			dynamic.subInputs.push({ responseType: 'likert', text: 'How stressed are you?' })
			dynamic.subInputs.push({ responseType: 'likert', text: 'How energetic are you?' })
			const task = page.inputs.push({ name: 'reaction_task' })
			task.responseType.set('webapp')
			task.url.set('https://example.org/task/index.html')
			task.text.set('Reaction game')
			task.webappDescription.set('Tap as fast as you can.')
			const voice = page.inputs.push({ name: 'voice_memo' })
			voice.responseType.set('record_audio')
			voice.maxLength.set(120)
			const typed = page.inputs.push({ name: 'typed_memo' })
			typed.responseType.set('record_keystrokes')
			typed.minLength.set(20)
			const slider = page.inputs.push({ name: 'energy_slider' })
			slider.responseType.set('va_scale')
			slider.maxValue.set(100)
			slider.instruction.set('Slide to your level.')
			const picture = page.inputs.push({ name: 'show_picture' })
			picture.responseType.set('image')
			picture.url.set('https://example.org/picture.png')
			picture.imageAlt.set('A kitchen')

			study.schedule.dayStart.set(4 * HOUR)
			study.schedule.checkins.push({
				questionnaireId: morning.internalId.get(),
				startType: 'fixed', startTime: 6 * HOUR, endType: 'fixed', endTime: 10 * HOUR, prompt: 'random', reminderCount: 2,
			})
			study.schedule.checkins.push({ questionnaireId: evening.internalId.get(), limit: 'day' })
		},
	},
	{
		name: 'multilang',
		title: 'Mood study',
		async edit(study, loader) {
			study.studyDescription.set('About the study.')
			const q = await loader.addQuestionnaire(study, { title: 'Daily', pages: [{ header: 'Today' }] })
			const page = q.pages.get()[0]
			const mood = page.inputs.push({ name: 'mood_now', responseType: 'list_single', text: 'How do you feel?', listChoices: ['Good', 'Bad'] })
			page.inputs.push({ name: 'only_english', text: 'No translation for this one.' })
			page.inputs.push({ name: 'shared_url', responseType: 'image', url: 'https://example.org/a.png', text: 'Look' })
			study.schedule.checkins.push({ questionnaireId: q.internalId.get() })

			study.langCodes.push('de')
			study.addLanguage('de')
			study.currentLangCode.set('de')
			study.title.set('Stimmungsstudie')
			study.studyDescription.set('Über die Studie.')
			q.title.set('Täglich')
			page.header.set('Heute')
			mood.text.set('Wie fühlst du dich?')
			mood.listChoices.get()[0].set('Gut')
			mood.listChoices.get()[1].set('Schlecht')
			study.currentLangCode.set('en')
		},
	},
]
