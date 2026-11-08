<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\schedule\Schedule;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
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
	/** minishlink/web-push's default TTL (4 weeks), for a push without a known end. */
	const DEFAULT_TTL = 2419200;
	/** The study-complete message is held for a phone that is offline for a day at most. */
	const STUDY_END_TTL = 86400;

	/** Seconds before a push service that does not answer is given up on (what web-push 9 used). */
	const HTTP_TIMEOUT = 30;

	/**
	 * web-push 11 no longer ships an HTTP client, it needs a PSR-18 one. We hand it Guzzle explicitly
	 * (php-http/guzzle7-adapter) so the timeout is set here and does not depend on client discovery:
	 * a bare Guzzle client has no timeout, and one hung push service would stall the whole send run.
	 */
	private static function newWebPush($subject, $publicKey, $privateKey): WebPush {
		$client = new GuzzleAdapter(new Client([
			'timeout'         => self::HTTP_TIMEOUT,
			'connect_timeout' => self::HTTP_TIMEOUT,
		]));
		return new WebPush(['VAPID' => [
			'subject'    => $subject,
			'publicKey'  => $publicKey,
			'privateKey' => $privateKey,
		]], [], $client);
	}

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

		$webPush = self::newWebPush($subject, $publicKey, $privateKey);

		$now        = Main::getMilliseconds();
		$defaultLang = Configs::get('defaultLang') ?: 'en';
		$studyStore = Configs::getDataStore()->getStudyStore();

		$queued = 0;
		$studiesProcessed = 0;
		$endpointMeta = []; // push endpoint -> ['s'=>studyId, 'u'=>userId], for delivery logging

		foreach(self::listStudyIds() as $studyId) {
			$defaultStudy = self::loadStudy($studyStore, $studyId, $defaultLang);
			if($defaultStudy === null || empty($defaultStudy['webPushEnabled']))
				continue;
			// Texts in the participant's language (recorded at subscribe time), as the app shows them.
			$studiesByLang = [$defaultLang => $defaultStudy];

			$folder = PathsFS::folderPushSubscriptions($studyId);
			if(!is_dir($folder))
				continue;
			$studiesProcessed++;

			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(self::isStateFile($entry))
					continue;
				$file = $folder . $entry;
				$data = self::readJsonFile($file);
				if($data === null || empty($data['subscription']['endpoint']) || !PushEndpoint::isAllowed($data['subscription']['endpoint']))
					continue;

				$userId = (string) ($data['userId'] ?? $entry);
				// A participant who left the study gets nothing more. Their subscription is
				// normally deleted the moment they withdraw; this catches one that was written
				// afterwards (an older app build, a second device) and drops it for good.
				if(ParticipantWithdrawals::isWithdrawn($studyId, $userId)) {
					ParticipantWithdrawals::forgetDeliveryState($studyId, $userId);
					continue;
				}
				$tz     = (int) ($data['tzOffset'] ?? 0);
				$lang   = is_string($data['lang'] ?? null) && $data['lang'] !== '' ? $data['lang'] : $defaultLang;
				if(!array_key_exists($lang, $studiesByLang))
					$studiesByLang[$lang] = self::loadStudy($studyStore, $studyId, $lang);
				$study = $studiesByLang[$lang] ?? $defaultStudy;
				// The app language the participant's device reported (used when the study sets none).
				$study = NotificationText::withUiLang($study, is_string($data['uiLang'] ?? null) ? $data['uiLang'] : '');

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
				$ttl = $payload === null ? 0 : self::ttlSeconds($payload, $now);
				// The participant's last check-in has just closed or been completed: one "study complete" message,
				// which takes the place of the last reminder on the phone (same tag) instead of leaving it there.
				// Recorded once, sent or not, so it never repeats; a study that ended days ago is recorded without a
				// message (and no longer checked every minute).
				$ended = (is_array($state) && isset($state['ended'])) ? (int) $state['ended'] : 0;
				if($payload === null && $ended === 0) {
					$end = DueOccurrences::studyEnded($studyId, $userId, $study, $tz, (int) ($data['created'] ?? $now), $now);
					if($end !== null)
						$ended = $now;
					if($end === Schedule::ENDED_JUST) {
						$payload = self::studyEndPayload($study);
						$ttl = self::STUDY_END_TTL;
					}
				}
				// Switched off in the app: nothing is sent, but the cursor still moves on below, so turning
				// notifications back on never delivers a backlog.
				if(ParticipantNotificationPrefs::isOff($studyId, $userId))
					$payload = null;
				if($payload !== null) {
					try {
						$sub = Subscription::create([
							'endpoint' => $data['subscription']['endpoint'],
							'keys'     => [
								'p256dh' => $data['subscription']['keys']['p256dh'] ?? '',
								'auth'   => $data['subscription']['keys']['auth'] ?? '',
							],
						]);
						$webPush->queueNotification($sub, json_encode($payload), ['TTL' => $ttl]);
						if(!empty($payload['items'])) // a reminder, for the funnel (not the study-complete message)
							$endpointMeta[$data['subscription']['endpoint']] = ['s' => $studyId, 'u' => $userId];
						$queued++;
					}
					catch(Throwable $e) {
						/* malformed subscription — skip */
					}
				}
				// Persist the schedule's ledger and the sent ledger (pruned to a rolling 48h window) whether or not
				// anything was due.
				$sent = self::pruneSentLedger($sent, $now);
				$newState = ['sent' => $sent, 'schedule' => $schedule['schedule'] ?? []];
				if($ended > 0)
					$newState['ended'] = $ended;
				try { FileSystemBasics::writeFile($stateFile, json_encode($newState)); }
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

	/** Decoded study config in one language (falls back to the main config), or null if unreadable. */
	private static function loadStudy($studyStore, int $studyId, string $lang): ?array {
		try {
			$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, $lang), true);
		}
		catch(Throwable $e) {
			return null;
		}
		return is_array($study) ? $study : null;
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

		$webPush = self::newWebPush($subject, $publicKey, $privateKey);

		$folder = PathsFS::folderPushSubscriptions($studyId);
		$queued = 0;
		$endpointUser = []; // endpoint -> userId, for delivery logging
		if(is_dir($folder)) {
			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(self::isStateFile($entry))
					continue;
				$data = json_decode(@file_get_contents($folder . $entry), true);
				if(!is_array($data) || empty($data['subscription']['endpoint']) || !PushEndpoint::isAllowed($data['subscription']['endpoint']))
					continue;
				$userId = (string) ($data['userId'] ?? $entry);
				if(ParticipantWithdrawals::isWithdrawn($studyId, $userId) || ParticipantNotificationPrefs::isOff($studyId, $userId))
					continue;
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

		$webPush = self::newWebPush($subject, $publicKey, $privateKey);

		if(ParticipantWithdrawals::isWithdrawn($studyId, $userId))
			return ['error' => 'This participant has left the study', 'queued' => 0, 'succeeded' => 0];
		if(ParticipantNotificationPrefs::isOff($studyId, $userId))
			return ['error' => 'This participant has switched notifications off', 'queued' => 0, 'succeeded' => 0];
		$file = PathsFS::filePushSubscription($studyId, $userId);
		$data = self::readJsonFile($file);
		if($data === null || empty($data['subscription']['endpoint']))
			return ['error' => 'No subscription found for this participant', 'queued' => 0, 'succeeded' => 0];
		if(!PushEndpoint::isAllowed($data['subscription']['endpoint']))
			return ['error' => 'The stored subscription does not point to a known push service', 'queued' => 0, 'succeeded' => 0];

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
	 * Collapse a participant's due occurrences into a single push payload, or null if none are due (or all were
	 * already sent). Keeps one entry per questionnaire (the most recent occurrence), and carries per-item {qid, key,
	 * windowStart, deadline, occKey, limit, dayFrom, dayTo, title, body} so the service worker can drop check-ins the
	 * participant has completed meanwhile (the schedule's rule, see Schedule::applyCompletions) and re-render the count.
	 *
	 * Per-occurrence de-duplication: each item's `key` is `type:qid:sendTime`, the identity of one notification.
	 * Items whose key is already in $sent are skipped so the exact same notification is never sent twice; kept keys
	 * are recorded into $sent (updated in place).
	 *
	 * @param array $occurrences items from Schedule::due
	 * @param array $sent        key => sentAtMs ledger, mutated in place
	 */
	private static function buildCoalescedPayload(int $studyId, string $userId, array $study, array $occurrences, array &$sent, int $nowMs): ?array {
		if(empty($occurrences))
			return null;
		$schedule = Schedule::config($study);

		// One entry per questionnaire — keep the most recent (a reminder supersedes its base).
		$byQid = [];
		foreach($occurrences as $occ) {
			$qid = (int) ($occ['qid'] ?? 0);
			if(!isset($byQid[$qid]) || (int) $occ['timestamp'] > (int) $byQid[$qid]['timestamp'])
				$byQid[$qid] = $occ;
		}

		$items = [];
		$anyReminder = false;
		$studyTitle = NotificationSpec::studyTitle($study);
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
				'windowStart' => (int) $occ['windowStart'],
				'deadline'    => (int) $occ['deadline'],
				'occKey'      => (string) $occ['occKey'],
				'limit'       => (string) ($schedule['checkins'][$occ['checkin']]['limit'] ?? Schedule::LIMIT_WINDOW),
				'dayFrom'     => (int) $occ['dayFrom'],
				'dayTo'       => (int) $occ['dayTo'],
				'title'       => (string) ($occ['title'] ?? $studyTitle),
				'body'        => (string) ($occ['body'] ?? ''),
			];
		}

		$count = count($items);
		if($count === 0) // everything due was already sent in an earlier run — nothing new
			return null;
		[$title, $body] = NotificationSpec::coalesce($items, $study);

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
			'bodyTemplate' => NotificationSpec::coalescedTemplate(NotificationText::langFor($study)),
		];
	}

	/**
	 * The "study complete" message (Study.studyEndNotification, in the participant's language), or null when the
	 * research team switched it off. Silent: it replaces the last reminder on the phone (same tag) without alerting,
	 * as it can come at night when the last check-in closes. Carries no sid/uid, so it stays out of the reminder
	 * funnel (no receipts).
	 */
	public static function studyEndPayload(array $study): ?array {
		if(($study['studyEndNotificationEnabled'] ?? true) === false)
			return null;
		$body = is_string($study['studyEndNotification'] ?? null) ? trim($study['studyEndNotification']) : '';
		return [
			'title'    => NotificationSpec::studyTitle($study),
			'body'     => $body !== '' ? $body : NotificationSpec::studyEndBody(NotificationText::langFor($study)),
			'url'      => '/pwa/',
			'tag'      => self::REMINDER_TAG,
			'renotify' => false,
			'silent'   => true,
			'type'     => 'studyEnd',
		];
	}

	/**
	 * How long the push service may hold a reminder for a phone that is offline: until the last of its check-ins
	 * closes (the end of its availability), so a reminder is never delivered after it can no longer be done. The
	 * service worker takes a shown one down at that time too (sw.ts closeExpired). Without known deadlines, the
	 * library's default (4 weeks).
	 */
	public static function ttlSeconds(array $payload, int $nowMs): int {
		$deadlines = array_map(function($it) { return (int) ($it['deadline'] ?? 0); }, $payload['items'] ?? []);
		if(empty($deadlines) || min($deadlines) <= 0)
			return self::DEFAULT_TTL;
		return max(60, min(self::DEFAULT_TTL, (int) ceil((max($deadlines) - $nowMs) / 1000)));
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
