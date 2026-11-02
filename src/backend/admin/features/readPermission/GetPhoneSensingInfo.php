<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\PhoneSensingStore;

/**
 * Status for the study's Phone sensing admin panel: per stream, how many participants have sent data
 * and when the newest upload arrived.
 */
class GetPhoneSensingInfo extends HasReadPermission {
	function exec(): array {
		return ['streams' => PhoneSensingStore::overview($this->studyId)];
	}
}
