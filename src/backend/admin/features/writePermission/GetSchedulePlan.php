<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\schedule\ScheduleCalendar;

/**
 * The calendar's planning view (ScheduleCalendar::plan): the windows a sample participant gets, for the study as it
 * is being edited (posted, so unsaved changes show). Body: {study, joined (local ms), days, variables: {name: "HH:MM"}}.
 */
class GetSchedulePlan extends HasWritePermission {
	function exec(): array {
		$input = json_decode(Main::getRawPostInput(), true);
		if(!is_array($input) || !is_array($input['study'] ?? null))
			throw new PageFlowException('Missing study');
		return ScheduleCalendar::plan(
			$input['study'],
			(int) ($input['joined'] ?? 0),
			is_array($input['variables'] ?? null) ? $input['variables'] : [],
			(int) ($input['days'] ?? 14)
		);
	}
}
