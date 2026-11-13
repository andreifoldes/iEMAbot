<?php

use backend\participants\ParticipantGate;
use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\ParticipantWithdrawals;
use backend\schedule\Schedule;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

/**
 * What the participant app (web and Android) needs to compute the study's schedule itself (web-pwa
 * lib/schedule.ts, the port of backend/schedule/Schedule.php), offline too: the participant's answers the schedule
 * reads (usual wake-up and bedtime, event fallbacks) and the wearable events it reacts to, which only the server has.
 * The app caches it and adds its own completions, so a check-in answered offline re-plans the day at once.
 *
 * GET ?studyId&userId
 * Response dataset: {now, variables: {name: {v: "HH:MM", t}}, events: [{t, provider, kind}]}; both empty when the
 * study has no schedule or the participant has left it.
 */

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$studyId = isset($_GET['studyId']) ? (int) $_GET['studyId'] : 0;
$userId  = isset($_GET['userId']) ? (string) $_GET['userId'] : '';
if($studyId <= 0 || $userId === '' || !Main::strictCheckInput($userId)) {
	echo JsonOutput::error('Missing data');
	return;
}

if($refusal = ParticipantGate::refusal((int) $studyId, (string) $userId)) {
	echo $refusal;
	return;
}

try {
	$now = Main::getMilliseconds();
	$empty = ['now' => $now, 'variables' => (object) [], 'events' => []];
	if(ParticipantWithdrawals::isWithdrawn($studyId, $userId)) {
		echo JsonOutput::successObj($empty);
		return;
	}
	$defaultLang = Configs::get('defaultLang') ?: 'en';
	$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
	if(!is_array($study) || Schedule::config($study) === null) {
		echo JsonOutput::successObj($empty);
		return;
	}
	$inputs = Schedule::inputs($study, $studyId, $userId, $now);
	$variables = array_intersect_key($inputs['variables'], array_flip(Schedule::variableNames($study)));
	$events = array_values(array_filter($inputs['events'], fn($e) => Schedule::wantsEvent($study, (string) $e['provider'], (string) $e['kind'])));
	echo JsonOutput::successObj(['now' => $now, 'variables' => (object) $variables, 'events' => $events]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
