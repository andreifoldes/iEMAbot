<?php

use backend\participants\ParticipantGate;
use backend\Configs;
use backend\fileSystem\loader\UserDataLoader;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\ParticipantNotificationPrefs;
use backend\notifications\ParticipantWithdrawals;
use backend\schedule\Schedule;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

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
	// Left the study (sign-out / quit event, or withdrawn by a researcher): the app resets itself
	// and stops its own reminders when it sees this. Checked first, so it also works for studies
	// without web push (the native Android app plans its reminders on the device).
	if(ParticipantWithdrawals::isWithdrawn($studyId, $userId)) {
		echo JsonOutput::successObj(['nextNotification' => null, 'joinedAt' => null, 'withdrawn' => true]);
		return;
	}
	// Switched off in the app (on this or another device): the app shows it and keeps the switch in step.
	$notificationsOff = ParticipantNotificationPrefs::isOff($studyId, $userId);
	$defaultLang = Configs::get('defaultLang') ?: 'en';
	$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
	if(!is_array($study) || empty($study['webPushEnabled'])) {
		echo JsonOutput::successObj(['nextNotification' => null, 'joinedAt' => null, 'withdrawn' => false, 'notificationsOff' => $notificationsOff]);
		return;
	}

	$now = Main::getMilliseconds();
	$anchor = $now;
	$tz = 0;
	$subFile = PathsFS::filePushSubscription($studyId, $userId);
	if(file_exists($subFile)) {
		$data = json_decode(file_get_contents($subFile), true);
		if(is_array($data)) {
			$anchor = (int) ($data['created'] ?? $now);
			$tz = (int) ($data['tzOffset'] ?? 0);
		}
	}
	$userDataPath = PathsFS::fileUserData($studyId, $userId);
	if(file_exists($userDataPath)) {
		try {
			$ud = UserDataLoader::import(file_get_contents($userDataPath));
			if(!empty($ud->joinedTime))
				$anchor = (int) $ud->joinedTime;
		}
		catch(Throwable $e) { /* use subscribe time */ }
	}

	// Earliest prompt or reminder in the next 14 days, from the schedule (random draws are deterministic, so this is
	// the time the sender will actually use; a wake-up or event not known yet counts as its fallback).
	$horizon = $now + 14 * 86400000;
	$occurrences = Schedule::upcoming($study, $studyId, $userId, Schedule::inputs($study, $studyId, $userId, $now), $anchor, $tz, $now, $now, $horizon);
	$next = null;
	foreach($occurrences as $occ) {
		if($next === null || $occ['timestamp'] < $next)
			$next = $occ['timestamp'];
	}
	// joinedAt is the schedule anchor the server actually used (join time, else subscribe
	// time). The client adopts it so an already-enrolled participant's timeline isn't
	// shifted by a client-side backfill. Null when there's no subscription on file.
	$joinedAt = file_exists($subFile) ? $anchor : null;
	echo JsonOutput::successObj(['nextNotification' => $notificationsOff ? null : $next, 'joinedAt' => $joinedAt, 'withdrawn' => false, 'notificationsOff' => $notificationsOff]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
