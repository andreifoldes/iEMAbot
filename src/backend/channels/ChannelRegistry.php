<?php
declare(strict_types=1);

namespace backend\channels;

use backend\channels\telegram\TelegramChannel;

/**
 * The chat channels this server knows. Adding a platform (e.g. WhatsApp) means adding an
 * adapter implementing ConversationalChannel and one line here; the conversation engine,
 * stores and dispatcher stay the same.
 */
class ChannelRegistry {
	/** @var array<string, ConversationalChannel> */
	private static $instances = [];

	/** @return array<string, string> id => adapter class */
	public static function classes(): array {
		return [
			TelegramChannel::ID => TelegramChannel::class,
		];
	}

	public static function get(string $id): ?ConversationalChannel {
		if(isset(self::$instances[$id]))
			return self::$instances[$id];
		$class = self::classes()[$id] ?? null;
		return $class === null ? null : (self::$instances[$id] = new $class());
	}

	/** @return ConversationalChannel[] channels with server credentials */
	public static function configured(): array {
		$out = [];
		foreach(array_keys(self::classes()) as $id) {
			$channel = self::get($id);
			if($channel !== null && $channel->isConfigured())
				$out[$id] = $channel;
		}
		return $out;
	}

	/** For tests: replace an adapter. */
	public static function inject(string $id, ?ConversationalChannel $channel): void {
		if($channel === null)
			unset(self::$instances[$id]);
		else
			self::$instances[$id] = $channel;
	}
}
