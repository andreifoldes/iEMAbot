<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\notifications\SensorTriggerScheduler;
use Throwable;

/**
 * Turns provider webhooks ("new sleep data for user X") into sensor events for the
 * participants who linked that provider account, so sensor-triggered prompts can fire.
 *
 * The webhooks only carry the provider's own user id and a data category, never the
 * measurement. Every notification for a participant of a wearables-enabled study is kept in
 * WearablesEventArchive for the researcher. Only the ones a sensor trigger waits for also go to
 * WearablesEventStore, the short hand-off the prompt scheduler reads.
 */
class WearablesWebhook {
	/**
	 * Withings Notify: form POST with userid, appli, startdate, enddate (or date).
	 * @return int number of participants an event was recorded for
	 */
	public static function handleWithings(array $post, int $nowMs): int {
		$providerUserId = (string) ($post['userid'] ?? '');
		$kind = WearablesEventKinds::fromWithingsAppli((int) ($post['appli'] ?? 0));
		if($providerUserId === '' || $kind === null)
			return 0;
		return self::record('withings', $providerUserId, $kind, $nowMs, self::withingsInterval($post));
	}

	/** Withings sends the covered period as epoch seconds (`startdate`/`enddate`). @return array{0:?int, 1:?int}|null */
	private static function withingsInterval(array $post): ?array {
		$from = $post['startdate'] ?? null;
		$to = $post['enddate'] ?? null;
		if(!is_numeric($from))
			return null;
		return [(int) $from * 1000, is_numeric($to) ? (int) $to * 1000 : null];
	}

	/**
	 * Keep the notification for every participant linked to this provider account whose study has
	 * wearables enabled, and hand it to the prompt scheduler where a sensor trigger waits for it.
	 * @param array{0:?int, 1:?int}|null $interval [startMs, endMs] the notification covers, if known
	 * @return int number of participants it was kept for
	 */
	public static function record(string $provider, string $providerUserId, string $kind, int $nowMs, ?array $interval = null): int {
		$count = 0;
		foreach(self::findParticipants($provider, $providerUserId) as [$studyId, $userId]) {
			try {
				$study = self::loadStudy($studyId);
				if($study === null || empty($study['wearablesEnabled']))
					continue;
				$isNew = WearablesEventArchive::append($studyId, $userId, $provider, $kind, $nowMs, $interval);
				if($isNew && SensorTriggerScheduler::wantsEvent($study, $provider, $kind))
					WearablesEventStore::append($studyId, $userId, $provider, $kind, $nowMs);
				$count++;
			}
			catch(Throwable $e) {
				Main::reportError($e, "Recording wearable event failed (study $studyId):");
			}
		}
		return $count;
	}

	/**
	 * (studyId, userId) pairs linked to a provider account. Scans token files because the
	 * webhook only knows the provider's user id; fine for study-sized participant counts.
	 * @return array<int, array{0:int, 1:string}>
	 */
	public static function findParticipants(string $provider, string $providerUserId): array {
		// A Google Health batch often repeats the same owner; scanning decrypts every token file.
		static $memo = [];
		$memoKey = "$provider:$providerUserId";
		if(isset($memo[$memoKey]))
			return $memo[$memoKey];
		$found = [];
		$studies = PathsFS::folderStudies();
		if(!is_dir($studies))
			return $found;
		foreach(array_diff(scandir($studies), ['.', '..']) as $entry) {
			if(!ctype_digit($entry))
				continue;
			$folder = PathsFS::folderWearablesTokens((int) $entry);
			if(!is_dir($folder))
				continue;
			foreach(array_diff(scandir($folder), ['.', '..']) as $file) {
				if(substr($file, -strlen($provider) - 1) !== ".$provider")
					continue;
				$token = WearablesTokenStore::readTokenFile($folder . $file);
				if($token !== null && (string) ($token['provider_user_id'] ?? '') === $providerUserId && !empty($token['userId']))
					$found[] = [(int) $entry, (string) $token['userId']];
			}
		}
		return $memo[$memoKey] = $found;
	}

	private static function loadStudy(int $studyId): ?array {
		$defaultLang = Configs::get('defaultLang') ?: 'en';
		$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
		return is_array($study) ? $study : null;
	}

	/**
	 * Called after a participant links a provider: register the provider-side webhooks.
	 * Best effort - linking must succeed even if the provider refuses or is unreachable;
	 * sensor prompts then simply rely on their time fallback.
	 */
	public static function subscribe(string $provider, array $token): void {
		try {
			$providerObj = WearablesRegistry::get($provider);
			if($providerObj !== null && !$providerObj->subscribesPerUser())
				return;
			$kinds = WearablesEventKinds::kindsFor($provider);
			if($providerObj === null || empty($kinds) || empty($token['access_token']))
				return;
			$subscribed = $providerObj->subscribeWebhooks(
				(string) $token['access_token'],
				(string) ($token['provider_user_id'] ?? ''),
				WearablesRegistry::webhookUri($provider),
				$kinds
			);
			// Most Withings categories need a device the participant may not own, so a partial result is normal.
			if($subscribed === 0)
				Main::reportError(new WearablesException("none of " . count($kinds) . " webhook subscriptions succeeded"), "Subscribing $provider webhooks failed:");
		}
		catch(Throwable $e) {
			Main::reportError($e, "Subscribing $provider webhooks failed:");
		}
	}
}
