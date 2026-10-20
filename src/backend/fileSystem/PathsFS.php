<?php

namespace backend\fileSystem;

use backend\Main;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\Paths;

class PathsFS {
	const FILENAME_DATA = 'esmira_data',
		FILENAME_EVENTS = 'events',
		FILENAME_PLUGIN_METADATA = 'metadata.json',
		FILENAME_STATISTICS_JSONFILE = 'json',
		FILENAME_STATISTICS_METADATA = '.metadata',
		FILENAME_STATISTICS_NEWLINES = '.new_data',
		FILENAME_STUDY_INDEX = '.index',
		FILENAME_WEB_ACCESS = 'web_access',
		FOLDER_PLUGIN_DATA = '.data';
	
	static function folderData(): string {
		return Configs::get('dataFolder_path');
	}
	
	static function folderErrorReports(): string {
		return self::folderData() . 'errors/';
	}
	
	static function folderLegal(): string {
		return self::folderData() . 'legal/';
	}
	
	static function folderStudies(): string {
		return self::folderData() . 'studies/';
	}
	
	static function folderTokenRoot(): string {
		return self::folderData() . '.loginToken/';
	}
	static function folderSnapshots(): string {
		return self::folderData() . 'snapshots/';
	}
	static function fileSnapshotZip(string $name): string {
		return self::folderSnapshots() . Paths::makeUrlFriendly($name) . '.zip';
	}
	
	static function fileDataVersion(): string {
		return self::folderData() . Paths::FILENAME_VERSION;
	}
	
	static function fileLogins(): string {
		return self::folderData() . '.logins';
	}
	
	static function filePermissions(): string {
		return self::folderData() . '.permissions';
	}
	
	static function fileServerStatistics(): string {
		return self::folderData() . 'server_statistics.json';
	}
	
	static function fileStudyIndex(): string {
		return self::folderStudies() . self::FILENAME_STUDY_INDEX;
	}
	
	static function fileFallbackStudyIndex(string $encodedUrl): string {
		return self::folderFallbackStudiesUrl($encodedUrl) . self::FILENAME_STUDY_INDEX;
	}
	
	
	static function folderLangs(int $studyId): string {
		return self::folderStudies() . "$studyId/.langs/";
	}
	
	static function folderMedia(int $studyId): string {
		return self::folderStudies() . "$studyId/media/";
	}
	
	static function folderMerlinLogs(int $studyId): string {
		return self::folderStudies() . "$studyId/.merlin_logs/";
	}
	
	static function folderMessages(int $studyId): string {
		return self::folderStudies() . "$studyId/.messages/";
	}
	
	static function folderMessagesArchive(int $studyId): string {
		return self::folderMessages($studyId) . '.archive/';
	}
	
	static function folderMessagesPending(int $studyId): string {
		return self::folderMessages($studyId) . '.pending/';
	}
	
	static function folderMessagesUnread(int $studyId): string {
		return self::folderMessages($studyId) . '.unread/';
	}
	
	static function folderPendingUploads(int $studyId): string {
		return self::folderMedia($studyId) . '.pending_uploads/';
	}
	
	static function folderResponsesIndex(int $studyId): string {
		return self::folderStudies() . "$studyId/.responses_index/";
	}
	
	static function folderResponses(int $studyId): string {
		return self::folderStudies() . "$studyId/responses/";
	}
	
	static function folderStatistics(int $studyId): string {
		return self::folderStudies() . "$studyId/.statistics/";
	}
	
	static function folderStudy(int $studyId): string {
		return self::folderStudies() . "$studyId/";
	}
	
	static function folderToken(string $accountName): string {
		return self::folderTokenRoot() . Paths::makeUrlFriendly($accountName) . '/';
	}
	
	static function folderUserData(int $studyId): string {
		return self::folderStudies() . "$studyId/.userdata/";
	}

	static function folderPushSubscriptions(int $studyId): string {
		return self::folderStudies() . "$studyId/.push_subscriptions/";
	}

