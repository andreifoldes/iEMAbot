<?php

namespace backend\fileSystem\loader;


use backend\dataClasses\UserData;
use backend\exceptions\CriticalException;
use backend\FileSystemBasics;
use backend\Main;

class UserDataLoader {
	/**
	 * @throws CriticalException
	 */
	public static function import(string $data): UserData {
		$userData = FileSystemBasics::readSerialized($data, [UserData::class]);
		if($userData === false)
			throw new CriticalException("Could not serialize data: \n$data");
		
		return $userData;
	}
	
	public static function export(UserData $userToken): string {
		return serialize($userToken);
	}
}