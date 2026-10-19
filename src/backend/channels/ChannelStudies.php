<?php
declare(strict_types=1);

namespace backend\channels;

use backend\Configs;
use Throwable;

/**
 * Study lookups for the chat channels: loading a study in the participant's language,
 * checking it is reachable with an access key (same rules as api/studies.php), and the
 * per-study channel switch `channels.<channel>.enabled` (omitted from the saved JSON
 * when false, so a missing key means disabled).
 */
class ChannelStudies {
	/** Decoded, language-resolved study config, or null. */
	public static function load(int $studyId, string $lang = ''): ?array {
		$store = Configs::getDataStore()->getStudyStore();
		try {
			if(!$store->studyExists($studyId))
				return null;
			if($lang === '')
				$lang = Configs::get('defaultLang') ?: 'en';
			$study = json_decode($store->getStudyLangConfigAsJson($studyId, $lang), true);
		}
		catch(Throwable $e) {
			return null;
		}
		return is_array($study) ? $study : null;
	}

	public static function channelSettings(array $study, string $channel): array {
		$settings = $study['channels'][$channel] ?? [];
		return is_array($settings) ? $settings : [];
	}

	public static function isChannelEnabled(array $study, string $channel): bool {
		return !empty(self::channelSettings($study, $channel)['enabled']);
	}

	/** Published, not over and reachable with $accessKey (same index api/studies.php uses). */
	public static function isJoinable(int $studyId, string $accessKey): bool {
		$dataStore = Configs::getDataStore();
		try {
			$ids = $dataStore->getStudyAccessIndexStore()->getStudyIds(strtolower(trim($accessKey)));
			if(!in_array($studyId, array_map('intval', $ids), true))
				return false;
			return !$dataStore->getStudyMetadataStore($studyId)->isOver();
		}
		catch(Throwable $e) {
			return false;
		}
	}

	/** Pick the study language for a participant from their UI language hint. */
	public static function pickLang(array $study, string $langHint): string {
		$codes = is_array($study['langCodes'] ?? null) ? $study['langCodes'] : [];
		$hint = strtolower(substr($langHint, 0, 2));
		if($hint !== '' && in_array($hint, $codes, true))
			return $hint;
		return (string) ($study['defaultLang'] ?? ($codes[0] ?? 'en'));
	}

	public static function questionnaire(array $study, int $qid): ?array {
		foreach(($study['questionnaires'] ?? []) as $i => $q) {
			if((int) ($q['internalId'] ?? $i) === $qid)
				return $q;
		}
		return null;
	}

	/** Participant tz offset now, in the JS getTimezoneOffset() convention (UTC − local, minutes). */
	public static function tzOffsetMin(array $binding, int $nowMs): int {
		$name = (string) ($binding['tzName'] ?? '');
		if($name !== '') {
			try {
				$tz = new \DateTimeZone($name);
				return -intdiv($tz->getOffset(new \DateTime('@' . intdiv($nowMs, 1000))), 60);
			}
			catch(Throwable $e) { /* fall through to the stored offset */ }
		}
		return (int) ($binding['tzOffsetMin'] ?? 0);
	}
}
