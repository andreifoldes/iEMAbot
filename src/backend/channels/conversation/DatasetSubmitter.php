<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\media\LocalFileUploader;
use backend\Configs;
use backend\CreateDataSet;
use backend\exceptions\CriticalException;
use backend\Main;
use Throwable;

/**
 * Saves chat-channel data through the same path as the apps (CreateDataSet), with an
 * envelope shaped like the PWA's (web-pwa/src/lib/esmiraApi.ts buildPayload) and
 * `appType` set to the channel (e.g. "Telegram").
 *
 * All events of one moment go into ONE envelope: CreateDataSet rejects a second
 * submission from the same participant within `dataset_server_timeout` (1 s).
 */
class DatasetSubmitter {
	/** @var string */
	private $appType;
	/** @var string */
	private $model;

	public function __construct(string $appType, string $model) {
		$this->appType = $appType;
		$this->model = $model;
	}

	/**
	 * @param array  $study     decoded study config (language-resolved)
	 * @param array  $binding   {studyId, userId, accessKey, lang}
	 * @param array  $events    each {eventType: joined|quit|questionnaire, qid?, title?, responses?}
	 * @param callable|null $fetchMedia fn(array $media): ?string — downloads a media answer to a
	 *                          local temp file (channel specific); null skips file uploads
	 * @return string[] error messages (empty on success)
	 * @throws CriticalException when the envelope is rejected as a whole
	 */
	public function submit(array $study, array $binding, array $events, array $media = [], ?callable $fetchMedia = null): array {
		$now = Main::getMilliseconds();
		$common = [
			'studyId'         => (int) $binding['studyId'],
			'studyVersion'    => (int) ($study['version'] ?? 0),
			'studySubVersion' => (int) ($study['subVersion'] ?? 0),
			'studyLang'       => (string) ($study['lang'] ?? ($binding['lang'] ?? 'en')),
			'accessKey'       => (string) ($binding['accessKey'] ?? ''),
		];
		$dataset = [];
		foreach($events as $event) {
			$type = $event['eventType'];
			$dataset[] = $common + [
				'dataSetId'               => count($dataset),
				'questionnaireName'       => $type === CreateDataSet::DATASET_TYPE_QUESTIONNAIRE ? (string) ($event['title'] ?? '') : null,
				'questionnaireInternalId' => $type === CreateDataSet::DATASET_TYPE_QUESTIONNAIRE ? (int) $event['qid'] : null,
				'eventType'               => $type,
				'responseTime'            => $now,
				'responses'               => $event['responses'] ?? ['model' => $this->model],
			];
		}
		$payload = [
			'userId'        => (string) $binding['userId'],
			'appType'       => $this->appType,
			'appVersion'    => (string) Main::SERVER_VERSION,
			'serverVersion' => Main::SERVER_VERSION,
			'dataset'       => $dataset,
		];

		$errors = $this->save($payload);
		// The per-participant anti-flood check (dataset_server_timeout) rejects a submission
		// that follows the previous one within a second; wait once and retry.
		if($errors && strpos(implode(' ', $errors), 'Too many requests') !== false) {
			usleep(((int) Configs::get('dataset_server_timeout') + 100) * 1000);
			$errors = $this->save($payload);
		}
		if(!$errors && $fetchMedia !== null)
			$errors = array_merge($errors, $this->uploadMedia((int) $binding['studyId'], (string) $binding['userId'], $media, $fetchMedia));
		return $errors;
	}

	/** @return string[] per-event error messages */
	private function save(array $payload): array {
		$create = new CreateDataSet();
		try {
			$create->prepare(json_decode(json_encode($payload)));
			$create->handlePlugins();
			$create->exec();
		}
		catch(Throwable $e) {
			$create->close();
			throw new CriticalException('Saving data failed: ' . $e->getMessage());
		}
		$errors = [];
		foreach($create->output as $line) {
			if(empty($line['success']))
				$errors[] = (string) ($line['error'] ?? 'unknown error');
		}
		return $errors;
	}

	/** Upload the files of media answers (after the dataset registered their identifiers). */
	private function uploadMedia(int $studyId, string $userId, array $media, callable $fetchMedia): array {
		$errors = [];
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		foreach($media as $name => $m) {
			$tmp = null;
			try {
				$tmp = $fetchMedia($m);
				if($tmp === null)
					throw new CriticalException("could not download $name");
				if(filesize($tmp) > (int) Configs::get('max_filesize_for_uploads'))
					throw new CriticalException("$name is too big");
				$uploader = new LocalFileUploader($tmp);
				Configs::getDataStore()->getPluginStore()->handleFileUpload($studyId, $userId, (int) $m['identifier'], $uploader);
				$responsesStore->uploadFile($studyId, $userId, (int) $m['identifier'], $uploader);
			}
			catch(Throwable $e) {
				$errors[] = $e->getMessage();
			}
			finally {
				if($tmp !== null && file_exists($tmp))
					@unlink($tmp);
			}
		}
		return $errors;
	}
}
