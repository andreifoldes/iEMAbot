<?php

declare(strict_types=1);

/**
 * Creates (or refreshes) the demo study "Demo: all question types" (access key `demoall`): one
 * on-demand questionnaire that holds EVERY ESMira input type once, for testing the same study in
 * the web PWA and in Telegram side by side. Each item's helper text says what to expect on each
 * channel, so the questionnaire doubles as a manual test checklist.
 *
 * Published for the web and with the Telegram channel switched on. A hidden "Cognitive Trials"
 * questionnaire receives the PVT's per-trial rows (as the PWA expects).
 *
 * Saved through ESMira's own CreateStudy / SaveStudy features (as the admin "Save" button does),
 * so access index, response index and CSV headers are built correctly. Re-running updates the
 * study in place and keeps its questionnaire ids, so earlier test answers stay in the same files.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker compose exec -T -u www-data esmira php -- apply [account] < tools/live-study/create-demo-all-types.php
 * `account` (default: the first admin) gets the study in its admin list.
 */

require_once '/var/www/html/backend/autoload.php';

use backend\admin\features\createPermission\CreateStudy;
use backend\admin\features\writePermission\SaveStudy;
use backend\Configs;
use backend\Main;

const TITLE = 'Demo: all question types';
const ACCESS_KEY = 'demoall';
const BASE = 'https://iemabot.surrey.ac.uk';
const PVT_URL = BASE . '/webapp/m2c2/assessments/pvt-ba/index.html?show_results=true';
const TRIALS_TITLE = 'Cognitive Trials';

$apply = ($argv[1] ?? '') === 'apply';
$account = $argv[2] ?? '';

/** Helper text under an item: what each channel should do. */
function expect(string $web, string $telegram): string {
	return "<p><i>Web:</i> $web<br><i>Telegram:</i> $telegram</p>";
}

