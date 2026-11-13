<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\participants\ParticipantAllowList;

/**
 * The participant ids a study lists, each with whether the study has already seen that participant, and how many
 * participants have joined without being on the list (all of them, if the study was open before it got a list).
 */
class GetAllowedPids extends HasWritePermission {
	/** How many unlisted participants are named; the rest are counted. */
	const NAMED_UNLISTED_MAX = 200;
	
	function exec(): array {
		$listed = ParticipantAllowList::ids($this->studyId);
		$known = array_flip(ParticipantAllowList::knownIds($this->studyId));
		$listedSet = array_flip($listed);
		
		$ids = [];
		foreach($listed as $id)
			$ids[] = ['id' => $id, 'joined' => isset($known[$id])];
		
		$unlisted = [];
		foreach($known as $id => $_)
			if(!isset($listedSet[$id]))
				$unlisted[] = (string) $id;
		sort($unlisted);
		
		return [
			'enforced' => ParticipantAllowList::isEnforced($this->studyId),
			'ids' => $ids,
			'unlistedJoinedCount' => sizeof($unlisted),
			'unlistedJoined' => array_slice($unlisted, 0, self::NAMED_UNLISTED_MAX),
			'maxIds' => ParticipantAllowList::MAX_IDS,
		];
	}
}
