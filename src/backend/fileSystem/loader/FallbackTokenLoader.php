<?php

namespace backend\fileSystem\loader;

use backend\dataClasses\FallbackSetupToken;
use backend\dataClasses\InboundFallbackToken;
use backend\dataClasses\OutboundFallbackToken;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;

class FallbackTokenLoader
{
	private static $setupCache = null;
	private static $inboundCache = null;
	private static $outboundCache = null;

	public static function importInboundFile(): array
	{
		if (self::$inboundCache)
			return self::$inboundCache;
		$path = PathsFS::fileIncomingFallbackTokens();
		return self::$inboundCache = file_exists($path) ? FileSystemBasics::readSerialized(file_get_contents($path), [InboundFallbackToken::class]) : [];
	}

	public static function exportInboundFile(array $inboundTokens)
	{
		$path = PathsFS::fileIncomingFallbackTokens();
		FileSystemBasics::writeSecretFile($path, serialize($inboundTokens));
		self::$inboundCache = $inboundTokens;
	}

	public static function importOutboundFile(): array
	{
		if (self::$outboundCache)
			return self::$outboundCache;
		$path = PathsFS::fileOutboundFallbackTokens();
		return self::$outboundCache = file_exists($path) ? FileSystemBasics::readSerialized(file_get_contents($path), [OutboundFallbackToken::class]) : [];
	}

	public static function exportOutboundFile(array $outboundTokens)
	{
		$path = PathsFS::fileOutboundFallbackTokens();
		FileSystemBasics::writeSecretFile($path, serialize($outboundTokens));
		self::$outboundCache = $outboundTokens;
	}

	public static function importSetupFile(): array
	{
		if (self::$setupCache)
			return self::$setupCache;
		$path = PathsFS::fileFallbackSetupTokens();
		return self::$setupCache = file_exists($path) ? FileSystemBasics::readSerialized(file_get_contents($path), [FallbackSetupToken::class]) : [];
	}

	public static function exportSetupFile(array $setupTokens)
	{
		$path = PathsFS::fileFallbackSetupTokens();
		FileSystemBasics::writeSecretFile($path, serialize($setupTokens));
		self::$setupCache = $setupTokens;
	}
}
