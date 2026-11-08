<?php

namespace backend\fileSystem\loader;

use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;

class StudyAccessKeyIndexLoader {
	public static function importFile(): array {
		$path = PathsFS::fileStudyIndex();
		return file_exists($path) ? FileSystemBasics::readSerialized(file_get_contents($path), false) : [];
	}
	
	/**
	 * @throws CriticalException
	 */
	public static function exportFile(array $studyIndex) {
		FileSystemBasics::writeFile(PathsFS::fileStudyIndex(), serialize($studyIndex));
	}
}