function inputs(): array {
	return [
		// ── display-only ──────────────────────────────────────────────
		['header' => '<p><b>1 · Display items</b></p>', 'inputs' => [
			['name' => 'd_text', 'responseType' => 'text',
				'text' => '<p>This demo shows every ESMira question type once. <b>Bold</b>, <i>italic</i> and a <a href="' . BASE . '/esmira/">link</a> should render on both channels.</p>'],
			['name' => 'd_image', 'responseType' => 'image', 'url' => BASE . '/pwa/pwa-512x512.png',
				'text' => '<p>An image item.</p>', 'description' => expect('the image inline', 'sent as a photo')],
			['name' => 'd_video', 'responseType' => 'video', 'url' => BASE . '/pwa/',
				'text' => '<p>A video item (points to a page here, no real video).</p>', 'description' => expect('not rendered (skipped)', 'text with an Open link')],
			['name' => 'd_share', 'responseType' => 'share', 'url' => BASE . '/pwa/',
				'text' => '<p>A share item.</p>', 'description' => expect('not rendered (skipped)', 'text with an Open link')],
		]],
		// ── one tap / buttons ─────────────────────────────────────────
		['header' => '<p><b>2 · Buttons and scales</b></p>', 'inputs' => [
			['name' => 'b_likert', 'responseType' => 'likert', 'likertSteps' => 5, 'required' => true,
				'text' => '<p>Likert: how is your mood?</p>', 'leftSideLabel' => 'very bad', 'rightSideLabel' => 'very good',
				'description' => expect('5 points, stored 1–5', 'buttons 1–5, end labels in the text')],
			['name' => 'b_binary', 'responseType' => 'binary', 'required' => true,
				'text' => '<p>Binary: are you alone right now?</p>', 'leftSideLabel' => 'No', 'rightSideLabel' => 'Yes',
				'description' => expect('two buttons, stored 0/1', 'two buttons, stored 0/1')],
			['name' => 'b_multi', 'responseType' => 'list_multiple', 'relevance' => 'b_binary == 0',
				'text' => '<p>Multiple choice (only shown when not alone): who are you with?</p>', 'listChoices' => ['Partner', 'Family', 'Friends', 'Colleagues'],
				'description' => expect('checkboxes → b_multi~1..4', '✅ toggle buttons + Done → b_multi~1..4')],
			['name' => 'b_single', 'responseType' => 'list_single', 'required' => true, 'other' => true,
				'text' => '<p>Single choice: where are you?</p>', 'listChoices' => ['Home', 'Work', 'Outside', 'Somewhere else'],
				'description' => expect('"Somewhere else" asks for text → b_single~other', 'same, as a typed reply')],
			['name' => 'b_vas', 'responseType' => 'va_scale', 'maxValue' => 100,
				'text' => '<p>Visual analogue scale: how alert do you feel?</p>', 'leftSideLabel' => 'not at all', 'rightSideLabel' => 'very',
				'description' => expect('slider 0–100', 'slider in a Mini App (or 0–100 buttons without one)')],
		]],
		// ── typed answers ─────────────────────────────────────────────
		['header' => '<p><b>3 · Typed answers</b></p>', 'inputs' => [
			['name' => 't_text', 'responseType' => 'text_input', 'maxLength' => 200,
				'text' => '<p>Free text: anything on your mind?</p>', 'description' => expect('text box', 'type a message (max 200 characters)')],
			['name' => 't_int', 'responseType' => 'number',
				'text' => '<p>Whole number: cups of coffee today?</p>', 'description' => expect('number field', 'typed; decimals refused')],
			['name' => 't_dec', 'responseType' => 'number', 'numberHasDecimal' => true,
				'text' => '<p>Decimal number: your body temperature (°C)?</p>', 'description' => expect('number field', 'typed; 36.6 or 36,6')],
			['name' => 't_time', 'responseType' => 'time',
				'text' => '<p>Time: when did you go to bed last night?</p>', 'description' => expect('hour/minute picker → HH:MM', 'typed: 23:30, 11pm, 7.15am → HH:MM')],
			['name' => 't_howlong', 'responseType' => 'time',
				'text' => '<p>Time item phrased "how long": how long did it take you to fall asleep?</p>',
				'description' => expect('duration picker → minutes', 'typed: 20m, 1h 10m → minutes')],
			['name' => 't_duration', 'responseType' => 'duration',
				'text' => '<p>Duration: how long did you sleep?</p>', 'description' => expect('hours + minutes → minutes', 'typed: 7h 30m, 7:30, 450 → minutes')],
			['name' => 't_date', 'responseType' => 'date',
				'text' => '<p>Date: which day are you reporting on?</p>', 'description' => expect('date picker → YYYY-MM-DD', 'Today/Yesterday buttons or typed 14/03/2026')],
		]],
		// ── special logic ─────────────────────────────────────────────
		['header' => '<p><b>4 · Special items</b></p>', 'inputs' => [
			['name' => 's_dynamic', 'responseType' => 'dynamic_input', 'text' => '<p>Dynamic input (a different sub-question each time):</p>',
				'description' => expect('not rendered (skipped)', 'one of the sub-questions, rotating → s_dynamic~index'),
				'subInputs' => [
					['responseType' => 'likert', 'likertSteps' => 5, 'text' => '<p>How stressed are you?</p>', 'leftSideLabel' => 'not at all', 'rightSideLabel' => 'very'],
					['responseType' => 'likert', 'likertSteps' => 5, 'text' => '<p>How energetic are you?</p>', 'leftSideLabel' => 'not at all', 'rightSideLabel' => 'very'],
				]],
			['name' => 's_countdown', 'responseType' => 'countdown', 'timeoutSec' => 10,
				'text' => '<p>Countdown: close your eyes and breathe slowly.</p>', 'description' => expect('not rendered (skipped)', 'message + Continue button')],
		]],
		// ── media and device ──────────────────────────────────────────
		['header' => '<p><b>5 · Media and device</b></p>', 'inputs' => [
			['name' => 'm_audio', 'responseType' => 'record_audio',
				'text' => '<p>Voice memo: tell us briefly about your day.</p>', 'description' => expect('recorder; Skip opens the typed fallback', 'send a voice message; Skip opens the typed fallback')],
			['name' => 'm_audio_typed', 'responseType' => 'record_keystrokes', 'minLength' => 20, 'text' => '',
				'description' => expect('only after skipping the memo: writing box with keystroke logging', 'only after skipping: Mini App writer (or a plain typed answer)')],
			['name' => 'm_keystrokes', 'responseType' => 'record_keystrokes', 'minLength' => 20,
				'text' => '<p>Keystroke-logged writing: describe your morning in a few words.</p>',
				'description' => expect('writing box with keystroke logging', 'Mini App writer (or a plain typed answer, capture_mode chat)')],
			['name' => 'm_photo', 'responseType' => 'photo',
				'text' => '<p>Photo: take a picture of what is in front of you.</p>', 'description' => expect('not rendered (skipped)', 'send a photo')],
			['name' => 'm_file', 'responseType' => 'file_upload',
				'text' => '<p>File upload: send any image file.</p>', 'description' => expect('not rendered (skipped)', 'send a photo or document')],
			['name' => 'm_location', 'responseType' => 'location',
				'text' => '<p>Location: where are you, approximately?</p>', 'description' => expect('not rendered (skipped)', 'Share location button (or Skip)')],
		]],
		// ── cognitive task ────────────────────────────────────────────
		['header' => '<p><b>6 · Cognitive task</b></p>', 'inputs' => [
			['name' => 'c_pvt', 'responseType' => 'webapp', 'url' => PVT_URL, 'text' => 'Reaction-time game',
				'webappDescription' => 'A short reaction-time game (PVT-BA). Tap Start, then tap as soon as the counter appears.',
				'description' => expect('task in a full-screen frame; trials → Cognitive Trials', 'task in a Mini App (or skipped without one)')],
		]],
		// ── phone sensors ─────────────────────────────────────────────
		['header' => '<p><b>7 · Phone sensors</b> (native apps only)</p>', 'inputs' => [
			['name' => 'x_light', 'responseType' => 'ambient_light', 'text' => '<p>Ambient light</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_apps', 'responseType' => 'app_usage', 'text' => '<p>App usage</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_battery', 'responseType' => 'battery_level', 'text' => '<p>Battery level</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_bluetooth', 'responseType' => 'bluetooth_devices', 'text' => '<p>Bluetooth devices</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_compass', 'responseType' => 'compass', 'text' => '<p>Compass</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_noise', 'responseType' => 'noise_level', 'text' => '<p>Noise level</p>', 'description' => expect('skipped', 'skipped')],
			['name' => 'x_end', 'responseType' => 'text', 'text' => '<p>That was every question type. Thanks for testing!</p>'],
		]],
	];
}