	static function filePushSubscription(int $studyId, string $userId): string {
		return self::folderPushSubscriptions($studyId) . Paths::makeUrlFriendly($userId);
	}

	static function filePushState(int $studyId, string $userId): string {
		return self::folderPushSubscriptions($studyId) . Paths::makeUrlFriendly($userId) . '.state';
	}

	/** Append-only JSONL of push funnel events (sent/failed/received/clicked) for a study. */
	static function filePushEvents(int $studyId): string {
		return self::folderStudies() . "$studyId/.push_events";
	}
	/** Global sender heartbeat ({lastRunMs, studies, queued}); rewritten every cron run. */
	static function filePushHeartbeat(): string {
		return self::folderData() . '.push_heartbeat.json';
	}
	/** Per-participant client telemetry (installed-as-PWA + device class); latest wins. */
	static function folderClientInfo(int $studyId): string {
		return self::folderStudies() . "$studyId/.client_info/";
	}
	static function fileClientInfo(int $studyId, string $userId): string {
		return self::folderClientInfo($studyId) . Paths::makeUrlFriendly($userId);
	}

	// --- Wearables (Withings / Google Health) data sharing. Everything lives under
	// the study folder so it is captured by snapshots/backups, mirroring the push
	// subscription layout above. `provider` is a short ascii slug (withings/googlehealth).

	static function folderWearablesTokens(int $studyId): string {
		return self::folderStudies() . "$studyId/.wearables_tokens/";
	}
	/** Encrypted per-participant OAuth token blob, one file per (participant, provider). */
	static function fileWearablesToken(int $studyId, string $userId, string $provider): string {
		return self::folderWearablesTokens($studyId) . Paths::makeUrlFriendly($userId) . '.' . Paths::makeSafe($provider);
	}

	static function folderWearablesData(int $studyId): string {
		return self::folderStudies() . "$studyId/.wearables_data/";
	}
	/** Append-only CSV of fetched data points, one file per (participant, provider). */
	static function fileWearablesData(int $studyId, string $userId, string $provider): string {
		return self::folderWearablesData($studyId) . Paths::makeUrlFriendly($userId) . '.' . Paths::makeSafe($provider) . '.csv';
	}
	/** Per-(participant, provider) sync cursor (last fetched instant). */
	static function fileWearablesSyncState(int $studyId, string $userId, string $provider): string {
		return self::folderWearablesData($studyId) . Paths::makeUrlFriendly($userId) . '.' . Paths::makeSafe($provider) . '.state';
	}

	/** Marker: the provider rejected this participant's credentials, so they must link the device again. */
	static function fileWearablesRevoked(int $studyId, string $userId, string $provider): string {
		return self::folderWearablesData($studyId) . Paths::makeUrlFriendly($userId) . '.' . Paths::makeSafe($provider) . '.revoked';
	}

	/** Webhook-derived sensor events ("new sleep data arrived"), JSONL, one file per participant. */
	static function folderWearablesEvents(int $studyId): string {
		return self::folderStudies() . "$studyId/.wearables_events/";
	}
	static function fileWearablesEvents(int $studyId, string $userId): string {
		return self::folderWearablesEvents($studyId) . Paths::makeUrlFriendly($userId) . '.jsonl';
	}

	/** Latest answers of the items a sensor-trigger fallback reads (e.g. habitual wake time), JSON, one file per participant. */
	static function folderParticipantVariables(int $studyId): string {
		return self::folderStudies() . "$studyId/.participant_variables/";
	}
	static function fileParticipantVariables(int $studyId, string $userId): string {
		return self::folderParticipantVariables($studyId) . Paths::makeUrlFriendly($userId) . '.json';
	}

	// --- Messaging channels (Telegram; later WhatsApp). Participant bindings and their
	// conversation state live under the study folder (captured by snapshots/backups); the
	// chat → participant index and pre-enrolment state are global because an incoming
	// message carries only the chat address. Chat addresses are never stored in clear:
	// file names use an HMAC and binding files hold them encrypted (channels/SecretBox).

