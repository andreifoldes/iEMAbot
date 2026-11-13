<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\participants\ParticipantAllowList;

/**
 * Replace the list of participant ids a study lets in. Body: {"ids": ["P001", ...]}. Whether the list is used at
 * all is the study's `pidMode` setting, saved with the study; the list can be prepared before it is switched on.
 */
class SetAllowedPids extends HasWritePermission {
	function exec(): array {
		$json = json_decode(Main::getRawPostInput(), true);
		if(!is_array($json) || !isset($json['ids']) || !is_array($json['ids']))
			throw new PageFlowException('Unexpected data');
		try {
			$saved = ParticipantAllowList::replace($this->studyId, $json['ids']);
		}
		catch(CriticalException $e) {
			throw new PageFlowException($e->getMessage());
		}
		return ['saved' => sizeof($saved), 'ids' => $saved];
	}
}
