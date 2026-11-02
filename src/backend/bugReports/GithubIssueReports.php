<?php
declare(strict_types=1);

namespace backend\bugReports;

use backend\channels\store\JsonFile;
use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Files the participant PWA's "Send error report" messages as GitHub issues.
 *
 * The report still arrives as a normal participant message (api/save_message.php). When a
 * repository and token are configured (cli/github_issues_setup.php), the message is also
 * queued here and cli/github_issues_send.php (cron, every minute) posts the queue, so the
 * participant's request never waits on GitHub. Config keys:
 *   github_issues_repo    owner/name
 *   github_issues_token   fine-grained token with Issues: read and write (secret; never returned)
 *   github_issues_labels  labels added to each issue
 * Rate limits keep a participant (or anyone posting to save_message.php) from flooding the repo.
 */
class GithubIssueReports {
	/** First line of every report built by the PWA (web-pwa/src/App.tsx, buildErrorReport). */
	const MARKER = '--- iEMAbot error report ---';
	const MAX_PER_USER_PER_DAY = 3;
	const MAX_PER_DAY = 30;
	/** Reports GitHub could not take for this long are dropped (they stay in the messages). */
	const MAX_AGE_MS = 7 * 86400000;
	const API = 'https://api.github.com';

	public static function isConfigured(): bool {
		return self::repo() !== '' && (string) Configs::get('github_issues_token') !== '';
	}

	public static function isErrorReport(string $content): bool {
		return strpos(ltrim($content), self::MARKER) === 0;
	}

	public static function repo(): string {
		$repo = trim((string) Configs::get('github_issues_repo'));
		return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : '';
	}

	/**
	 * Queue a participant's error report for the next send run. Returns false when it is not an
	 * error report, nothing is configured, or a rate limit was reached.
	 */
	public static function enqueue(int $studyId, string $userId, string $content, ?int $nowMs = null): bool {
		if(!self::isErrorReport($content) || !self::isConfigured())
			return false;
		$now = $nowMs ?? Main::getMilliseconds();
		$who = "$studyId/$userId";
		return JsonFile::withLock(self::fileQueue(), function(array &$data) use ($studyId, $userId, $content, $now, $who) {
			$recent = array_values(array_filter($data['recent'] ?? [], function($r) use ($now) {
				return is_array($r) && $now - (int) ($r[0] ?? 0) < 86400000;
			}));
			$mine = count(array_filter($recent, function($r) use ($who) { return ($r[1] ?? '') === $who; }));
			$data['recent'] = $recent;
			if(count($recent) >= self::MAX_PER_DAY || $mine >= self::MAX_PER_USER_PER_DAY)
				return false;
			$data['recent'][] = [$now, $who];
			$data['queue'][] = ['studyId' => $studyId, 'userId' => $userId, 'content' => $content, 'receivedMs' => $now];
			return true;
		});
	}