	static function folderChannelBindings(int $studyId): string {
		return self::folderStudies() . "$studyId/.channel_bindings/";
	}
	/** Binding of one participant to a chat ({channel, chatRef (encrypted), lang, tz, ...}). */
	static function fileChannelBinding(int $studyId, string $userId): string {
		return self::folderChannelBindings($studyId) . Paths::makeUrlFriendly($userId) . '.json';
	}
	/** Conversation + schedule state of one bound participant (locked while handling an update). */
	static function fileChannelState(int $studyId, string $userId): string {
		return self::folderChannelBindings($studyId) . Paths::makeUrlFriendly($userId) . '.state';
	}
	static function folderChannelsRoot(): string {
		return self::folderData() . '.channels/';
	}
	static function folderChannel(string $channel): string {
		return self::folderChannelsRoot() . Paths::makeSafe($channel) . '/';
	}
	/** chat HMAC → {studyId, userId} of the active binding. */
	static function fileChannelIndex(string $channel, string $chatHash): string {
		return self::folderChannel($channel) . 'index/' . Paths::makeSafe($chatHash) . '.json';
	}
	/** Enrolment progress of a chat that has not joined a study yet. */
	static function fileChannelEnrolment(string $channel, string $chatHash): string {
		return self::folderChannel($channel) . 'enrol/' . Paths::makeSafe($chatHash) . '.json';
	}
	/** Transport state of a channel (e.g. Telegram getUpdates offset, processed update ids). */
	static function fileChannelTransport(string $channel): string {
		return self::folderChannel($channel) . 'transport.json';
	}
	/** Short invite codes → {studyId, accessKey, pid?, expires}. */
	static function fileChannelInvite(string $code): string {
		return self::folderChannelsRoot() . 'invites/' . Paths::makeSafe($code) . '.json';
	}
	/** Global dispatcher heartbeat ({lastRunMs, bindings, started, sent}). */
	static function fileChannelsHeartbeat(): string {
		return self::folderChannelsRoot() . 'heartbeat.json';
	}

	// OAuth CSRF states are global (the provider callback carries only `state`, not a
	// study id); the state file content records which study/participant it belongs to.
	static function folderWearablesOAuthStates(): string {
		return self::folderData() . '.wearables_states/';
	}
	/** Single-use, short-lived CSRF state created when a participant starts a connect flow. */
	static function fileWearablesOAuthState(string $state): string {
		return self::folderWearablesOAuthStates() . Paths::makeSafe($state);
	}
	
	static function folderRewardCodes(int $studyId): string {
		return self::folderStudies() . "$studyId/.reward_codes/";
	}
	
	static function folderFallbackStudies(): string {
		return self::folderData() . 'fallbackStudies/';
	}
	
	static function folderFallbackStudiesUrl(string $encodedUrl): string {
		return self::folderFallbackStudies() . "$encodedUrl/";
	}
	
	static function folderFallbackStudy(string $encodedUrl, int $studyId): string {
		return self::folderFallbackStudiesUrl($encodedUrl) . "$studyId/";
	}
	
	static function folderFallbackStudyLangs(string $encodedUrl, int $studyId): string {
		return self::folderFallbackStudy($encodedUrl, $studyId) . '.langs/';
	}
	
	static function folderPluginRoot(): string {
		return self::folderData() . 'plugins/';
	}
	static function folderPluginTemp(): string {
		return self::folderPluginRoot() . '.temp/';
	}
	static function folderPluginCache(): string {
		return self::folderData() .".pluginCache/";
	}
	static function folderPlugin(string $pluginId): string {
		return self::folderPluginRoot() .Paths::makeUrlFriendly($pluginId) .'/';
	}
	static function folderPluginFrontendSectionCodes(string $pluginId): string {
		return self::folderPlugin($pluginId) . 'sections/';
	}
	static function folderPluginData(string $pluginId): string {
		return self::folderPlugin($pluginId) .self::FOLDER_PLUGIN_DATA;
	}
	
