<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ChannelCapabilities;
use backend\channels\InboundEvent;
use backend\channels\OutboundMessage;

/**
 * Runs one questionnaire as a conversation. Pure: it only reads/writes the
 * per-participant state array and returns what to send — no I/O, no clock, no
 * platform knowledge — so the same engine drives Telegram today and WhatsApp later,
 * and scripted conversations can be unit-tested.
 *
 * Flow semantics follow web-pwa/src/lib/surveyEngine.ts: hidden items are skipped,
 * answers of items that become hidden are pruned (cascading), an "other" choice asks
 * for free text, and a skipped voice memo reveals its typed fallback.
 *
 * Context ($ctx): lang, baseUrl, tzOffsetMin (JS getTimezoneOffset convention), model,
 * handoff (?callable(array $session, int $index, array $item): ?string — web-view URL).
 */
class ConversationEngine {
	/** @var ChannelCapabilities */
	private $caps;
	/** @var array */
	private $ctx;
	/** @var ReplyReader */
	private $reader;

	public function __construct(ChannelCapabilities $caps, array $ctx) {
		$this->caps = $caps;
		$this->ctx = $ctx + ['lang' => 'en', 'baseUrl' => '', 'tzOffsetMin' => 0, 'model' => 'Chat', 'handoff' => null];
		$this->reader = new ReplyReader($this->ctx);
	}

	public static function hasSession(array $state): bool {
		return !empty($state['session']);
	}

	/**
	 * Begin a questionnaire. $occurrence is the scheduler occurrence that triggered it
	 * ({occKey, deadline, ...}) or [] when the participant opened it themselves.
	 */
	public function start(array &$state, array $questionnaire, array $occurrence, int $nowMs): EngineResult {
		$id = substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6);
		$items = QuestionnaireFlattener::flatten($questionnaire, $id);
		$headers = [];
		foreach($items as $item) {
			if(isset($item['pageHeader']))
				$headers[$item['page']] = $item['pageHeader'];
		}
		$state['session'] = [
			'id'        => $id,
			'qid'       => (int) ($questionnaire['internalId'] ?? 0),
			'title'     => (string) ($questionnaire['title'] ?? ''),
			'startedAt' => $nowMs,
			'deadline'  => isset($occurrence['deadline']) ? (int) $occurrence['deadline'] : null,
			'occKey'    => isset($occurrence['occKey']) ? (string) $occurrence['occKey'] : null,
			'items'     => [],
			'headers'   => $headers,
			'index'     => -1,
			'lastPage'  => -1,
			'mode'      => '',
			'pending'   => null,
			'selected'  => [],
			'answers'   => ['responses' => [], 'multi' => [], 'other' => [], 'keystrokeText' => [], 'captureMode' => [], 'dynamicIndex' => []],
			'media'     => [],
			'fallbacks' => [],
			'nudged'    => [],
			'trials'    => [],
			'dynPicks'  => [],
			'promptText'=> '',
		];
		$state['session']['items'] = DynamicInputResolver::resolve($items, $state);

