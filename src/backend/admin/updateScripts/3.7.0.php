<?php

/*
Reason for update:
	3.7.0 gives every study one schedule (Study.schedule, see backend/schedule/Schedule.php): which check-ins open
	when and when participants are prompted, on every channel. Older configs schedule per questionnaire (signal
	times, sensor triggers, completion filters, active periods) or with the daily check-in plan; ScheduleUpgrade
	turns them into the schedule and removes the old fields. Each study's config files are backed up first to
	<data folder>/backups/schedule-upgrade/<studyId>/.
*/

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\ResponsesIndex;
use backend\schedule\ScheduleUpgrade;

$studyStore = Configs::getDataStore()->getStudyStore();

foreach($studyStore->getStudyIdList() as $studyId) {
	try {
		$mainStudy = json_decode($studyStore->getStudyConfigAsJson($studyId));
		if(!($mainStudy instanceof stdClass) || !ScheduleUpgrade::needed(json_decode(json_encode($mainStudy), true)))
			continue;
		$langs = json_decode($studyStore->getAllLangConfigsAsJson($studyId));

		// Back up the config files (not the responses) before anything is changed.
		$backup = PathsFS::folderData() . "backups/schedule-upgrade/$studyId/";
		if(!is_dir($backup) && !mkdir($backup, 0744, true) && !is_dir($backup))
			throw new RuntimeException("Could not create $backup");
		copy(PathsFS::fileStudyConfig($studyId), $backup . '.config.json');
		foreach(glob(PathsFS::folderLangs($studyId) . '*.json') ?: [] as $langFile)
			copy($langFile, $backup . basename($langFile));

		$notes = [];
		$collection = (object) ['_' => ScheduleUpgrade::upgradeObject($mainStudy, $notes)];
		foreach($langs ?? [] as $code => $langConfig)
			$collection->$code = ScheduleUpgrade::upgradeObject($langConfig);

		// Same questionnaires and items: rebuild their ResponsesIndex as SaveStudy does.
		$keys = [];
		foreach($collection->_->questionnaires ?? [] as $questionnaire) {
			$index = new ResponsesIndex();
			foreach($questionnaire->pages ?? [] as $page) {
				foreach($page->inputs ?? [] as $input) {
					if(isset($input->name))
						$index->addInput($input);
				}
			}
			foreach($questionnaire->sumScores ?? [] as $score) {
				if(isset($score->name))
					$index->addName($score->name);
			}
			foreach($questionnaire->virtualInputs ?? [] as $virtualInput) {
				if(is_string($virtualInput))
					$index->addName($virtualInput);
			}
			$keys[$questionnaire->internalId] = $index;
		}
		$studyStore->saveStudy($collection, $keys);
		if($notes)
			Main::report("Schedule upgrade of study $studyId:\n- " . implode("\n- ", $notes));
	}
	catch(Throwable $e) {
		Main::reportError($e, "There was an error upgrading the schedule of study $studyId during server update.");
	}
}
