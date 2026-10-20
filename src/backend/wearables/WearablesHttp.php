<?php
declare(strict_types=1);

namespace backend\wearables;

/**
 * Minimal curl wrapper for the wearables providers. We only need: GET with a bearer
 * token, urlencoded form POST (optionally with HTTP Basic auth),
 * and JSON decoding. Deliberately tiny so the feature adds no composer dependency
 * (ext-curl is already required by minishlink/web-push — see Dockerfile).
 *
 * Every call returns ['status' => int, 'body' => string, 'json' => mixed|null] so a
 * provider can branch on the HTTP status (e.g. a 403 for a data type whose scope was not granted)
 * without exceptions. Transport failures (DNS, timeout) throw WearablesException.
 */
class WearablesHttp {
	const TIMEOUT = 30;

	/**
	 * @param array<string,string> $query
	 * @param array<string,string> $headers
	 * @throws WearablesException
	 */
	public static function getJson(string $url, array $query = [], array $headers = []): array {
		if(!empty($query))
			$url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
		return self::request('GET', $url, null, $headers);
	}

	/**
	 * urlencoded form POST. When $basicAuth = [user, pass] is given, an Authorization:
	 * Basic header is added.
	 * @param array<string,string|int> $form
	 * @param array{0:string,1:string}|null $basicAuth
	 * @param array<string,string> $headers
	 * @throws WearablesException
	 */
	public static function postForm(string $url, array $form, ?array $basicAuth = null, array $headers = []): array {
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		if($basicAuth !== null)
			$headers['Authorization'] = 'Basic ' . base64_encode($basicAuth[0] . ':' . $basicAuth[1]);
		return self::request('POST', $url, http_build_query($form), $headers);
	}

	/**
	 * @param array<string,string> $headers
	 * @throws WearablesException
	 */
	private static function request(string $method, string $url, ?string $body, array $headers): array {
		$ch = curl_init($url);
		if($ch === false)
			throw new WearablesException("Could not init request to $url");

		$headerLines = [];
		foreach($headers as $k => $v)
			$headerLines[] = "$k: $v";

		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER     => $headerLines,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		if($body !== null)
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

		$response = curl_exec($ch);
		if($response === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new WearablesException("Request to $url failed: $err");
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		$json = json_decode((string) $response, true);
		return ['status' => $status, 'body' => (string) $response, 'json' => is_array($json) ? $json : null];
	}

	/**
	 * Convenience: GET with a bearer token, returning the decoded JSON array, or
	 * throwing if the status is not 2xx. Used for provider data endpoints.
	 * @param array<string,string> $query
	 * @throws WearablesException
	 */
	public static function getBearerJson(string $url, string $accessToken, array $query = []): array {
		$resp = self::getJson($url, $query, ['Authorization' => "Bearer $accessToken"]);
		if($resp['status'] < 200 || $resp['status'] >= 300)
			throw new WearablesException("GET $url returned {$resp['status']}");
		return $resp['json'] ?? [];
	}
}
