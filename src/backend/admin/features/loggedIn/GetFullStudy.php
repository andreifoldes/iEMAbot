<?php

namespace backend\admin\features\loggedIn;

use backend\admin\IsLoggedIn;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\JsonOutput;
use backend\Permission;

class GetFullStudy extends IsLoggedIn {
	/**
	 * The full study config contains the study's access keys and all its content, so it may only be
	 * read by accounts that have a role in THIS study (the same roles that make it show up in
	 * GetStrippedStudyList) or by server admins.
	 */
	const ALLOWED_STUDY_PERMISSIONS = ['read', 'write', 'msg', 'reward'];
	
	function __construct() {
		parent::__construct();
		if($this->studyId == 0)
			throw new PageFlowException('Missing study id');
		if($this->isAdmin)
			return;
		foreach(self::ALLOWED_STUDY_PERMISSIONS as $permCode) {
			if(Permission::hasPermission($this->studyId, $permCode))
				return;
		}
		throw new PageFlowException('No permission');
	}
	
	public function execAndOutput() {
		$studyStore = Configs::getDataStore()->getStudyStore();
		echo JsonOutput::successString('{"config": ' .$studyStore->getStudyConfigAsJson($this->studyId) .', "languages": ' .$studyStore->getAllLangConfigsAsJson($this->studyId) .'}');
	}
	
	function exec(): array {
		throw new CriticalException('Internal error. GetFullStudy can only be used with execAndOutput()');
	}
}