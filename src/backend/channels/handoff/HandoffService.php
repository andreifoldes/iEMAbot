<?php
declare(strict_types=1);

namespace backend\channels\handoff;

use backend\channels\ChannelRegistry;
use backend\channels\ChannelStudies;
use backend\channels\conversation\ConversationService;
use backend\channels\conversation\PromptFactory;
use backend\channels\SecretBox;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\CreateDataSet;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Web handoff: items a chat cannot render (VAS slider, keystroke recorder, cognitive
 * tasks) open a single-item page of the participant PWA (web-pwa/handoff.html) — as a
 * Telegram Mini App, or a plain link on channels without web views. The page reads the
 * item with GET api/channel_handoff.php?t=…, renders it with the PWA's own components and
 * POSTs the result back; the chat then moves on to the next question.
 */
class HandoffService {
	const TRIALS_QN_TITLE = 'Cognitive Trials'; // hidden questionnaire for per-trial rows (as in the PWA)
	const MAX_TRIALS = 5000;
	const MAX_KEYSTROKE_LOG = 2000000;

	public static function isAvailable(): bool {
		return SecretBox::hasKey(ChannelBindingStore::SECRET_KEY) && self::pageUrl() !== '';
	}

	/** https URL of the PWA handoff page (configurable; derived from the public base URL). */
	public static function pageUrl(): string {
		$url = (string) Configs::get('channels_handoff_url');
		if($url === '') {
			$base = parse_url((string) Configs::get('channels_public_base_url'));
			if(empty($base['host']) || ($base['scheme'] ?? '') !== 'https')
				return '';
			$url = 'https://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . '/pwa/handoff.html';
		}
		return $url;
	}

	/** @return callable(array $session, int $index, array $item): ?string */
	public static function urlBuilder(array $binding): callable {
		return function(array $session, int $index, array $item) use ($binding): ?string {
			if(!in_array($item['kind'], ['va_scale', 'keystroke', 'cognitive'], true))
				return null;
			$token = HandoffToken::create((int) $binding['studyId'], (string) $binding['userId'], (string) $session['id'], $index, intdiv(Main::getMilliseconds(), 1000));
			return self::pageUrl() . '?t=' . rawurlencode($token);
		};
	}

	/**
	 * What the handoff page needs to render the item: the raw ESMira input (so the PWA's own
	 * adapter maps it exactly as on web) plus a little context.
	 * @throws CriticalException when the token is invalid or the item is no longer current
	 */
	public static function describe(string $token): array {
		[$t, $binding, $state] = self::open($token);
		$session = $state['session'];
		$item = $session['items'][$t['i']];
		$study = ChannelStudies::load((int) $binding['studyId'], (string) ($binding['lang'] ?? ''));
		$input = $study !== null ? self::findInput($study, (int) $session['qid'], $item['name']) : null;
		if($input === null)
			throw new CriticalException('This question is no longer available.');
		return [
			'studyId'           => (int) $binding['studyId'],
			'questionnaireName' => (string) $session['title'],
			'kind'              => $item['kind'],
			'input'             => $input,
			'channel'           => (string) $binding['channel'],
		];
	}

	/**
	 * Record the result posted by the handoff page and continue the chat.
	 * $post: {value?, keystrokeText?, captureMode?, keystrokeLog?, trials?: array[]}
	 * @throws CriticalException
	 */
	public static function complete(string $token, array $post): void {
		[$t] = self::open($token);
		$binding = ChannelBindingStore::get($t['s'], $t['u']);
		$channel = $binding !== null ? ChannelRegistry::get((string) $binding['channel']) : null;
		if($channel === null)
			throw new CriticalException('Not found');
		$service = new ConversationService($channel);
		$ok = $service->withState($binding, function(array &$state) use ($service, $binding, $t, $post) {
			$item = $state['session']['items'][$t['i']] ?? null;
			if($item === null || ($state['session']['id'] ?? '') !== $t['ss'] || (int) $state['session']['index'] !== $t['i'])
				return false;
			$result = self::sanitize($item, $post, $binding);
			return $service->applyHandoff($binding, $state, $t['ss'], $t['i'], $result, Main::getMilliseconds());
		});
		if(!$ok)
			throw new CriticalException('This question was already answered.');
	}

