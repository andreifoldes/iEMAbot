<?php
declare(strict_types=1);

namespace backend\channels\dispatch;

use backend\channels\ChannelException;
use backend\channels\ChannelRegistry;
use backend\channels\ChannelStudies;
use backend\channels\conversation\ConversationEngine;
use backend\channels\conversation\ConversationService;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\notifications\DueOccurrences;
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
						$service->withState($binding, function(array &$state) use ($service, $binding, $defaultStudy, $nowMs, &$stats) {
							self::tick($service, $binding, $state, $defaultStudy, $nowMs, $stats);
						});
					}
					catch(ChannelException $e) {
						$stats['errors']++;
						if($e->recipientGone) { // bot blocked / chat deleted: stop sending until they write again
							$binding['blocked'] = true;
							ChannelBindingStore::save($binding);
						}
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
		$engine = $service->engine($binding, $study, $nowMs);
		if(($expired = $engine->expireIfDue($state, $nowMs)) !== null) {
			unset($state['promptRef']);
			$service->deliver($binding, $state, $expired);
		}

		$schedule = is_array($state['schedule'] ?? null) ? $state['schedule'] : [];
		$tz = ChannelStudies::tzOffsetMin($binding, $nowMs);
		$due = DueOccurrences::compute((int) $binding['studyId'], (string) $binding['userId'], $defaultStudy, $tz, (int) $binding['joinedAt'], $schedule, $nowMs);
		DueOccurrences::advance($schedule, $nowMs);
		$state['schedule'] = $schedule;

		$sent = is_array($state['sent'] ?? null) ? $state['sent'] : [];
		foreach(self::latestPerQuestionnaire($due['occurrences']) as $occ) {
			$key = (($occ['type'] ?? '') === 'reminder' ? 'reminder' : 'availability') . ':' . (int) $occ['qid'] . ':' . (int) $occ['timestamp'];
			if(isset($sent[$key]))
				continue;
			$sent[$key] = $nowMs;
			if(!self::shouldOffer($study, $state, $occ, $nowMs, $tz))
				continue;

			$session = $state['session'] ?? null;
			if(!empty($session)) {
				if((int) $session['qid'] === (int) $occ['qid'])
					continue; // already being answered
				self::enqueue($state, $occ);
				$stats['queued']++;
				continue;
			}
			$service->startQuestionnaire($binding, $state, $study, $occ, $nowMs);
			$stats['started']++;
		}
		$state['sent'] = self::prune($sent, $nowMs);
	}

	/** Completion rules the scheduler itself does not know about (it only sees lastDataSetTime). */
	public static function shouldOffer(array $study, array $state, array $occ, int $nowMs, int $tzOffsetMin): bool {
		$q = ChannelStudies::questionnaire($study, (int) $occ['qid']);
		if($q === null)
			return false;
		if(!empty($occ['deadline']) && (int) $occ['deadline'] <= $nowMs)
			return false;
		if(!empty($occ['occKey']) && isset($state['done'][(int) $occ['qid'] . '|' . $occ['occKey']]))
			return false;
		$completions = $state['qdone'][(string) $occ['qid']] ?? [];
		if(!empty($q['completableOnce']) && $completions)
			return false;
		if(!empty($q['completableOncePerDay']) && $completions) {
			$offset = $tzOffsetMin * 60000;
			$today = intdiv($nowMs - $offset, 86400000);
			if(intdiv((int) end($completions) - $offset, 86400000) === $today)
				return false;
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
