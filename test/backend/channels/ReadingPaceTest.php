<?php

namespace backend\channels;

use backend\channels\telegram\TelegramApiClient;
use backend\channels\telegram\TelegramChannel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * Consecutive bot messages are paced to reading speed, with a typing indicator in between
 * (ReadingPace; same rule as web-pwa/src/lib/pacing.ts). Runs on a virtual clock.
 */
class ReadingPaceTest extends TestCase {
	const CHAT = '987654321';
	const TOUR = "Here's a quick tour. Below is every questionnaire this study uses — you can try a practice run of any of them (nothing you enter is saved). When you're ready, continue to the study.";

	/** @var float virtual time in ms */
	private $now = 0.0;
	/** @var array[] [method, params, time] */
	private $calls = [];

	private function channel(): TelegramChannel {
		$fake = function(string $method, array $params) {
			$this->calls[] = [$method, $params, $this->now];
			return ['ok' => true, 'result' => ['message_id' => count($this->calls)]];
		};
		$pace = new ReadingPace(
			function(): float { return $this->now; },
			function(int $ms) { $this->now += $ms; }
		);
		return new TelegramChannel(new TelegramApiClient('t', $fake), $pace);
	}

	/** @return array[] the sendMessage calls */
	private function sent(): array {
		return array_values(array_filter($this->calls, function(array $c) { return $c[0] === 'sendMessage'; }));
	}

	public function test_counts_words_without_markup() {
		$this->assertSame(5, ReadingPace::countWords('<p>It takes about</p><p>5&nbsp;minutes</p>'));
		$this->assertSame(2, ReadingPace::countWords('Hello — world !'));
	}

	public function test_pause_scales_with_length_between_floor_and_cap() {
		$this->assertSame(ReadingPace::MIN_PAUSE_MS, ReadingPace::pauseMs('Hi'));
		$this->assertSame((int) round(30 / ReadingPace::READING_WPM * 60000), ReadingPace::pauseMs(str_repeat('word ', 30)));
		$this->assertSame(ReadingPace::MAX_PAUSE_MS, ReadingPace::pauseMs(str_repeat('word ', 500)));
	}

	public function test_second_message_waits_for_the_first_to_be_read_with_typing_dots() {
		$channel = $this->channel();
		$channel->send(self::CHAT, OutboundMessage::text(self::TOUR));
		$channel->send(self::CHAT, OutboundMessage::text('What to expect'));

		$sent = $this->sent();
		$this->assertCount(2, $sent);
		$this->assertSame(0.0, $sent[0][2]);
		$this->assertEquals(ReadingPace::pauseMs(self::TOUR), $sent[1][2]);
		$this->assertSame('sendChatAction', $this->calls[1][0]);
		$this->assertSame('typing', $this->calls[1][1]['action']);
	}

	public function test_typing_indicator_is_renewed_during_a_long_wait() {
		$channel = $this->channel();
		$channel->send(self::CHAT, OutboundMessage::text(str_repeat('word ', 500)));
		$channel->send(self::CHAT, OutboundMessage::text('Next'));
		$typing = array_filter($this->calls, function(array $c) { return $c[0] === 'sendChatAction'; });
		$this->assertCount((int) ceil(ReadingPace::MAX_PAUSE_MS / ReadingPace::TYPING_RENEW_MS), $typing);
	}

	public function test_only_waits_for_what_is_left_of_the_reading_time() {
		$channel = $this->channel();
		$channel->send(self::CHAT, OutboundMessage::text('Hi'));
		$this->now += 5000;
		$channel->send(self::CHAT, OutboundMessage::text('Later'));
		$this->assertSame(5000.0, $this->sent()[1][2]);
		$this->assertCount(2, $this->calls); // no typing dots
	}

	public function test_reply_to_the_participant_is_not_held_back() {
		$channel = $this->channel();
		$channel->send(self::CHAT, OutboundMessage::text(self::TOUR));
		$channel->heard(self::CHAT);
		$channel->send(self::CHAT, OutboundMessage::text('Thanks!'));
		$this->assertSame(0.0, $this->sent()[1][2]);
	}

	public function test_other_chats_are_not_held_back() {
		$channel = $this->channel();
		$channel->send(self::CHAT, OutboundMessage::text(self::TOUR));
		$channel->send('42', OutboundMessage::text('Reminder'));
		$this->assertSame(0.0, $this->sent()[1][2]);
	}

	public function test_study_speed_scales_the_pause_and_zero_turns_pacing_off() {
		$this->assertSame(ReadingPace::READING_WPM, ReadingPace::studyWpm([]));
		$this->assertSame(120, ReadingPace::studyWpm(['messagePacingWpm' => 120]));
		$this->assertSame(0, ReadingPace::studyWpm(['messagePacingWpm' => 0]));
		$this->assertSame(0, ReadingPace::studyWpm(['messagePacingWpm' => 'fast']));

		$channel = $this->channel();
		$channel->paceFor(self::CHAT, ['messagePacingWpm' => 120]);
		$channel->send(self::CHAT, OutboundMessage::text(self::TOUR));
		$channel->send(self::CHAT, OutboundMessage::text('What to expect'));
		$this->assertEquals(ReadingPace::pauseMs(self::TOUR, 120), $this->sent()[1][2]);
		$this->assertGreaterThan(ReadingPace::pauseMs(self::TOUR), ReadingPace::pauseMs(self::TOUR, 120));

		$this->calls = [];
		$this->now = 0.0;
		$off = $this->channel();
		$off->paceFor(self::CHAT, ['messagePacingWpm' => 0]);
		$off->send(self::CHAT, OutboundMessage::text(self::TOUR));
		$off->send(self::CHAT, OutboundMessage::text('What to expect'));
		$this->assertSame(0.0, $this->sent()[1][2]);
		$this->assertCount(2, $this->calls);
	}
}
