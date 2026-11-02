<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\Configs;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\schedule\ScheduleCalendar;

/**
 * The calendar's monitoring view (ScheduleCalendar): every participant's compliance so far, or with `user_id` one
 * participant's windows day by day, each completed, covered, missed, open or upcoming.
 */
class GetScheduleMonitor extends HasReadPermission {
	function exec(): array {
		$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($this->studyId, Configs::get('defaultLang') ?: 'en'), true);
		if(!is_array($study))
			throw new PageFlowException('Study not found');
		$now = Main::getMilliseconds();
		$userId = trim((string) ($_POST['user_id'] ?? $_GET['user_id'] ?? ''));
		$result = $userId === ''
			? ScheduleCalendar::overview($study, $this->studyId, $now)
			: ScheduleCalendar::participant($study, $this->studyId, $userId, $now);
		return $result + ['now' => $now];
	}
}
