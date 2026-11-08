<?php

namespace backend\admin\features\noPermission;

use backend\admin\NoPermission;
use backend\Configs;
use backend\exceptions\PageFlowException;
use backend\SelfHost;

class InitESMiraPrep extends NoPermission {
	function exec(): array {
		if(Configs::getDataStore()->isInit() || !SelfHost::firstRunPageAllowed())
			throw new PageFlowException('Disabled');
		
		return Configs::getDataStore()->getESMiraInitializer()->getInfoArray();
	}
}