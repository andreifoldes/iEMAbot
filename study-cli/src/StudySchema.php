<?php

declare(strict_types=1);

namespace StudyCli;

use backend\schedule\Schedule;

/**
 * The shape of a study config as the admin editor writes it: for every structure its fields with their defaults,
 * which text fields are translated, and which nested objects and lists it holds.
 *
 * It mirrors src/frontend/ts/data/study/*.ts, where each field is declared as `primitive(name, default)`,
 * `translatable(...)` and so on. The editor leaves out every field that still has its default value
 * (ObservableObject.createJson), so a config produced here must do the same to be the config the editor would have
 * written. A test keeps this file and the TypeScript classes in step: tests/fixtures/ui-defaults.json is
 * generated from the real TypeScript classes (tests/frontend) and compared with this table (StudySchemaTest).
 *
 * Node format:
 *  - fields: name => default. The type of a field is the type of its default (bool, number, string, list of strings).
 *  - translatable: names of the text fields (and string lists) that can differ per language.
 *  - objects: name => node of a nested object that is always written, or null for free-form data.
 *  - lists: name => node of the entries of a list that is always written, or null for entries this table does not describe.
 */
final class StudySchema {
	public const STUDY = 'study';
	public const QUESTIONNAIRE = 'questionnaire';
	public const PAGE = 'page';
	public const INPUT = 'input';
	public const SUM_SCORE = 'sumScore';
	public const SCHEDULE = 'schedule';
	public const CHECKIN = 'checkin';
	public const EVENT_UPLOAD_SETTINGS = 'eventUploadSettings';
	public const CHANNELS = 'channels';
	public const TELEGRAM = 'telegram';
	public const STATISTICS = 'statistics';

	/** Fields the server or the editor sets on every save; a spec must not contain them. */
	public const SERVER_MANAGED = ['version', 'subVersion', 'new_changes', 'serverVersion', 'packageVersion', 'lang'];

	public const RESPONSE_TYPES = [
		'ambient_light', 'app_usage', 'battery_level', 'binary', 'bluetooth_devices', 'compass', 'countdown', 'date', 'duration',
		'dynamic_input', 'file_upload', 'image', 'likert', 'list_multiple', 'list_single', 'location', 'noise_level', 'number',
		'photo', 'record_audio', 'record_keystrokes', 'share', 'text', 'text_input', 'time', 'va_scale', 'video', 'webapp',
	];

	/** Names of the structures in the order the tests walk them. */
	public static function nodeNames(): array {
		return array_keys(self::nodes());
	}

	/** @return array{fields: array, translatable: string[], objects: array, lists: array} */
	public static function node(string $name): array {
		$nodes = self::nodes();
		if(!isset($nodes[$name]))
			throw new \InvalidArgumentException("Unknown study structure \"$name\"");
		return $nodes[$name];
	}

	/** Every key a structure may contain: fields, nested objects and lists. */
	public static function knownKeys(string $name): array {
		$node = self::node($name);
		return array_merge(array_keys($node['fields']), array_keys($node['objects']), array_keys($node['lists']));
	}

	/** What a field holds, said the way a researcher would, for error messages. */
	public static function describeType(mixed $default): string {
		return match(true) {
			is_bool($default) => 'true or false',
			is_int($default), is_float($default) => 'a number',
			is_array($default) => 'a list of texts',
			default => 'text',
		};
	}

