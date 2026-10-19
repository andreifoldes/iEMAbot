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
 * measurement. An event is stored only if the participant's study actually has a sensor
 * trigger waiting for it (data minimisation, and it keeps the event log from filling up
 * with frequent activity pings nobody uses).
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
		return self::record('withings', $providerUserId, $kind, $nowMs);
	}

	/**
	 * Fitbit Subscriptions: JSON array of {collectionType, date, ownerId, ownerType, subscriptionId}.
	 * @return int number of (participant, event) pairs recorded
	 */
	public static function handleFitbit(string $rawBody, int $nowMs): int {
		$notifications = json_decode($rawBody, true);
		if(!is_array($notifications))
			return 0;
		$count = 0;
		foreach($notifications as $n) {
			if(!is_array($n))
				continue;
			$providerUserId = (string) ($n['ownerId'] ?? '');
			$kind = WearablesEventKinds::fromFitbitCollection((string) ($n['collectionType'] ?? ''));
			if($providerUserId !== '' && $kind !== null)
				$count += self::record('fitbit', $providerUserId, $kind, $nowMs);
		}
		return $count;
	}

	/** Fitbit signs the raw body: base64(HMAC-SHA1(body, clientSecret . '&')). */
	public static function verifyFitbitSignature(string $rawBody, string $signature, string $clientSecret): bool {
		if($signature === '' || $clientSecret === '')
			return false;
		$expected = base64_encode(hash_hmac('sha1', $rawBody, $clientSecret . '&', true));
		return hash_equals($expected, $signature);
	}

	/**
	 * Store the event for every participant linked to this provider account whose study
	 * has a matching sensor trigger.
	 */
	public static function record(string $provider, string $providerUserId, string $kind, int $nowMs): int {
		$count = 0;
		foreach(self::findParticipants($provider, $providerUserId) as [$studyId, $userId]) {
			try {
				if(!self::studyWantsEvent($studyId, $provider, $kind))
					continue;
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
		// A Fitbit batch often repeats the same owner; scanning decrypts every token file.
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

	private static function studyWantsEvent(int $studyId, string $provider, string $kind): bool {
		$defaultLang = Configs::get('defaultLang') ?: 'en';
		$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
		return is_array($study)
			&& !empty($study['wearablesEnabled'])
			&& SensorTriggerScheduler::wantsEvent($study, $provider, $kind);
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
