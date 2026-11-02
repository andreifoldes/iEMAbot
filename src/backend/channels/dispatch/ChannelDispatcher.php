<?php
declare(strict_types=1);

namespace backend\channels\dispatch;

use backend\channels\ChannelRegistry;
use backend\channels\ChannelStudies;
use backend\channels\conversation\ConversationEngine;
use backend\channels\conversation\ConversationService;
use backend\channels\conversation\SubmissionOutbox;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\notifications\DueOccurrences;
use backend\notifications\ParticipantWithdrawals;
use backend\schedule\Schedule;
use Throwable;

/**
 * Cron side of the chat channels (cli/channels_dispatch.php, every minute): for every
 * participant bound to a chat, work out what became due since the last run (same
 * schedule logic as web push, see notifications/DueOccurrences) and open it as a
 * conversation — or queue it while another questionnaire is still open. Expired
 * sessions are closed here too.
 */
class ChannelDispatcher {
	public static function run(int $nowMs): array {
		$stats = ['bindings' => 0, 'started' => 0, 'queued' => 0, 'errors' => 0];
		foreach(ChannelRegistry::configured() as $channel) {
			$service = new ConversationService($channel);
			foreach(self::studyIds() as $studyId) {
				$defaultStudy = ChannelStudies::load($studyId);
				if($defaultStudy === null || !ChannelStudies::isChannelEnabled($defaultStudy, $channel->id()))
					continue;
				foreach(ChannelBindingStore::listForStudy($studyId, $channel->id()) as $binding) {
					if(!empty($binding['blocked']))
						continue;
					$stats['bindings']++;
					try {
						$service->recipientGone = false;
						$service->withState($binding, function(?array &$state) use ($service, $binding, $defaultStudy, $nowMs, &$stats) {
							// Re-read under the lock: the participant may have left (/stop) or
							// changed their time zone since the binding list was read.
							$current = ChannelBindingStore::get((int) $binding['studyId'], (string) $binding['userId']);
							if($current === null) {
								$state = null; // drop the state file the lock just re-created
								return;
							}
							if(ParticipantWithdrawals::isWithdrawn((int) $current['studyId'], (string) $current['userId']))
								return; // left the study (quit event from any channel, or a researcher): nothing is sent
							self::tick($service, $current, $state, $defaultStudy, $nowMs, $stats);
							if($service->recipientGone) { // bot blocked / chat deleted: pause until they write again
								$current['blocked'] = true;
								ChannelBindingStore::save($current);
								$stats['errors']++;
							}
						});
					}
					catch(Throwable $e) {
						$stats['errors']++;
						Main::reportError($e, 'Chat channel dispatch failed');
					}
				}
			}
		}
		try {
			JsonFile::write(PathsFS::fileChannelsHeartbeat(), ['lastRunMs' => $nowMs] + $stats);
		}
		catch(Throwable $e) { /* non-fatal */ }
		return $stats;
	}