	/** Does a value have the type of the field's default? Numbers may be int or float, lists must hold only strings. */
	public static function hasTypeOf(mixed $value, mixed $default): bool {
		return match(true) {
			is_bool($default) => is_bool($value),
			is_int($default), is_float($default) => (is_int($value) || is_float($value)) && is_finite((float) $value),
			is_array($default) => is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value),
			default => is_string($value),
		};
	}

	/** How a value is called in a message: "text", "a number", "a list", ... */
	public static function nameOf(mixed $value): string {
		return match(true) {
			is_bool($value) => 'true or false',
			is_int($value), is_float($value) => 'a number',
			is_string($value) => 'text',
			is_array($value) => array_is_list($value) ? 'a list' : 'an object',
			$value === null => 'null',
			default => gettype($value),
		};
	}

	private static function nodes(): array {
		return [
			self::STUDY => [
				'fields' => [
					'id' => 0, 'version' => 0, 'subVersion' => 0, 'packageVersion' => '0.0.0', 'serverVersion' => 0, 'lang' => '',
					'new_changes' => false, 'published' => false, 'publishedWeb' => true, 'publishedAndroid' => true, 'publishedIOS' => true,
					'studyOver' => false, 'sendMessagesAllowed' => true, 'randomGroups' => 1, 'enableRewardSystem' => false,
					'rewardVisibleAfterDays' => 0, 'defaultLang' => 'en', 'useFallback' => true, 'additionalDaysActive' => 0,
					'legacyScheduling' => false, 'enableTutorialMode' => false, 'webPushEnabled' => false, 'webMicOnboarding' => true,
					'notificationNudge' => true, 'androidNotificationsEnabled' => true, 'studyEndNotificationEnabled' => true,
					'webPhoneOnly' => false, 'webLanguage' => '', 'webSnapshot' => '', 'webLocalOnly' => false, 'webTaskActions' => false,
					'messagePacingWpm' => 238, 'wearablesEnabled' => false, 'wearablesProviders' => [], 'wearablesDataTypes' => [],
					'phoneSensingLight' => false, 'phoneSensingActivity' => false, 'phoneSensingAccelerometer' => false,
					'phoneSensingAccelBeforeMin' => 10, 'phoneSensingAccelAfterMin' => 10, 'phoneSensingAccelHz' => 20,
					'phoneSensingUsage' => '', 'phoneSensingUploadNetwork' => 'wifi_preferred', 'phoneSensingWifiFallbackHours' => 24,
					'phoneSensingParticipantNetwork' => true, 'enablePersonalCharts' => true, 'chartsVisibleOnlyAfterStudy' => false,
					'studyArtwork' => '', 'accessKeys' => [], 'langCodes' => ['en'],
					'title' => 'Study title', 'studyDescription' => '', 'studyTag' => '', 'informedConsentForm' => '',
					'postInstallInstructions' => '', 'tutorialOffer' => '', 'tutorialIntro' => '', 'chooseUsernameInstructions' => '',
					'webQuestionnaireCompletedInstructions' => '', 'webInstallInstructions' => '', 'contactEmail' => '',
					'rewardEmailContent' => '', 'rewardInstructions' => '', 'studyEndNotification' => '', 'postStudyNote' => '', 'faq' => '',
				],
				'translatable' => [
					'title', 'studyDescription', 'studyTag', 'informedConsentForm', 'postInstallInstructions', 'tutorialOffer',
					'tutorialIntro', 'chooseUsernameInstructions', 'webQuestionnaireCompletedInstructions', 'webInstallInstructions',
					'contactEmail', 'rewardEmailContent', 'rewardInstructions', 'studyEndNotification', 'postStudyNote', 'faq',
				],
				'objects' => [
					self::EVENT_UPLOAD_SETTINGS => self::EVENT_UPLOAD_SETTINGS,
					'publicStatistics' => self::STATISTICS,
					'personalStatistics' => self::STATISTICS,
					'pluginData' => null,
					self::CHANNELS => self::CHANNELS,
					self::SCHEDULE => self::SCHEDULE,
				],
				'lists' => ['questionnaires' => self::QUESTIONNAIRE],
			],
			self::EVENT_UPLOAD_SETTINGS => self::plain([
				'actions_executed' => true, 'invitation' => true, 'invitation_missed' => false, 'language_changed' => false,
				'message' => false, 'notification' => false, 'rejoined' => false, 'reminder' => false, 'requested_reward_code' => false,
				'schedule_changed' => true, 'schedule_planned' => false, 'schedule_removed' => false, 'statistic_viewed' => false,
				'study_message' => false, 'study_updated' => false,
			]),
			self::CHANNELS => ['fields' => [], 'translatable' => [], 'objects' => [self::TELEGRAM => self::TELEGRAM], 'lists' => []],
			self::TELEGRAM => self::plain(['enabled' => false, 'allowHandoff' => true]),
			// Charts have no spec form yet: the list is kept as the editor wrote it.
			self::STATISTICS => ['fields' => [], 'translatable' => [], 'objects' => [], 'lists' => ['charts' => null]],
			self::SCHEDULE => [
				'fields' => Schedule::SCHEDULE_DEFAULTS,
				'translatable' => [],
				'objects' => [],
				'lists' => ['checkins' => self::CHECKIN],
			],
			self::CHECKIN => self::plain(Schedule::CHECKIN_DEFAULTS),
			self::QUESTIONNAIRE => [
				'fields' => [
					'internalId' => -1, 'publishedAndroid' => true, 'publishedIOS' => true, 'publishedWeb' => true,
					'changeResponseMode' => 'previous', 'includeInTutorial' => true, 'limitToGroup' => 0, 'minDataSetsForReward' => 0,
					'isBackEnabled' => true, 'endScriptBlock' => '', 'showInDisabledList' => true, 'virtualInputs' => [],
					'scriptFilter' => '', 'showPagination' => true, 'showSkipToast' => true, 'title' => '',
				],
				'translatable' => ['title'],
				'objects' => [],
				'lists' => ['actionTriggers' => null, 'pages' => self::PAGE, 'sumScores' => self::SUM_SCORE],
			],
			self::PAGE => [
				'fields' => ['randomized' => false, 'skipAfterSecs' => 0, 'header' => '', 'footer' => '', 'relevance' => ''],
				'translatable' => ['header', 'footer'],
				'objects' => [],
				'lists' => ['inputs' => self::INPUT],
			],
			self::INPUT => [
				'fields' => [
					'responseType' => 'text_input', 'name' => 'input', 'required' => false, 'optional' => false, 'random' => false,
					'likertSteps' => 5, 'numberHasDecimal' => false, 'asDropDown' => true, 'forceInt' => false, 'packageId' => '',
					'timeoutSec' => 0, 'playSound' => false, 'showValue' => false, 'maxValue' => 0, 'maxLength' => 0, 'minLength' => 0,
					'resolution' => 0, 'quality' => 100, 'relevance' => '', 'vertical' => false, 'textScript' => '', 'other' => false,
					'useCustomStart' => false, 'customStart' => 0, 'defaultValue' => '', 'text' => '', 'description' => '',
					'instruction' => '', 'imageAlt' => '', 'screenReaderLabel' => '', 'spokenText' => '', 'url' => '',
					'webappDescription' => '', 'leftSideLabel' => '', 'rightSideLabel' => '', 'listChoices' => [],
				],
				'translatable' => [
					'defaultValue', 'text', 'description', 'instruction', 'imageAlt', 'screenReaderLabel', 'spokenText', 'url',
					'webappDescription', 'leftSideLabel', 'rightSideLabel', 'listChoices',
				],
				'objects' => [],
				'lists' => ['subInputs' => self::INPUT],
			],
			self::SUM_SCORE => self::plain(['name' => 'unknown', 'addList' => [], 'subtractList' => []]),
		];
	}

	/** A structure with only untranslated fields and nothing nested. */
	private static function plain(array $fields): array {
		return ['fields' => $fields, 'translatable' => [], 'objects' => [], 'lists' => []];
	}
}