		$r = new EngineResult();
		$title = trim($state['session']['title']);
		if($title !== '')
			$r->add(OutboundMessage::text('📋 <b>' . HtmlToChatText::escape($title) . '</b>'));
		$this->advance($state, $nowMs, $r);
		return $r;
	}

	/** Re-send the current question (e.g. after /checkin while a session is open). */
	public function repeatPrompt(array &$state): EngineResult {
		$r = new EngineResult();
		if(!self::hasSession($state))
			return $r;
		$s = &$state['session'];
		if($s['pending'] === 'specify')
			return $r->add($this->specifyPrompt($s));
		$r->add($this->buildPrompt($s)['message']);
		return $r;
	}

	/** Drop the session when its deadline has passed. Returns null when nothing expired. */
	public function expireIfDue(array &$state, int $nowMs): ?EngineResult {
		$s = $state['session'] ?? null;
		if(empty($s) || empty($s['deadline']) || $nowMs <= (int) $s['deadline'])
			return null;
		unset($state['session']);
		return (new EngineResult())->add(OutboundMessage::text(HtmlToChatText::escape(ChatStrings::t('expired', $this->ctx['lang'], $s['title']))));
	}

	public function handle(array &$state, InboundEvent $ev, int $nowMs): EngineResult {
		$r = new EngineResult();
		if(!self::hasSession($state)) {
			$r->unhandled = true;
			return $r;
		}
		$s = &$state['session'];
		$item = $s['items'][$s['index']] ?? null;
		if($item === null) {
			$r->unhandled = true;
			return $r;
		}

		if($ev->kind === InboundEvent::KIND_CALLBACK)
			return $this->handleButton($state, $ev->payload, $item, $nowMs, $r);

		if($s['pending'] === 'specify') {
			if($ev->kind !== InboundEvent::KIND_TEXT)
				return $r->add(OutboundMessage::text(ChatStrings::t('specify_prompt', $this->ctx['lang'])));
			$text = trim($ev->payload);
			if($text !== '')
				$s['answers']['other'][$item['name']] = $text;
			$s['pending'] = null;
			$this->afterAnswer($state, $nowMs, $r, null);
			return $r;
		}

		$read = $this->reader->read($s, $item, $ev, $nowMs);
		if(isset($read['hint']))
			return $r->add(OutboundMessage::text('<i>' . HtmlToChatText::escape($read['hint']) . '</i>'));
		if(isset($read['option']))
			return $this->chooseOption($state, $item, (int) $read['option'], $nowMs, $r);
		if(isset($read['multi'])) {
			$s['selected'] = $read['multi'];
			return $this->finishMulti($state, $item, $nowMs, $r);
		}
		if(isset($read['media'])) {
			$s['answers']['responses'][$item['name']] = (string) $read['media']['identifier'];
			$s['media'][$item['name']] = $read['media'];
		}
		else if(isset($read['value'])) {
			$s['answers']['responses'][$item['name']] = $read['value'];
			if($item['kind'] === 'keystroke') { // typed in chat: no keystroke log, the text goes to name~text
				$s['answers']['responses'][$item['name']] = '';
				$s['answers']['keystrokeText'][$item['name']] = $read['value'];
				$s['answers']['captureMode'][$item['name']] = 'chat';
			}
		}
		else {
			$r->unhandled = true;
			return $r;
		}
		$this->afterAnswer($state, $nowMs, $r, $this->promptHadButtons($s) ? '' : null);
		return $r;
	}

	/**
	 * Record the result of a web-handoff item (VAS slider, keystroke recorder, cognitive task).
	 * $result: {value, keystrokeText?, captureMode?, trials?: array[]}
	 */
	public function handoffResult(array &$state, string $session, int $index, array $result, int $nowMs): ?EngineResult {
		$s = &$state['session'];
		if(empty($s) || $s['id'] !== $session || $s['index'] !== $index || $s['mode'] !== PromptFactory::MODE_HANDOFF)
			return null;
		$item = $s['items'][$index];
		$name = $item['name'];
		$s['answers']['responses'][$name] = (string) ($result['value'] ?? '');
		if($item['kind'] === 'keystroke') {
			$s['answers']['keystrokeText'][$name] = (string) ($result['keystrokeText'] ?? '');
			$s['answers']['captureMode'][$name] = (string) ($result['captureMode'] ?? '');
		}
		if(!empty($result['media']))
			$s['media'][$name] = $result['media'];
		foreach(($result['trials'] ?? []) as $trial) {
			if(is_array($trial))
				$s['trials'][] = $trial;
		}
		$r = new EngineResult();
		$this->afterAnswer($state, $nowMs, $r, '✓');
		return $r;
	}

	// ---------------------------------------------------------------- internals

	private function handleButton(array &$state, string $data, array $item, int $nowMs, EngineResult $r): EngineResult {
		$s = &$state['session'];
		$b = ButtonData::parse($data);
		if($b === null || $b['type'] !== 'answer' || $b['session'] !== $s['id'] || $b['index'] !== $s['index']) {
			$r->toast = ChatStrings::t('stale_button', $this->ctx['lang']);
			return $r;
		}
		$lang = $this->ctx['lang'];
		switch($b['op']) {
			case 'o':
				if($s['pending'] === 'specify' || !in_array($s['mode'], [PromptFactory::MODE_BUTTONS, PromptFactory::MODE_TYPED], true))
					break;
				return $this->chooseOption($state, $item, $b['arg'], $nowMs, $r);
			case 't':
				if($s['mode'] !== PromptFactory::MODE_TOGGLE || !isset($item['options'][$b['arg']]))
					break;
				$sel = $s['selected'];
				$pos = array_search($b['arg'], $sel, true);
				if($pos === false)
					$sel[] = $b['arg'];
				else
					array_splice($sel, $pos, 1);
				sort($sel);
				$s['selected'] = $sel;
				$built = $this->buildPrompt($s);
				return $r->add(OutboundMessage::edit($built['message']->text, $built['message']->buttons));
			case 'd':
				if($s['mode'] !== PromptFactory::MODE_TOGGLE)
					break;
				return $this->finishMulti($state, $item, $nowMs, $r);
			case 's':
				if(!empty($item['required']) && $item['kind'] !== 'audio')
					break;
				if(!empty($item['fallbackId']))
					$s['fallbacks'][] = $item['fallbackId'];
				$this->afterAnswer($state, $nowMs, $r, '—');
				return $r;
			case 'c':
				if($s['mode'] !== PromptFactory::MODE_CONTINUE)
					break;
				$this->afterAnswer($state, $nowMs, $r, '');
				return $r;
			case 'x':
				if($s['pending'] !== 'specify')
					break;
				unset($s['answers']['responses'][$item['name']], $s['answers']['other'][$item['name']]);
				$s['pending'] = null;
				return $r->add($this->buildPrompt($s)['message']);
		}
		$r->toast = ChatStrings::t('stale_button', $lang);
		return $r;
	}

	private function chooseOption(array &$state, array $item, int $i, int $nowMs, EngineResult $r): EngineResult {
		$s = &$state['session'];
		$value = PromptFactory::optionValue($item, $i, $this->todayLocalMs($nowMs));
		if($value === null) {
			$r->toast = ChatStrings::t('stale_button', $this->ctx['lang']);
			return $r;
		}
		$s['answers']['responses'][$item['name']] = $value;
		$labels = PromptFactory::optionLabels($item);
		$label = isset($labels[$i]) ? HtmlToChatText::plain((string) $labels[$i]) : $value;

		if(!empty($item['other']) && $i === count($item['options']) - 1) {
			$s['pending'] = 'specify';
			$r->add(OutboundMessage::edit($s['promptText'] . "\n\n✓ " . HtmlToChatText::escape($label)));
			return $r->add($this->specifyPrompt($s));
		}
		$this->afterAnswer($state, $nowMs, $r, $label);
		return $r;
	}

	private function finishMulti(array &$state, array $item, int $nowMs, EngineResult $r): EngineResult {
		$s = &$state['session'];
		if(!empty($item['required']) && empty($s['selected'])) {
			if($s['mode'] === PromptFactory::MODE_TOGGLE)
				$r->toast = ChatStrings::t('select_at_least_one', $this->ctx['lang']);
			else
				$r->add(OutboundMessage::text('<i>' . ChatStrings::t('select_at_least_one', $this->ctx['lang']) . '</i>'));
			return $r;
		}
		$labels = [];
		foreach($s['selected'] as $i)
			$labels[] = HtmlToChatText::plain((string) $item['options'][$i]);
		$s['answers']['multi'][$item['name']] = $s['selected'];
		// The PWA keeps the comma-joined choices in its response map; relevance compares against that.
		$s['answers']['responses'][$item['name']] = implode(',', array_map(function($i) use ($item) { return (string) $item['options'][$i]; }, $s['selected']));
		$this->afterAnswer($state, $nowMs, $r, $labels ? implode(', ', $labels) : '—');
		return $r;
	}

	/**
	 * Close the current prompt and move on.
	 * @param string|null $echo text appended to the answered prompt ('' = just remove its
	 *                          buttons, null = leave the prompt message untouched)
	 */
	private function afterAnswer(array &$state, int $nowMs, EngineResult $r, ?string $echo): void {
		$s = &$state['session'];
		if($echo === '')
			$r->add(OutboundMessage::removeButtons());
		else if($echo !== null && $s['promptText'] !== '')
			$r->add(OutboundMessage::edit($s['promptText'] . "\n\n✓ " . HtmlToChatText::escape($echo)));
		$items = $s['items'];
		$removed = RelevanceEvaluator::pruneHidden($items, $s['answers']['responses']);
		foreach($removed as $name)
			unset($s['answers']['multi'][$name], $s['answers']['other'][$name], $s['media'][$name]);
		$this->advance($state, $nowMs, $r);
	}

	private function advance(array &$state, int $nowMs, EngineResult $r): void {
		$s = &$state['session'];
		while(true) {
			$s['index']++;
			$s['selected'] = [];
			$s['promptText'] = '';
			$s['promptButtons'] = false;
			if($s['index'] >= count($s['items'])) {
				$this->finish($state, $nowMs, $r);
				return;
			}
			$item = $s['items'][$s['index']];
			if(!$this->isVisible($s, $item))
				continue;
			if($item['page'] !== $s['lastPage']) {
				$s['lastPage'] = $item['page'];
				if(isset($s['headers'][$item['page']]))
					$r->add(OutboundMessage::text(HtmlToChatText::convert($s['headers'][$item['page']])));
			}
			$built = $this->buildPrompt($s);
			$s['mode'] = $built['mode'];
			if($built['mode'] === PromptFactory::MODE_SKIP || $built['mode'] === PromptFactory::MODE_DISPLAY) {
				if($built['mode'] === PromptFactory::MODE_SKIP && !empty($item['fallbackId']))
					$s['fallbacks'][] = $item['fallbackId'];
				$r->add($built['message']);
				continue;
			}
			$r->add($built['message']);
			return;
		}
	}

	private function finish(array &$state, int $nowMs, EngineResult $r): void {
		$s = $state['session'];
		foreach($s['dynPicks'] as $name => $pick)
			$state['dyn'][$name] = $pick;
		$r->submission = [
			'qid'       => $s['qid'],
			'title'     => $s['title'],
			'responses' => AnswerEncoder::encode($s['items'], $s['answers'], $nowMs - (int) $s['startedAt'], (string) $this->ctx['model']),
			'media'     => $s['media'],
			'occKey'    => $s['occKey'],
			'trials'    => $s['trials'],
		];
		unset($state['session']);
	}

	private function buildPrompt(array &$s): array {
		$idx = $s['index'];
		$item = $s['items'][$idx];
		$ctx = $this->ctx;
		$ctx['selected'] = $s['selected'];
		$ctx['handoffUrl'] = is_callable($this->ctx['handoff']) ? call_user_func($this->ctx['handoff'], $s, $idx, $item) : null;
		$built = PromptFactory::build($item, $s['id'], $idx, $this->caps, $ctx);
		if($built['message'] !== null && $built['message']->isPrompt) {
			$s['promptText'] = $built['message']->text;
			$s['promptButtons'] = !empty($built['message']->buttons);
		}
		return $built;
	}

	private function specifyPrompt(array $s): OutboundMessage {
		$lang = $this->ctx['lang'];
		$buttons = $this->caps->maxButtons > 0
			? [[OutboundMessage::button(ChatStrings::t('cancel', $lang), ButtonData::answer($s['id'], $s['index'], 'x'))]]
			: [];
		return new OutboundMessage(ChatStrings::t('specify_prompt', $lang), $buttons);
	}

	private function isVisible(array $s, array $item): bool {
		if(!empty($item['showIf']) && !RelevanceEvaluator::holds($item['showIf'], $s['answers']['responses']))
			return false;
		return empty($item['isFallback']) || in_array($item['name'], $s['fallbacks'], true);
	}

	private function promptHadButtons(array $s): bool {
		return !empty($s['promptButtons']);
	}

	private function todayLocalMs(int $nowMs): int {
		return $nowMs - (int) $this->ctx['tzOffsetMin'] * 60000;
	}
}