function trialsQuestionnaire(int $internalId): array {
	$fields = ['cogAssessment', 'cogSource', 'cogInputName', 'cogSession', 'cogTrialIndex', 'cogRt', 'cogCorrect', 'cogResponse', 'cogStimulus', 'cogRaw'];
	return [
		'internalId' => $internalId, 'title' => TRIALS_TITLE,
		'pages' => [['inputs' => array_map(function($f) { return ['name' => $f, 'responseType' => 'text_input']; }, $fields)]],
	];
}

// ── find an existing demo (re-run = update in place) ─────────────────────────
$studyStore = Configs::getDataStore()->getStudyStore();
$existing = null;
foreach($studyStore->getStudyIdList() as $id) {
	$cfg = $studyStore->getStudyConfig((int) $id);
	if(($cfg->title ?? '') === TITLE)
		$existing = $cfg;
}
$idsByTitle = [];
foreach(($existing->questionnaires ?? []) as $q)
	$idsByTitle[$q->title] = (int) $q->internalId;

$studyId = $existing !== null ? (int) $existing->id : 0;
while($studyId === 0 || ($existing === null && $studyStore->studyExists($studyId)))
	$studyId = random_int(10000000, 99999999);

$study = [
	'id' => $studyId,
	'version' => (int) ($existing->version ?? 1),
	'subVersion' => (int) ($existing->subVersion ?? -1) + 1,
	'serverVersion' => Main::SERVER_VERSION,
	'new_changes' => true,
	'title' => TITLE,
	'published' => true,
	'accessKeys' => [ACCESS_KEY],
	'langCodes' => ['en'],
	'defaultLang' => 'en',
	'studyDescription' => '<p>A demo study that contains <b>every ESMira question type</b>, for testing the web app and Telegram side by side. Not for real participants.</p>',
	'informedConsentForm' => '<p>This is a test study. Answers are stored on this server and only used to check that every question type works.</p>',
	'channels' => ['telegram' => ['enabled' => true]],
	'publicStatistics' => ['charts' => []],
	'personalStatistics' => ['charts' => []],
	'questionnaires' => [
		['internalId' => $idsByTitle['All question types'] ?? random_int(100000000, 999999999), 'title' => 'All question types', 'pages' => inputs()],
		trialsQuestionnaire($idsByTitle[TRIALS_TITLE] ?? random_int(100000000, 999999999)),
	],
];

$count = 0;
foreach($study['questionnaires'][0]['pages'] as $p)
	$count += count($p['inputs']);
echo ($existing !== null ? "Will update" : "Will create") . " study $studyId \"" . TITLE . "\" (key " . ACCESS_KEY . "): $count items on "
	. count($study['questionnaires'][0]['pages']) . " pages + hidden " . TRIALS_TITLE . "\n";
if(!$apply) {
	echo "Dry run only — re-run with `apply` to save.\n";
	exit(0);
}

// Act as an admin account so CreateStudy/SaveStudy run exactly as from the admin UI.
if($account === '') {
	foreach(Configs::getDataStore()->getAccountStore()->getAccountList() as $name) {
		if(!empty(Configs::getDataStore()->getAccountStore()->getPermissions($name)['admin'])) {
			$account = $name;
			break;
		}
	}
}
$_SESSION = ['account' => $account, 'is_loggedIn' => true];
$_GET['study_id'] = $studyId;
$_GET['lastChanged'] = PHP_INT_MAX;
Main::$defaultPostInput = json_encode(['_' => $study]);
if($existing !== null)
	(new SaveStudy())->exec();
else
	(new CreateStudy())->exec();

$saved = $studyStore->getStudyConfig($studyId);
echo "Saved study $studyId: published=" . json_encode($saved->published ?? null)
	. ", telegram=" . json_encode($saved->channels->telegram->enabled ?? null)
	. ", questionnaires=" . count($saved->questionnaires ?? []) . "\n";
echo "Web:      " . BASE . "/pwa/?key=" . ACCESS_KEY . "\n";
echo "Telegram: php cli/telegram_setup.php invite $studyId " . ACCESS_KEY . "\n";