	/** One participant: expire, compute due occurrences, start or queue them. */
	public static function tick(ConversationService $service, array $binding, array &$state, array $defaultStudy, int $nowMs, array &$stats): void {
		$study = ChannelStudies::load((int) $binding['studyId'], (string) ($binding['lang'] ?? '')) ?? $defaultStudy;
		$service->channel()->paceFor((string) $binding['chatRef'], $study);
		if(!empty($state['outbox']))
			(new SubmissionOutbox($service->channel()))->flush($study, $binding, $state);
		$service->resendPromptIfNeeded($binding, $state, $study, $nowMs);
		$engine = $service->engine($binding, $study, $nowMs);
		if(($expired = $engine->expireIfDue($state, $nowMs)) !== null) {
			unset($state['promptRef']);
			$service->deliver($binding, $state, $expired);
		}

		$scheduleState = is_array($state['schedule'] ?? null) ? $state['schedule'] : [];
		$tz = ChannelStudies::tzOffsetMin($binding, $nowMs);
		$due = DueOccurrences::compute((int) $binding['studyId'], (string) $binding['userId'], $defaultStudy, $tz, (int) $binding['joinedAt'], $scheduleState, $nowMs);
		$state['schedule'] = $scheduleState;

		$sent = is_array($state['sent'] ?? null) ? $state['sent'] : [];
		foreach(self::latestPerQuestionnaire($due['occurrences']) as $occ) {
			$key = (($occ['type'] ?? '') === 'reminder' ? 'reminder' : 'availability') . ':' . (int) $occ['qid'] . ':' . (int) $occ['timestamp'];
			if(isset($sent[$key]))
				continue;
			$sent[$key] = $nowMs;
			if(!self::shouldOffer($study, $state, $occ, $nowMs, $tz))
				continue;

			$session = $state['session'] ?? null;
			if(!empty($session) && (int) $session['qid'] === (int) $occ['qid']) {
				if(($occ['occKey'] ?? null) === ($session['occKey'] ?? null))
					continue; // a reminder of the questionnaire being answered
				unset($state['session'], $state['promptRef']); // a newer occurrence replaces a stale session
				$session = null;
			}
			if(!empty($session)) {
				self::enqueue($state, $occ);
				$stats['queued']++;
				continue;
			}
			$service->startQuestionnaire($binding, $state, $study, $occ, $nowMs);
			$stats['started']++;
		}
		$state['sent'] = self::prune($sent, $nowMs);
	}

	/**
	 * The schedule's completion rule (Schedule::applyCompletions) for what the chat state knows and the server's
	 * completions may not yet: the window was completed in the chat, or by the check-in's limit (once, per study day).
	 */
	public static function shouldOffer(array $study, array $state, array $occ, int $nowMs, int $tzOffsetMin): bool {
		$q = ChannelStudies::questionnaire($study, (int) $occ['qid']);
		$schedule = Schedule::config($study);
		if($q === null || $schedule === null)
			return false;
		if((int) $occ['deadline'] <= $nowMs)
			return false;
		if(isset($state['done'][(int) $occ['qid'] . '|' . $occ['occKey']]))
			return false;
		$completions = $state['qdone'][(string) $occ['qid']] ?? [];
		$limit = $schedule['checkins'][$occ['checkin']]['limit'] ?? Schedule::LIMIT_WINDOW;
		if($limit === Schedule::LIMIT_ONCE && $completions)
			return false;
		if($limit === Schedule::LIMIT_DAY) {
			foreach($completions as $t) {
				if((int) $t >= (int) $occ['dayFrom'] && (int) $t < (int) $occ['dayTo'])
					return false;
			}
		}
		return true;
	}

	/** One entry per questionnaire — the most recent (a reminder supersedes its base), like PushSender. */
	private static function latestPerQuestionnaire(array $occurrences): array {
		$byQid = [];
		foreach($occurrences as $occ) {
			$qid = (int) ($occ['qid'] ?? 0);
			if(!isset($byQid[$qid]) || (int) $occ['timestamp'] > (int) $byQid[$qid]['timestamp'])
				$byQid[$qid] = $occ;
		}
		return array_values($byQid);
	}

	private static function enqueue(array &$state, array $occ): void {
		$queue = $state['queue'] ?? [];
		foreach($queue as $i => $q) {
			if((int) $q['qid'] === (int) $occ['qid']) {
				$queue[$i] = $occ; // newer occurrence replaces the waiting one
				$state['queue'] = $queue;
				return;
			}
		}
		$queue[] = $occ;
		$state['queue'] = $queue;
	}

	private static function prune(array $sent, int $nowMs): array {
		foreach($sent as $k => $t) {
			if((int) $t < $nowMs - 48 * 3600000)
				unset($sent[$k]);
		}
		return $sent;
	}

	private static function studyIds(): array {
		$folder = PathsFS::folderStudies();
		if(!is_dir($folder))
			return [];
		$ids = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			if(ctype_digit($entry) && is_dir(PathsFS::folderChannelBindings((int) $entry)))
				$ids[] = (int) $entry;
		}
		return $ids;
	}
}