	/**
	 * Post every queued report. Stops at the first failure and leaves the rest for the next run.
	 * $post(string $url, string $token, array $payload): array{status: int, body: array} is for tests.
	 * @return array{sent: int, queued: int, dropped: int, error?: string}
	 */
	public static function run(?callable $post = null, ?int $nowMs = null): array {
		$result = ['sent' => 0, 'queued' => 0, 'dropped' => 0];
		if(!self::isConfigured())
			return $result;
		$now = $nowMs ?? Main::getMilliseconds();
		$post = $post ?? [self::class, 'httpPost'];

		// One run at a time; a slow GitHub must not stack up cron runs.
		JsonFile::ensureFolder(dirname(self::fileQueue()));
		$lock = fopen(self::fileQueue() . '.run', 'c');
		if($lock === false)
			return $result;
		if(!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			return $result;
		}
		try {
			// Network calls happen outside the queue lock so save_message.php is never held up.
			$queue = (JsonFile::read(self::fileQueue()) ?? [])['queue'] ?? [];
			$done = 0;
			$url = self::API . '/repos/' . self::repo() . '/issues';
			foreach($queue as $item) {
				if($now - (int) ($item['receivedMs'] ?? 0) > self::MAX_AGE_MS) {
					++$result['dropped'];
					++$done;
					continue;
				}
				$res = $post($url, (string) Configs::get('github_issues_token'), self::buildIssue($item));
				if(($res['status'] ?? 0) !== 201) {
					$result['error'] = 'GitHub returned ' . ($res['status'] ?? 0) . ': ' . (string) ($res['body']['message'] ?? '');
					break;
				}
				++$result['sent'];
				++$done;
			}
			$result['queued'] = JsonFile::withLock(self::fileQueue(), function(array &$data) use ($done) {
				$data['queue'] = array_slice($data['queue'] ?? [], $done);
				return count($data['queue']);
			});
			return $result;
		}
		finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * The issue holds only what helps fix the bug, never the raw report: fields are taken from an
	 * allowlist (only the first half of the user id, no server address or study title), the user agent is cut down to browser
	 * and OS (no device model), and the participant's note and the app errors are scrubbed of
	 * contact details and identifiers (scrub()). The full report stays in the study's messages.
	 * @return array{title: string, body: string, labels: string[]}
	 */
	public static function buildIssue(array $item): array {
		$content = str_replace("\r\n", "\n", (string) ($item['content'] ?? ''));
		$userId = (string) ($item['userId'] ?? '');
		$studyId = (int) ($item['studyId'] ?? 0);

		// Built by the PWA as: marker, "Participant note:" + note, then "App version: …" and the other fields.
		$note = '';
		$rest = $content;
		if(preg_match('/\nParticipant note:\n(.*?)(?:\n\n(?=App version: )|$)/s', $content, $m, PREG_OFFSET_CAPTURE)) {
			$note = trim($m[1][0]);
			$rest = substr($content, $m[0][1] + strlen($m[0][0]));
		}
		$note = self::excerpt(self::scrub($note, $userId), 4000);

		$lines = [];
		if($note !== '')
			array_push($lines, 'Participant note (contact details and identifiers removed):', $note, '');
		foreach(['App version', 'Server version', 'Pending uploads', 'Online', 'Language'] as $key) {
			if(preg_match('/^' . $key . ': (.{0,40})$/m', $rest, $m))
				$lines[] = "$key: " . self::scrub(trim($m[1]), $userId);
		}
		if(preg_match('/^User agent: (.*)$/m', $rest, $m))
			$lines[] = 'Browser: ' . self::coarseUserAgent($m[1]);
		if(preg_match_all('/^  \[([0-9TZ:.-]{10,30})\] (.*)$/m', $rest, $errors, PREG_SET_ORDER)) {
			array_push($lines, '', 'Recent app errors (identifiers removed):');
			foreach(array_slice($errors, -15) as $e)
				$lines[] = "  [{$e[1]}] " . self::excerpt(self::scrub($e[2], $userId), 300);
		}
		else
			array_push($lines, '', 'No app errors recorded.');
		$report = implode("\n", $lines);

		$title = 'Error report: ' . ($note !== '' ? self::excerpt(preg_replace('/\s+/', ' ', $note), 80) : "study $studyId");

		// A code fence longer than any backtick run keeps the text inert: no markdown, no @mentions
		// or #references.
		preg_match_all('/`+/', $report, $runs);
		$fence = str_repeat('`', max(3, ...array_map('strlen', array_merge([''], $runs[0]))) + 1);
		$received = gmdate('Y-m-d H:i:s', intdiv((int) ($item['receivedMs'] ?? 0), 1000));
		$body = "A participant sent an error report from the app.\n\n"
			. "- Study id: $studyId\n"
			. '- Participant: ' . self::maskUserId($userId) . " (first half of the user id)\n"
			. "- Received: $received UTC\n\n"
			. "To see the full report or reply to the participant, open the study's messages and look for this participant's message received at that time.\n\n"
			. "{$fence}text\n$report\n$fence\n";

		$labels = Configs::get('github_issues_labels');
		return [
			'title' => $title,
			'body' => $body,
			'labels' => is_array($labels) ? array_values(array_map('strval', $labels)) : [],
		];
	}

	/**
	 * Remove contact details and identifiers from participant-written or app-generated text (the
	 * participant's user id is cut to its first half, maskUserId()): email addresses, URL query strings, key=value secrets, IP addresses,
	 * phone numbers and other long numbers (also dates such as a birth date), long tokens and @handles.
	 * The rest of the note is kept: it is what the bug report is about. Names and street addresses
	 * cannot be recognised reliably and stay in.
	 */
	public static function scrub(string $text, string $userId = ''): string {
		$rules = [
			'/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i' => '[email]',
			'#\b((?:https?|wss?)://[^\s?\#<>"\']+)[?\#][^\s<>"\']*#i' => '$1?[removed]',
			'/\b((?:access_?)?key|token|secret|password|pass|uid|user_?id|pid|code|auth\w*)=[^\s&]+/i' => '$1=[removed]',
			'/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '[ip]',
		];
		$text = preg_replace(array_keys($rules), array_values($rules), $text);
		// A placeholder the rules below leave alone, so a numeric or long id is masked, not removed.
		if(strlen($userId) >= 3)
			$text = str_ireplace($userId, "\0uid\0", $text);
		// Long random-looking words (access keys, session ids, UUIDs): 20+ characters with letters and digits.
		$text = preg_replace_callback('/[A-Za-z0-9_-]{20,}/', function($m) {
			return preg_match('/\d/', $m[0]) && preg_match('/[A-Za-z]/', $m[0]) ? '[token]' : $m[0];
		}, $text);
		$rules = [
			'/(?<![\w.])\+?\(?\d(?:[\s().\/-]*\d){6,}/' => '[number]',
			'/(?<![\w@\[])@[A-Za-z0-9_.]{3,}/' => '[handle]',
		];
		return str_replace("\0uid\0", self::maskUserId($userId), preg_replace(array_keys($rules), array_values($rules), $text));
	}

	/** First half of the user id, enough to find the participant without giving the whole id away. */
	public static function maskUserId(string $userId): string {
		return $userId === '' ? '—' : mb_substr($userId, 0, intdiv(mb_strlen($userId), 2)) . '…';
	}

	/** "Chrome 130 on Android 14": browser and OS major versions only, never the device model. */
	public static function coarseUserAgent(string $ua): string {
		$os = 'unknown OS';
		if(preg_match('/Android (\d+)/', $ua, $m))
			$os = "Android {$m[1]}";
		else if(preg_match('/(iPhone|iPad|iPod).*? OS (\d+)_/', $ua, $m))
			$os = ($m[1] === 'iPad' ? 'iPadOS ' : 'iOS ') . $m[2];
		else if(strpos($ua, 'Windows') !== false)
			$os = 'Windows';
		else if(strpos($ua, 'CrOS') !== false)
			$os = 'ChromeOS';
		else if(strpos($ua, 'Mac OS X') !== false)
			$os = 'macOS';
		else if(strpos($ua, 'Linux') !== false)
			$os = 'Linux';

		$browsers = [
			'Edge' => '/Edg(?:A|iOS)?\/(\d+)/', 'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
			'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/', 'Opera' => '/OPR\/(\d+)/',
			'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/', 'Safari' => '/Version\/(\d+).*Safari/',
		];
		$browser = 'unknown browser';
		foreach($browsers as $name => $re) {
			if(preg_match($re, $ua, $m)) {
				$browser = "$name {$m[1]}";
				break;
			}
		}
		// The Android app (Capacitor) runs in a WebView, which marks itself with "; wv)".
		if(strpos($ua, '; wv)') !== false)
			$browser = 'Android app WebView (' . $browser . ')';
		return "$browser on $os";
	}

	/** Checks that the token can reach the repository. Returns an error message, or '' when fine. */
	public static function check(?callable $get = null): string {
		if(!self::isConfigured())
			return 'Not configured.';
		$get = $get ?? [self::class, 'httpGet'];
		$res = $get(self::API . '/repos/' . self::repo(), (string) Configs::get('github_issues_token'));
		if(($res['status'] ?? 0) !== 200)
			return 'GitHub returned ' . ($res['status'] ?? 0) . ': ' . (string) ($res['body']['message'] ?? '');
		if(empty($res['body']['has_issues']))
			return 'Issues are switched off for ' . self::repo() . '.';
		return '';
	}

	/** Store the repository and token (only what changed is written). Empty strings switch it off. */
	public static function configure(string $repo, string $token): void {
		$values = [];
		if((string) Configs::get('github_issues_repo') !== $repo)
			$values['github_issues_repo'] = $repo;
		if((string) Configs::get('github_issues_token') !== $token)
			$values['github_issues_token'] = $token;
		if($values)
			FileSystemBasics::writeServerConfigs($values);
	}

	public static function fileQueue(): string {
		return PathsFS::folderData() . '.github_issues/queue.json';
	}

	/** Shortened to $max characters, at a word boundary when there is one in the second half. */
	private static function excerpt(string $text, int $max): string {
		if(mb_strlen($text) <= $max)
			return $text;
		$cut = mb_substr($text, 0, $max - 1);
		$space = mb_strrpos($cut, ' ');
		if($space !== false && $space > $max / 2)
			$cut = mb_substr($cut, 0, $space);
		return rtrim($cut) . '…';
	}

	private static function httpPost(string $url, string $token, array $payload): array {
		return self::request($url, $token, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private static function httpGet(string $url, string $token): array {
		return self::request($url, $token, null);
	}

	private static function request(string $url, string $token, ?string $body): array {
		$ch = curl_init($url);
		$headers = [
			'Accept: application/vnd.github+json',
			'Authorization: Bearer ' . $token,
			'X-GitHub-Api-Version: 2022-11-28',
			'User-Agent: iEMAbot',
		];
		if($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
		]);
		$response = curl_exec($ch);
		if($response === false) {
			$err = curl_error($ch);
			curl_close($ch);
			return ['status' => 0, 'body' => ['message' => $err]];
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$decoded = json_decode((string) $response, true);
		return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
	}
}
