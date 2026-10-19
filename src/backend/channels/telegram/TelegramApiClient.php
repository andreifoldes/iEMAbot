<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\ChannelException;

/**
 * Minimal Telegram Bot API client (curl). Paces outgoing calls under Telegram's global
 * limit (~30 messages/s) and turns rate-limit / blocked-bot answers into
 * ChannelException flags. A transport callable can be injected for tests:
 *   fn(string $method, array $params): array  (the decoded Bot API response)
 */
class TelegramApiClient {
	const API = 'https://api.telegram.org';
	const MIN_INTERVAL_US = 40000; // ≤ 25 calls per second

	/** @var string */
	private $token;
	/** @var callable|null */
	private $transport;
	/** @var float */
	private static $lastCall = 0.0;

	public function __construct(string $token, ?callable $transport = null) {
		$this->token = $token;
		$this->transport = $transport;
	}

	/**
	 * @return mixed the `result` field
	 * @throws ChannelException
	 */
	public function call(string $method, array $params = [], int $timeoutSec = 15) {
		$response = $this->transport !== null
			? call_user_func($this->transport, $method, $params)
			: $this->http($method, $params, $timeoutSec);

		if(!empty($response['ok']))
			return $response['result'] ?? true;

		$code = (int) ($response['error_code'] ?? 0);
		$description = (string) ($response['description'] ?? 'Telegram API error');
		if($code === 429)
			throw new ChannelException($description, (int) ($response['parameters']['retry_after'] ?? 5));
		$gone = $code === 403 || ($code === 400 && stripos($description, 'chat not found') !== false);
		throw new ChannelException("$method: $description", 0, $gone);
	}

	/** Download a file by file_id to a temp file; returns its path or null. */
	public function download(string $fileId, int $maxBytes): ?string {
		$file = $this->call('getFile', ['file_id' => $fileId]);
		$path = is_array($file) ? (string) ($file['file_path'] ?? '') : '';
		if($path === '' || (int) ($file['file_size'] ?? 0) > $maxBytes)
			return null;
		$tmp = tempnam(sys_get_temp_dir(), 'tg');
		$h = fopen($tmp, 'wb');
		$ch = curl_init(self::API . '/file/bot' . $this->token . '/' . $path);
		curl_setopt_array($ch, [CURLOPT_FILE => $h, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
		$ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
		curl_close($ch);
		fclose($h);
		if(!$ok) {
			@unlink($tmp);
			return null;
		}
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		if($ext !== '' && @rename($tmp, "$tmp.$ext"))
			$tmp = "$tmp.$ext";
		return $tmp;
	}

	private function http(string $method, array $params, int $timeoutSec): array {
		$wait = self::MIN_INTERVAL_US - (int) ((microtime(true) - self::$lastCall) * 1e6);
		if($wait > 0)
			usleep($wait);
		self::$lastCall = microtime(true);

		$ch = curl_init(self::API . '/bot' . $this->token . '/' . $method);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeoutSec,
			CURLOPT_CONNECTTIMEOUT => 10,
		]);
		$raw = curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);
		if(!is_string($raw))
			throw new ChannelException("$method: network error ($error)");
		$decoded = json_decode($raw, true);
		if(!is_array($decoded))
			throw new ChannelException("$method: unexpected response");
		return $decoded;
	}
}