	static function folderPluginLanguages(string $pluginId): string {
		return self::folderPlugin($pluginId) .'locales/';
	}
	
	static function fileBlockLogin(string $accountName): string {
		return self::folderToken($accountName) . '.blocking';
	}
	
	static function fileErrorReport(int $timestamp): string {
		return self::folderErrorReports() . $timestamp;
	}
	
	static function fileErrorReportInfo(): string {
		return self::folderErrorReports() . '.error_info';
	}
	
	static function fileLangConfig(int $studyId, string $code): string {
		return self::folderLangs($studyId) . "/$code.json";
	}
	
	static function fileLangImpressum(string $code): string {
		return $code === '_' ? self::folderLegal() . 'impressum.html' : self::folderLegal() . "impressum.$code.html";
	}
	
	static function fileLangPrivacyPolicy(string $code): string {
		return $code === '_' ? self::folderLegal() . 'privacy_policy.html' : self::folderLegal() . "privacy_policy.$code.html";
	}
	
	static function fileLangHomeMessage(string $code): string {
		return $code === '_' ? self::folderLegal() . 'home_message.html' : self::folderLegal() . "home_message.$code.html";
	}
	
	static function fileLock(int $studyId): string {
		return self::folderStudies() . "$studyId/.locked";
	}
	
	static function fileMerlinLog(int $studyId, int $timestamp): string {
		return self::folderMerlinLogs($studyId) . $timestamp;
	}
	
	static function fileMerlinLogInfo(int $studyId): string {
		return self::folderMerlinLogs($studyId) . '.merlin_logs_info';
	}
	
	static function fileMessageArchive(int $studyId, string $userId): string {
		return self::folderMessagesArchive($studyId) . Paths::makeUrlFriendly($userId);
	}
	
	static function fileMessagePending(int $studyId, string $userId): string {
		return self::folderMessagesPending($studyId) . Paths::makeUrlFriendly($userId);
	}
	
	static function fileMessageUnread(int $studyId, string $userId): string {
		return self::folderMessagesUnread($studyId) . Paths::makeUrlFriendly($userId);
	}
	
	static function filePendingUploads(int $studyId, string $userId, int $identifier): string {
		return self::folderPendingUploads($studyId) . Paths::makeUrlFriendly($userId) . '_' . $identifier;
	}
	
	static function fileResponses(int $studyId, string $questionnaire_identifier): string {
		switch($questionnaire_identifier) {
			case self::FILENAME_EVENTS:
				$filename = self::FILENAME_EVENTS;
				break;
			case self::FILENAME_WEB_ACCESS:
				$filename = self::FILENAME_WEB_ACCESS;
				break;
			default:
				if(Main::strictCheckInput($questionnaire_identifier))
					$filename = $questionnaire_identifier;
				else
					$filename = 'error';
				break;
		}
		return self::folderResponses($studyId) . "$filename.csv";
	}
	
	/**
	 * @throws CriticalException
	 */
	static function fileResponsesBackup(int $studyId, string $questionnaire_identifier): string {
		$date = date('o-m-d');
		$folder = self::folderResponses($studyId);
		$filename = "{$date}_$questionnaire_identifier";
		$file = $folder . "$filename.csv";
		
		
		$count = 2;
		while(file_exists($file)) {
			$file = "$folder{$date}_{$count}_$questionnaire_identifier.csv";
			if(++$count > Configs::get('max_possible_backups_per_day'))
				throw new CriticalException('Could not rename old datafile. There are too many copies. Aborting... Check your datafiles before trying again.');
		}
		
		return $file;
	}
	
	static function fileResponsesIndex(int $studyId, string $questionnaire_identifier): string {
		return self::folderResponsesIndex($studyId) . $questionnaire_identifier;
	}
	
