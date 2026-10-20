<?php

namespace backend\channels\conversation;

use backend\channels\ChannelCapabilities;
use backend\channels\InboundEvent;
use backend\channels\OutboundMessage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/** Scripted conversations through the pure engine (no channel, no storage). */
class ConversationEngineTest extends TestCase {
	const NOW = 1773486000000; // 2026-03-14 11:00 UTC

	private function caps(array $override = []): ChannelCapabilities {
		return new ChannelCapabilities(array_merge([
			'maxButtons' => 100, 'maxButtonsPerRow' => 8, 'maxButtonLabel' => 40, 'supportsMessageEdit' => true,
			'supportsVoice' => true, 'supportsPhoto' => true, 'supportsLocation' => true, 'supportsWebView' => true,
			'supportsLinks' => true, 'supportsImages' => true, 'maxTextLength' => 4096,
		], $override));
	}

	private function engine(array $caps = []): ConversationEngine {
		return new ConversationEngine($this->caps($caps), ['model' => 'Telegram']);
	}

	private function questionnaire(array $inputs, array $pageExtra = []): array {
		return ['internalId' => 5, 'title' => 'Daily', 'pages' => [array_merge(['inputs' => $inputs], $pageExtra)]];
	}

	/** Find the callback data of the button with $label in the last prompt. */
	private function button(EngineResult $r, string $label): string {
		foreach(array_reverse($r->messages) as $m) {
			foreach($m->buttons as $row) {
				foreach($row as $b) {
					if($b['label'] === $label || strpos($b['label'], $label) !== false)
						return $b['data'];
				}
			}
		}
		$this->fail("No button '$label'");
	}

	private function press(ConversationEngine $e, array &$state, EngineResult $r, string $label): EngineResult {
		return $e->handle($state, new InboundEvent(InboundEvent::KIND_CALLBACK, '1', $this->button($r, $label)), self::NOW + 60000);
	}

	private function say(ConversationEngine $e, array &$state, string $text): EngineResult {
		return $e->handle($state, new InboundEvent(InboundEvent::KIND_TEXT, '1', $text), self::NOW + 60000);
	}

	public function test_full_conversation_produces_pwa_columns() {
		$q = $this->questionnaire([
			['name' => 'intro', 'responseType' => 'text', 'text' => '<p>Welcome</p>'],
			['name' => 'mood', 'responseType' => 'likert', 'likertSteps' => 5, 'text' => 'Mood?', 'leftSideLabel' => 'bad', 'rightSideLabel' => 'good', 'required' => true],
			['name' => 'where', 'responseType' => 'list_single', 'listChoices' => ['Home', 'Work', 'Other'], 'other' => true, 'required' => true],
			['name' => 'with', 'responseType' => 'list_multiple', 'listChoices' => ['Alone', 'Partner', 'Friends']],
			['name' => 'bed', 'responseType' => 'time', 'text' => 'Bedtime?'],
			['name' => 'note', 'responseType' => 'text_input'],
		]);
		$e = $this->engine();
		$state = [];
		$r = $e->start($state, $q, ['occKey' => '0:3600000', 'deadline' => self::NOW + 3600000], self::NOW);
		$this->assertStringContainsString('Daily', $r->messages[0]->text);
		$this->assertStringContainsString('Welcome', $r->messages[1]->text);
		$this->assertStringContainsString('Mood?', $r->messages[2]->text);
		$this->assertStringContainsString('bad ← → good', $r->messages[2]->text);
		$this->assertTrue($r->messages[2]->isPrompt);

		$r = $this->press($e, $state, $r, '4');
		$this->assertTrue($r->messages[0]->editPrompt);
		$this->assertStringContainsString('✓ 4', $r->messages[0]->text);

		$r = $this->press($e, $state, $r, 'Other');
		$this->assertSame('specify', $state['session']['pending']);
		$r = $this->say($e, $state, 'Gym');
		$this->assertStringContainsString('▫️ Alone', $r->messages[0]->text . json_encode($r->messages[0]->buttons, JSON_UNESCAPED_UNICODE));

		$r2 = $this->press($e, $state, $r, 'Alone');
		$this->assertTrue($r2->messages[0]->editPrompt);
		$r2 = $this->press($e, $state, $r2, 'Friends');
		$r2 = $this->press($e, $state, $r2, 'Done');
		$this->assertStringContainsString('Bedtime?', end($r2->messages)->text);

		$r3 = $this->say($e, $state, 'late');
		$this->assertStringContainsString('07:30', $r3->messages[0]->text);
		$this->assertTrue(ConversationEngine::hasSession($state));
		$this->say($e, $state, '11pm');
		$r4 = $this->say($e, $state, 'all good');

		$this->assertFalse(ConversationEngine::hasSession($state));
		$sub = $r4->submission;
		$this->assertSame(5, $sub['qid']);
		$this->assertSame('0:3600000', $sub['occKey']);
		$this->assertSame(60000, $sub['responses']['formDuration']);
		unset($sub['responses']['formDuration']);
		$this->assertEquals([
			'mood' => '4', 'where' => 'Other', 'where~other' => 'Gym',
			'with~1' => true, 'with~2' => false, 'with~3' => true,
			'bed' => '23:00', 'note' => 'all good', 'pageDurations' => '', 'model' => 'Telegram',
		], $sub['responses']);
	}

