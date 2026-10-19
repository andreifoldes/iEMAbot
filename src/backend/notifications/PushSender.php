<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends the questionnaire reminders that are due, to every registered web-push
 * subscription. Designed to be invoked once a minute by cron
 * (cli/push_send_due.php).
 *
 * Per participant we keep a tiny `.state` file with a `cursor` (the last instant
 * processed); each run sends occurrences in (cursor, now] exactly once. The first
 * run for a participant initialises the cursor to "now", so enabling push never
 * backfills historical reminders.
 */
class PushSender {
	// Constant notification tag: successive pushes replace one another on the device, so
	// the participant never sees more than one ESMira reminder at a time. The service
	// worker also uses it as the default tag.
	const REMINDER_TAG = 'esmira-reminder';

	public static function run(): array {
		$publicKey  = Configs::get('vapid_public_key');
		$privateKey = Configs::get('vapid_private_key');
		if(empty($publicKey) || empty($privateKey)) {
			// Still stamp the heartbeat: the cron IS alive, it just can't send without VAPID.
			// The admin panel already surfaces "no VAPID key" separately, so this lets it
			// distinguish that from "the sender has stopped running".
			self::writeHeartbeat(0, 0);
			return ['error' => 'VAPID keys not configured (run cli/generate_vapid.php)'];
		}

		$subject = Configs::get('vapid_subject');
		if(empty($subject))
			$subject = 'mailto:noreply@esmira';

		$webPush = new WebPush(['VAPID' => [
			'subject'    => $subject,
			'publicKey'  => $publicKey,
			'privateKey' => $privateKey,
		]]);

		$now        = Main::getMilliseconds();
		$defaultLang = Configs::get('defaultLang') ?: 'en';
		$studyStore = Configs::getDataStore()->getStudyStore();

		$queued = 0;
		$studiesProcessed = 0;
		$endpointMeta = []; // push endpoint -> ['s'=>studyId, 'u'=>userId], for delivery logging

		foreach(self::listStudyIds() as $studyId) {
			try {
				$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, $defaultLang), true);
			}
			catch(Throwable $e) {
				continue; // unreadable / no config in this lang — skip
			}
			if(!is_array($study) || empty($study['webPushEnabled']))
				continue;

			$folder = PathsFS::folderPushSubscriptions($studyId);
			if(!is_dir($folder))
				continue;
			$studiesProcessed++;

			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(self::isStateFile($entry))
					continue;
				$file = $folder . $entry;
				$data = json_decode(@file_get_contents($file), true);
				if(!is_array($data) || empty($data['subscription']['endpoint']))
					continue;

				$userId = (string) ($data['userId'] ?? $entry);
				$tz     = (int) ($data['tzOffset'] ?? 0);

				$stateFile = $file . '.state';
				$state     = self::readJsonFile($stateFile);
				$schedule  = is_array($state) ? $state : [];
				// Per-occurrence "already sent" ledger (key => sentAtMs). Belt-and-suspenders
				// over the cursor: the exact same notification (type:qid:sendTime) is never
				// queued twice, even across cursor resets. Pruned to a rolling window below.
				$sent      = (is_array($state) && isset($state['sent']) && is_array($state['sent'])) ? $state['sent'] : [];

