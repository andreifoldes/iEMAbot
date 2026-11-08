<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\sqlite\ResponsesExport;

class GetData extends HasReadPermission {
	// TODO: LIVE CHECK V19, rest (needs Excel): open a downloaded XLSX in Excel. Tracker Part 1. Done 2026-11-06: the CSV and XLSX links of
	// the data table answer with X-Export-Source: sqlite.
	public function execAndOutput() {
		if(!isset($_GET['q_id']))
			throw new PageFlowException('Missing data');
		// "format" is for researchers' downloads (csv with a formula guard, or xlsx), built from the database only. Without it the
		// admin screens get the plain feed they have always read.
		if(isset($_GET['format']) && $_GET['format'] !== '') {
			ResponsesExport::download($this->studyId, (string) $_GET['q_id'], (string) $_GET['format'], (string) Configs::get('csv_delimiter'));
			return;
		}
		Configs::getDataStore()->getResponsesStore()->outputResponsesFile($this->studyId, $_GET['q_id']);
	}
	
	function exec(): array {
		throw new CriticalException('Internal error. GetData can only be used with execAndOutput()');
	}
}