	/** Dataset events for cognitive-task trial rows (hidden "Cognitive Trials" questionnaire). */
	public static function trialsEvents(array $study, array $trials, string $model): array {
		if(!$trials)
			return [];
		$qn = null;
		foreach(($study['questionnaires'] ?? []) as $q) {
			if(($q['title'] ?? '') === self::TRIALS_QN_TITLE)
				$qn = $q;
		}
		if($qn === null)
			return [];
		$events = [];
		foreach(array_slice($trials, 0, self::MAX_TRIALS) as $row) {
			$events[] = [
				'eventType' => CreateDataSet::DATASET_TYPE_QUESTIONNAIRE, 'qid' => (int) $qn['internalId'],
				'title' => self::TRIALS_QN_TITLE, 'responses' => $row + ['model' => $model],
			];
		}
		return $events;
	}

	/** @return array{0: array, 1: array, 2: array} token, binding, state */
	private static function open(string $token): array {
		$t = HandoffToken::verify($token, intdiv(Main::getMilliseconds(), 1000));
		$binding = $t !== null ? ChannelBindingStore::get($t['s'], $t['u']) : null;
		if($binding === null)
			throw new CriticalException('This link has expired.');
		$state = JsonFile::read(PathsFS::fileChannelState($t['s'], $t['u'])) ?? [];
		$session = $state['session'] ?? null;
		if(empty($session) || $session['id'] !== $t['ss'] || (int) $session['index'] !== $t['i'] || ($session['mode'] ?? '') !== PromptFactory::MODE_HANDOFF)
			throw new CriticalException('This question was already answered.');
		return [$t, $binding, $state];
	}

	private static function sanitize(array $item, array $post, array $binding): array {
		$text = function($v, int $max = 4000): string {
			return is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';
		};
		if(!empty($post['skip']))
			return ['skip' => true];
		switch($item['kind']) {
			case 'va_scale':
				$v = (int) ($post['value'] ?? -1);
				if($v < 0 || $v > (int) $item['maxValue'])
					throw new CriticalException('Value out of range');
				return ['value' => (string) $v];
			case 'keystroke':
				$result = [
					'value' => '',
					'keystrokeText' => $text($post['keystrokeText'] ?? ''),
					'captureMode' => in_array($post['captureMode'] ?? '', ['physical', 'soft', 'mixed'], true) ? $post['captureMode'] : '',
				];
				$log = $post['keystrokeLog'] ?? '';
				if(is_string($log) && $log !== '' && strlen($log) <= self::MAX_KEYSTROKE_LOG) {
					$identifier = random_int(1, 2147483647);
					$folder = PathsFS::folderChannelBindings((int) $binding['studyId']) . '.media/';
					JsonFile::ensureFolder($folder);
					$path = $folder . $identifier . '.csv';
					file_put_contents($path, $log);
					$result['value'] = (string) $identifier;
					$result['media'] = ['kind' => 'keystrokes', 'identifier' => $identifier, 'localPath' => $path, 'mime' => 'text/csv'];
				}
				return $result;
			case 'cognitive':
				$trials = [];
				foreach(array_slice(is_array($post['trials'] ?? null) ? $post['trials'] : [], 0, self::MAX_TRIALS) as $row) {
					if(!is_array($row))
						continue;
					$clean = [];
					foreach($row as $k => $v) {
						if(is_string($k) && strncmp($k, 'cog', 3) === 0 && preg_match('/^\w{1,40}$/', $k))
							$clean[$k] = is_scalar($v) ? $text($v) : '';
					}
					$trials[] = $clean;
				}
				return ['value' => $text($post['value'] ?? ''), 'trials' => $trials];
		}
		throw new CriticalException('Unsupported item');
	}

	private static function findInput(array $study, int $qid, string $name): ?array {
		$q = ChannelStudies::questionnaire($study, $qid);
		foreach(($q['pages'] ?? []) as $page) {
			foreach(($page['inputs'] ?? []) as $input) {
				if(($input['name'] ?? '') === $name)
					return $input;
			}
		}
		return null;
	}
}