				$due = DueOccurrences::compute($studyId, $userId, $study, $tz, (int) ($data['created'] ?? $now), $schedule, $now);
				$occurrences = $due['occurrences'];
				// Coalesce everything due for this participant in this run into ONE push so
				// clients are never flooded. The constant tag (REMINDER_TAG) additionally makes
				// each successive push replace the previous one, so at most one is ever visible.
				// $sent is updated in place with the keys we actually queue this run.
				$payload = self::buildCoalescedPayload($studyId, $userId, $study, $occurrences, $sent, $now);
				if($payload !== null) {
					try {
						$sub = Subscription::create([
							'endpoint' => $data['subscription']['endpoint'],
							'keys'     => [
								'p256dh' => $data['subscription']['keys']['p256dh'] ?? '',
								'auth'   => $data['subscription']['keys']['auth'] ?? '',
							],
						]);
						$webPush->queueNotification($sub, json_encode($payload));
						$endpointMeta[$data['subscription']['endpoint']] = ['s' => $studyId, 'u' => $userId];
						$queued++;
					}
					catch(Throwable $e) {
						/* malformed subscription — skip */
					}
				}
				// Advance the cursor and persist realized random times (so they stay
				// stable across the per-minute runs) and the sent ledger (pruned to a
				// rolling 48h window) whether or not anything was due.
				$sent = self::pruneSentLedger($sent, $now);
				try { FileSystemBasics::writeFile($stateFile, json_encode(['cursor' => $now, 'realized' => $schedule['realized'], 'sent' => $sent, 'sensor' => $schedule['sensor']])); }
				catch(Throwable $e) { /* non-fatal */ }
			}
		}

		// Deliver, log the funnel "sent/failed" per message, and prune subscriptions the
		// push service reports as gone (404/410).
		foreach($webPush->flush() as $report) {
			$endpoint = $report->getEndpoint();
			$meta = $endpointMeta[$endpoint] ?? null;
			if($meta !== null)
				PushEvents::log($meta['s'], $meta['u'], $report->isSuccess() ? 'sent' : 'failed');
			if(!$report->isSuccess() && $report->isSubscriptionExpired())
				self::removeSubscriptionByEndpoint($endpoint);
		}

		self::writeHeartbeat($studiesProcessed, $queued);
		return ['studies' => $studiesProcessed, 'queued' => $queued];
	}

	/**
	 * Rewrite the global sender heartbeat. Delivery/funnel stats show what was sent, but
	 * can't reveal a silently-dead cron (they simply stop growing). This heartbeat lets the
	 * admin panel show liveness — "sender last ran Ns ago" vs. "hasn't run in X min".
	 * Best-effort — never breaks a send.
	 */
	private static function writeHeartbeat(int $studies, int $queued): void {
		try {
			FileSystemBasics::writeFile(PathsFS::filePushHeartbeat(), json_encode([
				'lastRunMs' => Main::getMilliseconds(),
				'studies'   => $studies,
				'queued'    => $queued,
			]));
		}
		catch(Throwable $e) { /* non-fatal */ }
	}

	/**
	 * Read + JSON-decode a file to an array, or null if it is missing/unreadable/invalid.
	 * Guards json_decode against the `false` that file_get_contents returns for a missing
	 * file — under declare(strict_types=1) that `false` is a fatal TypeError, not a benign
	 * null. The per-participant .state file is routinely absent (it doesn't exist until the
	 * first successful run), so this must never throw.
	 */
	private static function readJsonFile(string $path): ?array {
		$raw = @file_get_contents($path);
		if(!is_string($raw))
			return null;
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}

	/** The sender heartbeat ({lastRunMs, studies, queued}), or null if it has never run. */
	public static function readHeartbeat(): ?array {
		$data = self::readJsonFile(PathsFS::filePushHeartbeat());
		return ($data !== null && isset($data['lastRunMs'])) ? $data : null;
	}

	/** Number of registered push subscriptions for a study (for the admin panel). */
	public static function countSubscriptions(int $studyId): int {
		$folder = PathsFS::folderPushSubscriptions($studyId);
		if(!is_dir($folder))
			return 0;
		$n = 0;
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			if(!self::isStateFile($entry))
				$n++;
		}
		return $n;
	}

	/**
	 * Send an immediate test notification to every subscriber of a study (admin
	 * "Send test" action / cli/push_test.php). Returns per-endpoint delivery
	 * results from the push service, and prunes any that have expired.
	 */
	public static function sendTest(int $studyId, string $title, string $body): array {
		$publicKey  = Configs::get('vapid_public_key');
		$privateKey = Configs::get('vapid_private_key');
		if(empty($publicKey) || empty($privateKey))
			return ['error' => 'VAPID keys not configured', 'queued' => 0, 'succeeded' => 0];

		$subject = Configs::get('vapid_subject');
		if(empty($subject))
			$subject = 'mailto:noreply@esmira';

		$webPush = new WebPush(['VAPID' => [
			'subject'    => $subject,
			'publicKey'  => $publicKey,
			'privateKey' => $privateKey,
		]]);

		$folder = PathsFS::folderPushSubscriptions($studyId);
		$queued = 0;
		$endpointUser = []; // endpoint -> userId, for delivery logging
		if(is_dir($folder)) {
			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(self::isStateFile($entry))
					continue;
				$data = json_decode(@file_get_contents($folder . $entry), true);
				if(!is_array($data) || empty($data['subscription']['endpoint']))
					continue;
				$userId = (string) ($data['userId'] ?? $entry);
				try {
					$sub = Subscription::create([
						'endpoint' => $data['subscription']['endpoint'],
						'keys'     => [
							'p256dh' => $data['subscription']['keys']['p256dh'] ?? '',
							'auth'   => $data['subscription']['keys']['auth'] ?? '',
						],
					]);
					$webPush->queueNotification($sub, json_encode([
						'title'    => $title,
						'body'     => $body,
						'url'      => '/pwa/',
						'tag'      => self::REMINDER_TAG,
						'renotify' => true,
						'sid'      => $studyId,
						'uid'      => $userId,
					]));
					$endpointUser[$data['subscription']['endpoint']] = $userId;
					$queued++;
				}
				catch(Throwable $e) { /* malformed — skip */ }
			}
		}

		$succeeded = 0;
		$reports = [];
		foreach($webPush->flush() as $report) {
			$ok = $report->isSuccess();
			if($ok)
				$succeeded++;
			$reports[] = [
				'success' => $ok,
				'expired' => $report->isSubscriptionExpired(),
				'reason'  => $ok ? '' : $report->getReason(),
			];
			PushEvents::log($studyId, $endpointUser[$report->getEndpoint()] ?? '', $ok ? 'sent' : 'failed');
			if(!$ok && $report->isSubscriptionExpired())
				self::removeSubscriptionByEndpoint($report->getEndpoint());
		}
		return ['queued' => $queued, 'succeeded' => $succeeded, 'reports' => $reports];
	}

	/**
	 * Send an immediate test notification to a single participant of a study.
	 * Returns the same shape as sendTest() but for one user.
	 */
	public static function sendTestToUser(int $studyId, string $userId, string $title, string $body): array {
		$publicKey  = Configs::get('vapid_public_key');
		$privateKey = Configs::get('vapid_private_key');
		if(empty($publicKey) || empty($privateKey))
			return ['error' => 'VAPID keys not configured', 'queued' => 0, 'succeeded' => 0];

		$subject = Configs::get('vapid_subject');
		if(empty($subject))
			$subject = 'mailto:noreply@esmira';

		$webPush = new WebPush(['VAPID' => [
			'subject'    => $subject,
			'publicKey'  => $publicKey,
			'privateKey' => $privateKey,
		]]);

		$file = PathsFS::filePushSubscription($studyId, $userId);
		$data = json_decode(@file_get_contents($file), true);
		if(!is_array($data) || empty($data['subscription']['endpoint']))
			return ['error' => 'No subscription found for this participant', 'queued' => 0, 'succeeded' => 0];

		try {
			$sub = Subscription::create([
				'endpoint' => $data['subscription']['endpoint'],
				'keys'     => [
					'p256dh' => $data['subscription']['keys']['p256dh'] ?? '',
					'auth'   => $data['subscription']['keys']['auth'] ?? '',
				],
			]);
			$webPush->queueNotification($sub, json_encode([
				'title'    => $title,
				'body'     => $body,
				'url'      => '/pwa/',
				'tag'      => self::REMINDER_TAG,
				'renotify' => true,
				'sid'      => $studyId,
				'uid'      => $userId,
			]));
		}
		catch(Throwable $e) {
			return ['error' => 'Malformed subscription', 'queued' => 0, 'succeeded' => 0];
		}

		$succeeded = 0;
		foreach($webPush->flush() as $report) {
			if($report->isSuccess())
				$succeeded++;
			PushEvents::log($studyId, $userId, $report->isSuccess() ? 'sent' : 'failed');
			if(!$report->isSuccess() && $report->isSubscriptionExpired())
				self::removeSubscriptionByEndpoint($report->getEndpoint());
		}
		return ['queued' => 1, 'succeeded' => $succeeded];
	}

	/**
	 * Collapse a participant's due occurrences into a single push payload, or null if
	 * none are due (or all were already sent). Keeps one entry per questionnaire (the most
	 * recent occurrence), and carries per-item {qid, key, windowStart, deadline, once,
	 * dailyOnce, onceNotif, occKey, title, body} so the service worker can drop
	 * already-completed / already-shown questionnaires and re-render the remaining count.
	 *
	 * Per-occurrence de-duplication: each item's `key` is `type:qid:sendTime`, which is the
	 * identity of one notification (a specific beep/window, or a specific reminder). Items
	 * whose key is already in $sent are skipped so the exact same notification is never sent
	 * twice; kept keys are recorded into $sent (updated in place).
	 *
	 * @param array $occurrences items from PushScheduler::computeDueOccurrences
	 * @param array $sent        key => sentAtMs ledger, mutated in place
	 */
	private static function buildCoalescedPayload(int $studyId, string $userId, array $study, array $occurrences, array &$sent, int $nowMs): ?array {
		if(empty($occurrences))
			return null;

		// qid → completion-rule flags, so the service worker can suppress reminders for
		// questionnaires already satisfied in ways windowStart alone wouldn't catch:
		//   once     (completableOnce)              — completed on any earlier day
		//   dailyOnce(completableOncePerDay)        — completed earlier today, before this signal
		//   onceNotif(completableOncePerNotification)— this specific occurrence (occKey) done
		$onceByQid = [];
		$perDayByQid = [];
		$onceNotifByQid = [];
		foreach(($study['questionnaires'] ?? []) as $qi => $q) {
			$id = (int) ($q['internalId'] ?? $qi);
			$onceByQid[$id]      = !empty($q['completableOnce']);
			$perDayByQid[$id]    = !empty($q['completableOncePerDay']);
			$onceNotifByQid[$id] = !empty($q['completableOncePerNotification']);
		}

		// One entry per questionnaire — keep the most recent (a reminder supersedes its base).
		$byQid = [];
		foreach($occurrences as $occ) {
			$qid = (int) ($occ['qid'] ?? 0);
			if(!isset($byQid[$qid]) || (int) $occ['timestamp'] > (int) $byQid[$qid]['timestamp'])
				$byQid[$qid] = $occ;
		}

		$items = [];
		$anyReminder = false;
		foreach($byQid as $occ) {
			$qid  = (int) ($occ['qid'] ?? 0);
			$type = (($occ['type'] ?? '') === 'reminder') ? 'reminder' : 'availability';
			$key  = $type . ':' . $qid . ':' . (int) $occ['timestamp'];
			if(isset($sent[$key])) // exact same occurrence already sent — skip the duplicate
				continue;
			$sent[$key] = $nowMs;
			if($type === 'reminder')
				$anyReminder = true;
			$items[] = [
				'qid'         => $qid,
				'key'         => $key,
				'windowStart' => (int) ($occ['windowStart'] ?? $occ['timestamp']),
				'deadline'    => isset($occ['deadline']) ? $occ['deadline'] : null,
				'once'        => (bool) ($onceByQid[$qid] ?? false),
				'dailyOnce'   => (bool) ($perDayByQid[$qid] ?? false),
				'onceNotif'   => (bool) ($onceNotifByQid[$qid] ?? false),
				'occKey'      => isset($occ['occKey']) ? (string) $occ['occKey'] : null,
				'title'       => (string) ($occ['title'] ?? 'ESMira'),
				'body'        => (string) ($occ['body'] ?? ''),
			];
		}

		$count = count($items);
		if($count === 0) // everything due was already sent in an earlier run — nothing new
			return null;
		$studyTitle = (is_string($study['title'] ?? null) && $study['title'] !== '') ? $study['title'] : 'ESMira';
		if($count === 1) {
			$title = $items[0]['title'];
			$body  = $items[0]['body'];
		}
		else {
			$title = $studyTitle;
			$body  = sprintf('You have %d questionnaires to complete.', $count);
		}

		return [
			'title'        => $title,
			'body'         => $body,
			'url'          => '/pwa/',
			'tag'          => self::REMINDER_TAG,
			'renotify'     => true,
			'type'         => $anyReminder ? 'reminder' : 'availability',
			'sid'          => $studyId, // so the SW can report received/clicked receipts
			'uid'          => $userId,
			'count'        => $count,
			'items'        => $items,
			// The SW uses this when it drops some completed items and must re-count.
			'bodyTemplate' => 'You have %d questionnaires to complete.',
		];
	}

	/** Drop sent-ledger entries older than 48h so the per-participant .state stays small. */
	private static function pruneSentLedger(array $sent, int $nowMs): array {
		$cutoff = $nowMs - 48 * 3600 * 1000;
		foreach($sent as $k => $ts) {
			if((int) $ts < $cutoff)
				unset($sent[$k]);
		}
		return $sent;
	}

	/** Numeric study folders under the studies root. */
	private static function listStudyIds(): array {
		$folder = PathsFS::folderStudies();
		if(!is_dir($folder))
			return [];
		$ids = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			if(ctype_digit($entry) && is_dir($folder . $entry))
				$ids[] = (int) $entry;
		}
		return $ids;
	}

	private static function isStateFile(string $entry): bool {
		return substr($entry, -6) === '.state';
	}

	private static function removeSubscriptionByEndpoint(string $endpoint): void {
		foreach(self::listStudyIds() as $studyId) {
			$folder = PathsFS::folderPushSubscriptions($studyId);
			if(!is_dir($folder))
				continue;
			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(self::isStateFile($entry))
					continue;
				$file = $folder . $entry;
				$data = json_decode(@file_get_contents($file), true);
				if(is_array($data) && ($data['subscription']['endpoint'] ?? '') === $endpoint) {
					@unlink($file);
					@unlink($file . '.state');
					return;
				}
			}
		}
	}
}
