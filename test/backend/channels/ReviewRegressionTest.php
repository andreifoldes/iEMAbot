<?php

namespace backend\channels;

use backend\channels\conversation\ButtonData;
use backend\channels\conversation\ConversationService;
use backend\channels\dispatch\ChannelDispatcher;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\InviteStore;
use backend\channels\telegram\TelegramChannel;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;

require_once __DIR__ . '/../../autoload.php';

/** Regressions for issues found in the code review of the Telegram channel. */
class ReviewRegressionTest extends ChannelFlowTestCase {
	private function csvRows(): int {
		return count(file(PathsFS::fileResponses(self::STUDY, (string) self::QID))) - 1;
	}

	private function startMorning(array $binding, int $now, array $occurrence = []): void {
		$this->service->withState($binding, function(array &$state) use ($binding, $now, $occurrence) {
			$this->service->startQuestionnaire($binding, $state, ChannelStudies::load(self::STUDY), ['qid' => self::QID] + $occurrence, $now);
		});
	}

	/** A failed "thank you" message must not roll back the state and save the answers twice. */
	function test_send_failure_after_saving_does_not_duplicate_answers() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->startMorning($binding, $now);
		$this->press($this->buttonData('Yes'), $now);
		$quality = $this->buttonData('4');
		$this->failSend = 'Your answers have been saved';
		$this->press($quality, $now);
		$this->assertSame(1, $this->csvRows());
		$this->press($quality, $now); // pressing again is a stale press now
		$this->assertSame(1, $this->csvRows());
	}

	/** An undelivered question is re-sent by the dispatcher. */
	function test_undelivered_prompt_is_resent() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->failSend = 'Rested?';
		$this->startMorning($binding, $now);
		$this->failSend = null;
		$this->calls = [];
		ChannelDispatcher::run($now);
		$this->assertStringContainsString('Rested?', $this->sentTexts());
	}

	/** Sessions without a deadline still end, so later questionnaires are not blocked forever. */
	function test_session_without_deadline_expires() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->startMorning($binding, $now);
		$state = $this->service->withState($binding, function(array &$state) { return $state; });
		$this->assertSame($now + 180 * 60000, $state['session']['deadline']);
		$this->calls = [];
		ChannelDispatcher::run($now + 181 * 60000);
		$this->assertStringContainsString('no longer available', $this->sentTexts());
	}

	/** Answers that could not be saved wait in the outbox and are saved by the dispatcher. */
	function test_failed_save_is_retried_from_the_outbox() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		Configs::getDataStore()->getStudyStore()->lockStudy(self::STUDY); // saving fails while the study is locked
		$this->startMorning($binding, $now);
		$this->press($this->buttonData('Yes'), $now);
		$this->press($this->buttonData('5'), $now);
		$this->assertStringContainsString('will be saved as soon as', $this->sentTexts());
		$this->assertSame(0, $this->csvRows());
		Configs::getDataStore()->getStudyStore()->lockStudy(self::STUDY, false);
		ChannelDispatcher::run($now + 60000);
		$this->assertSame(1, $this->csvRows());
		ChannelDispatcher::run($now + 120000);
		$this->assertSame(1, $this->csvRows());
	}

	/** /timezone must not swallow answers to an open questionnaire. */
	function test_timezone_question_does_not_swallow_answers() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->startMorning($binding, $now);
		$this->text('/timezone', $now);
		$this->press($this->buttonData('Yes'), $now);
		$state = $this->service->withState($binding, function(array &$state) { return $state; });
		$this->assertSame('1', $state['session']['answers']['responses']['rested']);
	}

	/** A participant invite works once; a forwarded copy cannot take the participant over. */
	function test_pid_invite_is_single_use() {
		$now = Main::getMilliseconds();
		$payload = InviteStore::payloadFor(self::STUDY, 'key1', 'P-007');
		$this->text("/start $payload", $now);
		$this->press(ButtonData::enrol('c', '1'), $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now);
		$binding = ChannelBindingStore::findByChat('telegram', self::CHAT);
		$this->assertSame('P-007', $binding['userId']);
		$this->assertNull(InviteStore::resolve($payload));

		// a second invite for the same PID, opened from another chat, is refused
		$other = '111222333';
		$second = InviteStore::payloadFor(self::STUDY, 'key1', 'P-007');
		foreach([['text' => "/start $second"]] as $msg) {
			$update = ['update_id' => $this->updateId++, 'message' => ['message_id' => 9, 'chat' => ['id' => (int) $other, 'type' => 'private'], 'text' => $msg['text']]];
			foreach(\backend\channels\telegram\TelegramUpdateParser::parse($update) as $ev)
				$this->service->handleEvent($ev, $now);
		}
		foreach(['e.c.1', 'e.tz.d'] as $data) {
			$update = ['update_id' => $this->updateId++, 'callback_query' => ['id' => 'x', 'data' => $data,
				'message' => ['message_id' => 9, 'chat' => ['id' => (int) $other, 'type' => 'private']]]];
			foreach(\backend\channels\telegram\TelegramUpdateParser::parse($update) as $ev)
				$this->service->handleEvent($ev, $now);
		}
		$this->assertStringContainsString('already been used', $this->sentTexts());
		$this->assertNull(ChannelBindingStore::findByChat('telegram', $other));
		$this->assertSame(self::CHAT, ChannelBindingStore::get(self::STUDY, 'P-007')['chatRef']);
	}

	/** A redelivered update (same update_id) is ignored. */
	function test_redelivered_update_is_ignored() {
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->startMorning($binding, $now);
		$data = $this->buttonData('Yes');
		$update = ['update_id' => 5000, 'callback_query' => ['id' => 'r', 'data' => $data,
			'message' => ['message_id' => $this->messageId, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private']]]];
		foreach(\backend\channels\telegram\TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
		$before = count($this->calls);
		foreach(\backend\channels\telegram\TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
		$this->assertSame('answerCallbackQuery', $this->calls[count($this->calls) - 1][0]);
		$this->assertSame($before + 1, count($this->calls));
	}
}
