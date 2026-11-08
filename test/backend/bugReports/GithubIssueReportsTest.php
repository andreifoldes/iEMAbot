<?php

namespace backend\bugReports;

use backend\channels\store\JsonFile;
use backend\Configs;
use backend\Paths;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class GithubIssueReportsTest extends BaseDataFolderTestSetup {
	const NOW = 1741600000000;
	const USER = 'qx81Kd02pZ';
	/** As built by the PWA (web-pwa/src/App.tsx, buildErrorReport). */
	const REPORT = "--- iEMAbot error report ---\n\n"
		. "Participant note:\nThe save button `froze` after the sleep question.\n\nMail me at jane.doe@example.org or call +44 7700 900123, I'm @janed on Telegram.\n\n"
		. "App version: 3.7.2\nServer version: 12\nServer: https://ema.example.org/esmira/\nStudy: Sleep and mood in Leeds (id 7)\n"
		. "User id: qx81Kd02pZ\nPending uploads: 2\nOnline: true\nLanguage: en-GB\n"
		. "User agent: Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.6723.58 Mobile Safari/537.36\n\n"
		. "Recent errors (2):\n"
		. "  [2026-10-28T09:15:02.123Z] fetch failed: https://ema.example.org/esmira/api/datasets.php?key=abcSecret&uid=qx81Kd02pZ\n"
		. "  [2026-10-28T09:15:04.000Z] TypeError: x is undefined at main-3f9a.js:1:2345";

	/** @var array[] [url, token, payload] of every post */
	private $posts = [];
	/** @var int[] status returned by the fake GitHub for each post, in order (default 201) */
	private $statuses = [];
	/** @var bool */
	private $configExisted;

	protected function setUp(): void {
		self::setUpBeforeClass();
		$this->configExisted = file_exists(Paths::FILE_CONFIG);
		JsonFile::delete(GithubIssueReports::fileQueue());
		$this->configure('owner/repo', 'tok');
		$this->posts = [];
		$this->statuses = [];
	}

	protected function tearDown(): void {
		// configure() writes the server config file.
		if(!$this->configExisted && file_exists(Paths::FILE_CONFIG))
			unlink(Paths::FILE_CONFIG);
		Configs::resetConfig();
	}

	private function configure(string $repo, string $token): void {
		Configs::resetConfig(array_merge(Configs::getAll(), [
			'github_issues_repo' => $repo,
			'github_issues_token' => $token,
			'github_issues_labels' => ['participant-report'],
		]));
	}

	private function run_(int $now = self::NOW): array {
		return GithubIssueReports::run(function(string $url, string $token, array $payload) {
			$this->posts[] = [$url, $token, $payload];
			$status = array_shift($this->statuses) ?? 201;
			return ['status' => $status, 'body' => $status === 201 ? ['number' => 1] : ['message' => 'Bad credentials']];
		}, $now, function(string $url) {
			$this->gets[] = $url;
			return ['status' => 200, 'body' => ['private' => $this->repoIsPrivate, 'has_issues' => true]];
		});
	}

	private $gets = [];
	private $repoIsPrivate = true;
	
	function test_nothing_is_posted_to_a_repository_that_is_not_private() {
		GithubIssueReports::enqueue(5, 'user-one-id', "--- iEMAbot error report ---\nParticipant note:\nmy note");
		$this->repoIsPrivate = false;
		$result = $this->run_();
		$this->assertSame(0, $result['sent']);
		$this->assertSame(1, $result['queued']);
		$this->assertStringContainsString(GithubIssueReports::NOT_PRIVATE, $result['error']);
		$this->assertSame([], $this->posts, 'nothing may leave for a public repository');
		
		//once it is private, the same report goes out
		$this->repoIsPrivate = true;
		$result = $this->run_();
		$this->assertSame(1, $result['sent']);
		$this->assertCount(1, $this->posts);
	}
	
	function test_the_repository_is_only_looked_up_when_there_is_something_to_send() {
		$this->run_();
		$this->assertSame([], $this->gets);
	}
	
	function test_a_failed_lookup_of_the_repository_posts_nothing() {
		GithubIssueReports::enqueue(5, 'user-one-id', "--- iEMAbot error report ---\nParticipant note:\nmy note");
		$result = GithubIssueReports::run(function() { $this->posts[] = 'posted'; return ['status' => 201, 'body' => []]; }, self::NOW,
			function() { return ['status' => 502, 'body' => []]; });
		$this->assertSame(0, $result['sent']);
		$this->assertSame(1, $result['queued']);
		$this->assertStringContainsString('502', $result['error']);
		$this->assertSame([], $this->posts);
	}
	
	function test_only_error_reports_are_queued() {
		$this->assertFalse(GithubIssueReports::enqueue(1, 'u1', 'Hello, a normal message', self::NOW));
		$this->assertTrue(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW));
		$this->assertSame(['sent' => 1, 'queued' => 0, 'dropped' => 0], $this->run_());
	}

	function test_nothing_is_queued_or_sent_without_configuration() {
		$this->configure('', '');
		$this->assertFalse(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW));
		$this->configure('not a repo', 'tok');
		$this->assertFalse(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW));
		$this->assertSame(0, $this->run_()['sent']);
		$this->assertSame([], $this->posts);
	}

	function test_issue_keeps_the_note_and_diagnostics_but_no_identifiers() {
		GithubIssueReports::enqueue(7, self::USER, self::REPORT, self::NOW);
		$this->run_();
		[$url, $token, $payload] = $this->posts[0];
		$this->assertSame('https://api.github.com/repos/owner/repo/issues', $url);
		$this->assertSame('tok', $token);
		$this->assertSame(['participant-report'], $payload['labels']);
		$this->assertSame('Error report: The save button `froze` after the sleep question. Mail me at [email] or call…', $payload['title']);
		$body = $payload['body'];
		$this->assertStringContainsString("- Study id: 7\n- Participant: qx81K… (first half of the user id)", $body);

		// The free text is kept, minus contact details.
		$this->assertStringContainsString("The save button `froze` after the sleep question.\n\nMail me at [email] or call [number], I'm [handle] on Telegram.", $body);
		foreach(['App version: 3.7.2', 'Server version: 12', 'Pending uploads: 2', 'Online: true', 'Language: en-GB',
			'Browser: Android app WebView (Chrome 130) on Android 14',
			"[2026-10-28T09:15:02.123Z] fetch failed: https://ema.example.org/esmira/api/datasets.php?[removed]\n",
			'[2026-10-28T09:15:04.000Z] TypeError: x is undefined at main-3f9a.js:1:2345'] as $kept)
			$this->assertStringContainsString($kept, $body);

		foreach([self::USER, 'jane.doe', '7700', 'janed', 'abcSecret', 'SM-S918B', 'UP1A', 'ema.example.org/esmira/\n', 'Sleep and mood', 'User id'] as $gone)
			$this->assertStringNotContainsString($gone, $body);
		$this->assertStringNotContainsString(self::USER, $payload['title']);

		// The note contains ``` so the fence must be longer.
		$this->assertStringContainsString("````text\n", $body);
	}

	function test_title_without_a_note_names_the_study() {
		$issue = GithubIssueReports::buildIssue(['studyId' => 9, 'userId' => 'u1', 'content' => "--- iEMAbot error report ---\n\nApp version: 1\n\nNo client errors recorded.", 'receivedMs' => self::NOW]);
		$this->assertSame('Error report: study 9', $issue['title']);
		$this->assertStringContainsString("App version: 1\n\nNo app errors recorded.", $issue['body']);
	}

	function test_scrub_removes_contact_details_and_identifiers() {
		$cases = [
			'write to a.b+c@example.org please' => 'write to [email] please',
			'my number is 07700 900 123' => 'my number is [number]',
			'born 12/03/1990' => 'born [number]',
			'from 192.0.2.4' => 'from [ip]',
			'see https://x.org/p?token=abc#f now' => 'see https://x.org/p?[removed] now',
			'sent key=abc123 and pid=P042' => 'sent key=[removed] and pid=[removed]',
			'session 3f2a9c1e-77b4-4c1e-9a7e-0d5f4b2c8e11 lost' => 'session [token] lost',
			'ask @john_smith' => 'ask [handle]',
			'participant ABC123 here' => 'participant abc… here',
			'GET /api/x?uid=abc123 failed' => 'GET /api/x?uid=[removed] failed',
			// Ordinary bug descriptions survive.
			'The 3rd question froze at 10:30 on version 3.7.2, twice' => 'The 3rd question froze at 10:30 on version 3.7.2, twice',
		];
		foreach($cases as $in => $out)
			$this->assertSame($out, GithubIssueReports::scrub($in, 'abc123'), $in);
	}

	function test_user_id_keeps_only_its_first_half() {
		$this->assertSame('qx81K…', GithubIssueReports::maskUserId(self::USER));
		$this->assertSame('P0…', GithubIssueReports::maskUserId('P042'));
		$this->assertSame('—', GithubIssueReports::maskUserId(''));
		// A numeric id is masked like any other, not taken for a phone number.
		$this->assertSame('error for 12345678… at 10:30', GithubIssueReports::scrub('error for 1234567890123456 at 10:30', '1234567890123456'));
	}

	function test_user_agent_keeps_only_browser_and_os() {
		$cases = [
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1' => 'Safari 17 on iOS 17',
			'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36' => 'Samsung Internet 25 on Android 13',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' => 'Edge 130 on Windows',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:131.0) Gecko/20100101 Firefox/131.0' => 'Firefox 131 on macOS',
			'curl/8.0' => 'unknown browser on unknown OS',
		];
		foreach($cases as $ua => $out)
			$this->assertSame($out, GithubIssueReports::coarseUserAgent($ua));
	}

	function test_rate_limits_per_participant_and_per_server() {
		for($i = 0; $i < GithubIssueReports::MAX_PER_USER_PER_DAY; ++$i)
			$this->assertTrue(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW + $i));
		$this->assertFalse(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW + 10));
		// Same user id in another study counts separately.
		$this->assertTrue(GithubIssueReports::enqueue(2, 'u1', self::REPORT, self::NOW + 10));
		// A day later the participant can report again.
		$this->assertTrue(GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW + 86400000 + 10));

		$accepted = 0;
		for($i = 0; $i < GithubIssueReports::MAX_PER_DAY + 5; ++$i)
			$accepted += GithubIssueReports::enqueue(3, "p$i", self::REPORT, self::NOW + 86400000 + 20) ? 1 : 0;
		// One slot of the server-wide budget is taken by the report accepted a day later above.
		$this->assertSame(GithubIssueReports::MAX_PER_DAY - 1, $accepted);
	}

	function test_a_failure_keeps_the_rest_for_the_next_run() {
		GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW);
		GithubIssueReports::enqueue(1, 'u2', self::REPORT, self::NOW);
		GithubIssueReports::enqueue(1, 'u3', self::REPORT, self::NOW);
		$this->statuses = [201, 401];
		$result = $this->run_();
		$this->assertSame(1, $result['sent']);
		$this->assertSame(2, $result['queued']);
		$this->assertStringContainsString('401', $result['error']);

		$this->assertSame(['sent' => 2, 'queued' => 0, 'dropped' => 0], $this->run_());
		$this->assertCount(4, $this->posts);
	}

	function test_reports_older_than_a_week_are_dropped() {
		GithubIssueReports::enqueue(1, self::USER, self::REPORT, self::NOW);
		GithubIssueReports::enqueue(1, 'u2', self::REPORT, self::NOW + GithubIssueReports::MAX_AGE_MS);
		$result = $this->run_(self::NOW + GithubIssueReports::MAX_AGE_MS + 1);
		$this->assertSame(['sent' => 1, 'queued' => 0, 'dropped' => 1], $result);
	}

	function test_configure_writes_only_changes_and_check_reports_problems() {
		GithubIssueReports::configure('owner/other', 'tok2');
		$this->assertSame('owner/other', GithubIssueReports::repo());
		$this->assertSame('', GithubIssueReports::check(function() { return ['status' => 200, 'body' => ['has_issues' => true, 'private' => true]]; }));
		$this->assertStringStartsWith(GithubIssueReports::NOT_PRIVATE, GithubIssueReports::check(function() { return ['status' => 200, 'body' => ['has_issues' => true, 'private' => false]]; }));
		$this->assertStringStartsWith(GithubIssueReports::NOT_PRIVATE, GithubIssueReports::check(function() { return ['status' => 200, 'body' => ['has_issues' => true]]; }), 'unknown counts as not private');
		$this->assertStringContainsString('switched off', GithubIssueReports::check(function() { return ['status' => 200, 'body' => ['has_issues' => false, 'private' => true]]; }));
		$this->assertStringContainsString('404', GithubIssueReports::check(function() { return ['status' => 404, 'body' => ['message' => 'Not Found']]; }));
		GithubIssueReports::configure('', '');
		$this->assertFalse(GithubIssueReports::isConfigured());
	}
}
