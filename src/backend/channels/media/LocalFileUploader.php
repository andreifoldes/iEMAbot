<?php
declare(strict_types=1);

namespace backend\channels\media;

use backend\FileUploader;

/**
 * FileUploader for files that already sit on the server (e.g. a voice note the
 * channel adapter downloaded), where move_uploaded_file() — which only accepts
 * HTTP uploads — cannot be used.
 */
class LocalFileUploader extends FileUploader {
	/** @var string */
	private $path;

	public function __construct(string $path) {
		parent::__construct(['tmp_name' => $path]);
		$this->path = $path;
	}

	public function upload(string $targetPath): bool {
		return @rename($this->path, $targetPath) || (@copy($this->path, $targetPath) && @unlink($this->path));
	}
}
