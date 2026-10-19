<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ConversationalChannel;
use backend\channels\handoff\HandoffService;
use backend\CreateDataSet;
use backend\Main;
use Throwable;

/**
 * Saving a finished chat questionnaire, with nothing lost when saving fails: the events
 * that were not saved stay in the participant's state ($state['outbox']) and the dispatcher
 * retries them every minute (flush). Only the failed events are resent, so a partial
 * success never duplicates rows. Each entry keeps its original response time.
 */
class SubmissionOutbox {
	const MAX_ATTEMPTS = 30;

	/** @var ConversationalChannel */
	private $channel;

	public function __construct(ConversationalChannel $channel) {
		$this->channel = $channel;
	}

	/**
	 * Save a finished questionnaire (engine submission) now; failed events go to the outbox.
	 * The completion is recorded either way, so the same occurrence is not offered again.
	 * @return bool whether everything was saved right away
	 */
	public function submit(array $study, array $binding, array &$state, array $sub, int $nowMs): bool {
		$events = [[
			'eventType' => CreateDataSet::DATASET_TYPE_QUESTIONNAIRE, 'qid' => $sub['qid'], 'title' => $sub['title'],
			'responses' => $sub['responses'], 'responseTime' => $nowMs,
		]];
		foreach(HandoffService::trialsEvents($study, $sub['trials'] ?? [], $this->channel->displayName()) as $e)
			$events[] = $e + ['responseTime' => $nowMs];
		self::recordCompletion($state, $sub, $nowMs);
		return $this->send($study, $binding, $state, ['events' => $events, 'media' => $sub['media'] ?? [], 'attempts' => 0]);
	}

	/** Retry what is waiting in the outbox (dispatcher, every minute). */
	public function flush(array $study, array $binding, array &$state): void {
		$pending = $state['outbox'] ?? [];
		$state['outbox'] = [];
		foreach($pending as $entry)
			$this->send($study, $binding, $state, $entry);
		if(!$state['outbox'])
			unset($state['outbox']);
	}

	private function send(array $study, array $binding, array &$state, array $entry): bool {
		$entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
		try {
			$result = (new DatasetSubmitter($this->channel->appType(), $this->channel->displayName()))
				->submit($study, $binding, $entry['events'], $entry['media'] ?? [], [$this->channel, 'fetchMedia']);
		}
		catch(Throwable $e) {
			$result = ['failed' => array_fill_keys(array_keys($entry['events']), $e->getMessage()), 'mediaErrors' => []];
		}
		if($result['mediaErrors'])
			Main::reportError(new \Exception('Chat channel media upload failed: ' . implode('; ', $result['mediaErrors'])));
		if(!$result['failed'])
			return true;

		$retry = [
			'events'   => array_values(array_intersect_key($entry['events'], $result['failed'])),
			// media belong to the questionnaire event (index 0); keep them only if it failed
			'media'    => isset($result['failed'][0]) ? ($entry['media'] ?? []) : [],
			'attempts' => $entry['attempts'],
		];
		if($retry['attempts'] < self::MAX_ATTEMPTS)
			$state['outbox'][] = $retry;
		else
			Main::reportError(new \Exception('Chat channel dataset dropped after ' . self::MAX_ATTEMPTS . ' attempts: ' . json_encode($retry['events'])));
		if($entry['attempts'] === 1)
			Main::reportError(new \Exception('Chat channel dataset failed (will retry): ' . implode('; ', $result['failed'])));
		return false;
	}

	private static function recordCompletion(array &$state, array $sub, int $nowMs): void {
		if(!empty($sub['occKey']))
			$state['done'][$sub['qid'] . '|' . $sub['occKey']] = $nowMs;
		$qid = (string) $sub['qid'];
		$state['qdone'][$qid][] = $nowMs;
		$state['qdone'][$qid] = array_slice($state['qdone'][$qid], -50);
		// Keep the occurrence ledger small: entries older than 8 days can never match again.
		foreach(($state['done'] ?? []) as $k => $t) {
			if($t < $nowMs - 8 * 86400000)
				unset($state['done'][$k]);
		}
	}
}
