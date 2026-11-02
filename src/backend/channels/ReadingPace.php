<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * Reading-speed pacing for chat channels: when the bot sends several messages in a row, each one
 * waits until the previous one has had time to be read, with the channel's typing indicator in
 * between. The first message after the participant wrote or tapped something is never held back
 * (see heard()). The speed is a study setting (`messagePacingWpm`, words per minute; 0 = off), passed in
 * with setSpeed() once the chat's study is known. Same rule and constants as the PWA
 * (web-pwa/src/lib/pacing.ts); keep them in step.
 *
 * State is per process: the poller keeps one channel for its whole run, a webhook request starts
 * afresh (its first message has nothing to wait for).
 */
class ReadingPace {
	/** Average silent reading rate of adults for English non-fiction (Brysbaert, 2019); the default speed. */
	const READING_WPM = 238;
	/** Even a one-word message gets a beat before the next one arrives. */
	const MIN_PAUSE_MS = 1200;
	/** Long messages stay readable after the next one arrives, so never keep the reader waiting longer. */
	const MAX_PAUSE_MS = 10000;
	/** Typing indicators fade after a few seconds (Telegram: 5 s), so they are renewed this often. */
	const TYPING_RENEW_MS = 4000;

	/** @var callable(): float milliseconds */
	private $clock;
	/** @var callable(int): void sleeps for the given milliseconds */
	private $sleep;
	/** @var bool */
	private $enabled;
	/** @var array<string, array{0: float, 1: int}> chatRef => [sent at (ms), its reading pause (ms)] */
	private $last = [];
	/** @var array<string, int> chatRef => words per minute of its study (READING_WPM when unknown) */
	private $wpm = [];

	public function __construct(?callable $clock = null, ?callable $sleep = null, bool $enabled = true) {
		$this->clock = $clock ?? function(): float { return microtime(true) * 1000; };
		$this->sleep = $sleep ?? function(int $ms): void { usleep($ms * 1000); };
		$this->enabled = $enabled;
	}

	/** No pauses at all (tests that are not about pacing). */
	public static function off(): self {
		return new self(null, null, false);
	}

	/** A study's reading speed in words per minute; 0 = no pacing. */
	public static function studyWpm(array $study): int {
		$wpm = $study['messagePacingWpm'] ?? self::READING_WPM;
		return is_numeric($wpm) && $wpm > 0 ? (int) $wpm : 0;
	}

	/** Words in a message; markup and entities are not counted. */
	public static function countWords(string $html): int {
		$text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		return count(array_filter($words, function(string $w): bool { return preg_match('/[\p{L}\p{N}]/u', $w) === 1; }));
	}

	/** How long to wait after a message before sending the next one, in milliseconds. */
	public static function pauseMs(string $html, int $wpm = self::READING_WPM): int {
		if($wpm <= 0)
			return 0;
		$ms = self::countWords($html) / $wpm * 60000;
		return (int) round(min(self::MAX_PAUSE_MS, max(self::MIN_PAUSE_MS, $ms)));
	}

	/** Messages to $chatRef are paced for its study from now on. */
	public function setSpeed(string $chatRef, array $study): void {
		$this->wpm[$chatRef] = self::studyWpm($study);
	}

	/** The participant wrote or tapped something: the next message answers them and goes out at once. */
	public function heard(string $chatRef): void {
		unset($this->last[$chatRef]);
	}

	/**
	 * Wait until the previous message to $chatRef has had time to be read. $typing is called
	 * before each stretch of waiting, to show (and keep showing) the typing indicator.
	 */
	public function waitBefore(string $chatRef, callable $typing): void {
		if(!$this->enabled || !isset($this->last[$chatRef]))
			return;
		[$sentAt, $pause] = $this->last[$chatRef];
		while(($remaining = (int) ceil($sentAt + $pause - ($this->clock)())) > 0) {
			$typing();
			($this->sleep)(min($remaining, self::TYPING_RENEW_MS));
		}
	}

	/** A message with $html went out to $chatRef just now. */
	public function sent(string $chatRef, string $html): void {
		if($this->enabled)
			$this->last[$chatRef] = [($this->clock)(), self::pauseMs($html, $this->wpm[$chatRef] ?? self::READING_WPM)];
	}
}