	static function fileStatisticsJson(int $studyId): string {
		return self::folderStatistics($studyId) . self::FILENAME_STATISTICS_JSONFILE;
	}
	
	static function fileStudyStatisticsMetadata(int $studyId): string {
		return self::folderStatistics($studyId) . self::FILENAME_STATISTICS_METADATA;
	}
	
	static function fileStatisticsNewData(int $studyId): string {
		return self::folderStatistics($studyId) . self::FILENAME_STATISTICS_NEWLINES;
	}
	
	static function fileStudyConfig(int $studyId): string {
		return self::folderStudies() . "$studyId/.config.json";
	}
	
	static function fileStudyMetadata(int $studyId): string {
		return self::folderStudies() . "$studyId/.metadata";
	}
	
	static function fileStudyCreateMetadata(int $studyId): string {
		return self::folderStudies() . "$studyId/.create_metadata";
	}
	
	static function fileToken(string $accountName, string $hash): string {
		return self::folderToken($accountName) . $hash;
	}
	
	static function fileTokenHistory(string $accountName, int $num): string {
		return self::folderToken($accountName) . '.history' . $num;
	}
	
	static function fileUserData(int $studyId, string $userId): string {
		return self::folderUserData($studyId) . Paths::makeUrlFriendly($userId);
	}
	
	static function fileRewardCode(int $studyId, string $code): string {
		return self::folderRewardCodes($studyId) . Paths::makeUrlFriendly($code);
	}
	
	static function fileBookmarks(): string {
		return self::folderData() . '.bookmarks';
	}
	
	static function fileIncomingFallbackTokens(): string {
		return self::folderData() . '.inboundFallbackTokens';
	}
	
	static function fileFallbackSetupTokens(): string {
		return self::folderData() . '.fallbackSetupTokens';
	}
	
	static function fileOutboundFallbackTokens(): string {
		return self::folderData() . '.outboundFallbackTokens';
	}
	
	static function fileFallbackStudyLock(string $encodedUrl, int $studyId): string {
		return self::folderFallbackStudy($encodedUrl, $studyId) . '.locked';
	}
	
	static function fileFallbackStudyConfig(string $encodedUrl, int $studyId): string {
		return self::folderFallbackStudy($encodedUrl, $studyId) . '.config.json';
	}
	
	static function fileFallbackLangConfig(string $encodedUrl, int $studyId, string $code): string {
		return self::folderFallbackStudyLangs($encodedUrl, $studyId) . "$code.json";
	}
	
	static function filePluginMetadata(string $pluginId, string $pluginPath = null): string {
		return ($pluginPath ?? self::folderPlugin($pluginId)) . self::FILENAME_PLUGIN_METADATA;
	}
	static function filePluginStudyJsonInstructions(string $pluginId): string {
		return self::folderPlugin($pluginId) . 'study.json';
	}
	static function filePluginIcon(string $pluginId): string {
		return self::folderPlugin($pluginId) . 'icon.svg';
	}
	
	static function filePluginLanguage(string $pluginId, string $code): string {
		return self::folderPluginLanguages($pluginId) . Paths::makeSafe($code) .'.json';
	}
	
	static function filePluginLanguageBundle(string $code): string {
		return self::folderPluginCache() . "lang.$code.json";
	}
	
	static function filePluginFrontendJson(): string {
		return self::folderPluginCache() . 'frontend.json';
	}
	
	static function filePluginFrontendSectionCode(string $pluginId, string $pageName): string {
		return self::folderPluginFrontendSectionCodes($pluginId) . Paths::makeSafe($pageName) .'.js';
	}
	
	static function filePluginApi(string $pluginId, string $apiName): string {
		return self::folderPlugin($pluginId) . 'pluginApi/' . Paths::makeSafe($apiName) .'.php';
	}
	
	static function filePluginApiExtension(string $pluginId, string $apiName): string {
		return self::folderPlugin($pluginId) .'apiOverrides/' .Paths::makeSafe($apiName) .'.php';
	}
}