	public function test_stale_button_is_rejected() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([
			['name' => 'a', 'responseType' => 'binary', 'required' => true],
			['name' => 'b', 'responseType' => 'binary', 'required' => true],
		]);
		$first = $e->start($state, $q, [], self::NOW);
		$this->press($e, $state, $first, 'Yes');
		$again = $this->press($e, $state, $first, 'No'); // old message's button
		$this->assertSame(ChatStrings::t('stale_button'), $again->toast);
		$this->assertSame('1', $state['session']['answers']['responses']['a']);
		$this->assertArrayNotHasKey('b', $state['session']['answers']['responses']);
	}

	public function test_relevance_hides_and_prunes() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([
			['name' => 'naps', 'responseType' => 'list_single', 'listChoices' => ['0', '1', '2+'], 'required' => true],
			['name' => 'napLen', 'responseType' => 'duration', 'relevance' => 'naps >= 1'],
			['name' => 'end', 'responseType' => 'text_input', 'required' => true],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$r = $this->press($e, $state, $r, '0');
		$this->assertSame('end', $state['session']['items'][$state['session']['index']]['name']);
		$r = $this->say($e, $state, 'x');
		$this->assertArrayNotHasKey('napLen', $r->submission['responses']);
	}

	public function test_skipped_voice_memo_reveals_typed_fallback() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([
			['name' => 'memo', 'responseType' => 'record_audio', 'text' => 'Tell me about your day'],
			['name' => 'memo_typed', 'responseType' => 'record_keystrokes', 'text' => ''],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$r = $this->press($e, $state, $r, 'Skip');
		$this->assertStringContainsString('Tell me about your day', end($r->messages)->text);
		$r = $this->say($e, $state, 'It was fine');
		$this->assertSame(['memo_typed' => '', 'memo_typed~text' => 'It was fine', 'memo_typed~capture_mode' => 'chat'],
			array_intersect_key($r->submission['responses'], ['memo_typed' => 1, 'memo_typed~text' => 1, 'memo_typed~capture_mode' => 1]));
		$this->assertArrayNotHasKey('memo', $r->submission['responses']);
	}

	public function test_voice_note_answer_is_a_media_reference() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([['name' => 'memo', 'responseType' => 'record_audio']]);
		$e->start($state, $q, [], self::NOW);
		$r = $e->handle($state, new InboundEvent(InboundEvent::KIND_VOICE, '1', 'file-ref-1', 'audio/ogg'), self::NOW);
		$id = $r->submission['media']['memo']['identifier'];
		$this->assertSame((string) $id, $r->submission['responses']['memo']);
		$this->assertSame('file-ref-1', $r->submission['media']['memo']['ref']);
	}

	public function test_without_buttons_options_are_numbered() {
		$e = $this->engine(['maxButtons' => 0, 'supportsMessageEdit' => false]);
		$state = [];
		$q = $this->questionnaire([
			['name' => 'c', 'responseType' => 'list_single', 'listChoices' => ['Red', 'Blue'], 'required' => true],
			['name' => 'm', 'responseType' => 'list_multiple', 'listChoices' => ['A', 'B', 'C'], 'required' => true],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertStringContainsString("1. Red\n2. Blue", end($r->messages)->text);
		$this->say($e, $state, 'blue');
		$this->say($e, $state, '1, 9');
		$r = $this->say($e, $state, '1,3');
		$this->assertSame('Blue', $r->submission['responses']['c']);
		$this->assertSame([true, false, true], [$r->submission['responses']['m~1'], $r->submission['responses']['m~2'], $r->submission['responses']['m~3']]);
	}

	public function test_expiry_drops_the_session() {
		$e = $this->engine();
		$state = [];
		$e->start($state, $this->questionnaire([['name' => 'a', 'responseType' => 'text_input']]), ['deadline' => self::NOW + 1000], self::NOW);
		$this->assertNull($e->expireIfDue($state, self::NOW + 500));
		$r = $e->expireIfDue($state, self::NOW + 2000);
		$this->assertNotNull($r);
		$this->assertFalse(ConversationEngine::hasSession($state));
	}

	public function test_dynamic_input_rotates_in_order() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([['name' => 'dyn', 'responseType' => 'dynamic_input', 'subInputs' => [
			['responseType' => 'text_input', 'text' => 'First?'],
			['responseType' => 'text_input', 'text' => 'Second?'],
		]]]);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertStringContainsString('First?', end($r->messages)->text);
		$r = $this->say($e, $state, 'a');
		$this->assertSame(1, $r->submission['responses']['dyn~index']);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertStringContainsString('Second?', end($r->messages)->text);
		$r = $this->say($e, $state, 'b');
		$this->assertSame(2, $r->submission['responses']['dyn~index']);
	}

	public function test_sensor_items_are_skipped_silently() {
		$e = $this->engine();
		$state = [];
		$r = $e->start($state, $this->questionnaire([
			['name' => 'light', 'responseType' => 'ambient_light'],
			['name' => 'a', 'responseType' => 'text_input', 'text' => 'Q'],
		]), [], self::NOW);
		$this->assertCount(2, $r->messages); // title + Q
		$this->assertSame(OutboundMessage::KEYBOARD_INLINE, $r->messages[1]->keyboard);
	}

	public function test_location_can_be_shared_or_skipped() {
		$e = $this->engine();
		$state = [];
		$q = $this->questionnaire([
			['name' => 'here', 'responseType' => 'location'],
			['name' => 'there', 'responseType' => 'location'],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertSame(OutboundMessage::KEYBOARD_LOCATION, end($r->messages)->keyboard);
		$e->handle($state, new InboundEvent(InboundEvent::KIND_LOCATION, '1', '51.24,-0.59'), self::NOW);
		$r = $this->say($e, $state, 'skip');
		$this->assertSame('51.24,-0.59', $r->submission['responses']['here']);
		$this->assertArrayNotHasKey('there', $r->submission['responses']);
	}

	public function test_handoff_items_use_the_web_view_when_available() {
		$e = new ConversationEngine($this->caps(), ['handoff' => function(array $s, int $i, array $item) { return "https://x.test/h?i=$i"; }]);
		$state = [];
		$q = $this->questionnaire([
			['name' => 'vas', 'responseType' => 'va_scale', 'text' => 'Alert?'],
			['name' => 'task', 'responseType' => 'webapp', 'url' => 'https://x.test/pvt', 'text' => 'PVT'],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertSame('https://x.test/h?i=0', end($r->messages)->buttons[0][0]['webview']);
		$this->assertNull($e->handoffResult($state, $state['session']['id'], 1, ['value' => '5'], self::NOW)); // not current
		$e->handoffResult($state, $state['session']['id'], 0, ['value' => '42'], self::NOW);
		$r = $e->handoffResult($state, $state['session']['id'], 1, ['value' => '{"session":"s1"}', 'trials' => [['cogRt' => '300']]], self::NOW);
		$this->assertSame('42', $r->submission['responses']['vas']);
		$this->assertSame('{"session":"s1"}', $r->submission['responses']['task']);
		$this->assertSame([['cogRt' => '300']], $r->submission['trials']);
	}

	public function test_without_handoff_vas_falls_back_to_buttons_and_tasks_are_skipped() {
		$e = $this->engine();
		$state = [];
		$r = $e->start($state, $this->questionnaire([
			['name' => 'task', 'responseType' => 'webapp', 'url' => 'https://x.test/pvt', 'text' => 'PVT'],
			['name' => 'vas', 'responseType' => 'va_scale', 'maxValue' => 200],
		]), [], self::NOW);
		$this->assertStringContainsString('only available in the web app', $r->messages[1]->text);
		$r = $this->press($e, $state, $r, '70');
		$this->assertSame('140', $r->submission['responses']['vas']);
		$this->assertArrayNotHasKey('task', $r->submission['responses']);
	}

	/** A WhatsApp-like profile (3 reply buttons, no edits, no web views) needs no engine change. */
	public function test_whatsapp_like_profile_uses_numbered_replies() {
		$e = new ConversationEngine(new ChannelCapabilities([
			'maxButtons' => 3, 'maxButtonsPerRow' => 3, 'maxButtonLabel' => 20, 'supportsMessageEdit' => false,
			'supportsVoice' => true, 'supportsPhoto' => true, 'supportsLinks' => true, 'maxTextLength' => 4096,
		]), ['model' => 'WhatsApp']);
		$state = [];
		$q = $this->questionnaire([
			['name' => 'alone', 'responseType' => 'binary', 'required' => true],
			['name' => 'mood', 'responseType' => 'likert', 'likertSteps' => 7, 'required' => true],
			['name' => 'with', 'responseType' => 'list_multiple', 'listChoices' => ['A', 'B', 'C'], 'required' => true],
		]);
		$r = $e->start($state, $q, [], self::NOW);
		$this->assertCount(2, end($r->messages)->buttons[0]); // yes/no fit as buttons
		$r = $this->press($e, $state, $r, 'Yes');
		$this->assertStringContainsString('7. 7', end($r->messages)->text); // 7 options > 3 buttons → numbered
		$r = $this->say($e, $state, '6');
		$this->assertStringContainsString('separated by commas', end($r->messages)->text); // no edits → typed multi-select
		$r = $this->say($e, $state, '1,2');
		$this->assertSame(['alone' => '1', 'mood' => '6', 'with~1' => true, 'with~2' => true, 'with~3' => false],
			array_intersect_key($r->submission['responses'], array_flip(['alone', 'mood', 'with~1', 'with~2', 'with~3'])));
	}
}
