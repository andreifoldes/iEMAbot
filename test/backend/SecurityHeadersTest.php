<?php
declare(strict_types=1);

namespace backend;

use PHPUnit\Framework\TestCase;

/**
 * Guards the security headers that Apache sends from src/.htaccess and web-pwa/public/.htaccess, and the start
 * page's lack of inline handlers (which is what lets the Content-Security-Policy forbid inline script).
 * Not a browser test: the headers were also checked against a built image with curl and Playwright.
 */
class SecurityHeadersTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	
	private static function read(string $relativePath): string {
		$content = file_get_contents(self::ROOT . '/' . $relativePath);
		self::assertNotFalse($content, "Cannot read $relativePath");
		return $content;
	}
	
	/** The value of the first `Header always set <name> "<value>"` line, or null. */
	private static function headerValue(string $htaccess, string $name): ?string {
		if(!preg_match('/^\s*Header always set ' . preg_quote($name, '/') . ' "(.*)"\s*$/mi', $htaccess, $matches))
			return null;
		return $matches[1];
	}
	
	/** @return array<string, list<string>> directive => sources */
	private static function parsePolicy(string $policy): array {
		$directives = [];
		foreach(explode(';', $policy) as $part) {
			$tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
			if(!$tokens)
				continue;
			$directives[array_shift($tokens)] = $tokens;
		}
		return $directives;
	}
	
	function test_root_htaccess_sends_the_basic_headers() {
		$htaccess = self::read('src/.htaccess');
		$this->assertSame('nosniff', self::headerValue($htaccess, 'X-Content-Type-Options'));
		$this->assertSame('strict-origin-when-cross-origin', self::headerValue($htaccess, 'Referrer-Policy'));
		$this->assertSame('SAMEORIGIN', self::headerValue($htaccess, 'X-Frame-Options'));
		$this->assertStringContainsString('<IfModule mod_headers.c>', $htaccess);
	}
	
	function test_only_frame_ancestors_is_enforced() {
		foreach(['src/.htaccess', 'web-pwa/public/.htaccess'] as $file) {
			$enforced = self::headerValue(self::read($file), 'Content-Security-Policy');
			$this->assertNotNull($enforced, $file);
			$this->assertSame(['frame-ancestors'], array_keys(self::parsePolicy($enforced)), "$file must not enforce anything but frame-ancestors yet");
		}
	}
	
	function test_framing_is_limited_to_the_own_origin_except_for_the_telegram_handoff_page() {
		$root = self::parsePolicy(self::headerValue(self::read('src/.htaccess'), 'Content-Security-Policy'));
		$this->assertSame(["'self'"], $root['frame-ancestors']);
		
		$pwa = self::read('web-pwa/public/.htaccess');
		$handoff = self::parsePolicy(self::headerValue($pwa, 'Content-Security-Policy'));
		$this->assertSame(["'self'", 'https://web.telegram.org', 'https://*.telegram.org', 'https://telegram.org'], $handoff['frame-ancestors']);
		$this->assertStringContainsString('Header always unset X-Frame-Options', $pwa);
		$this->assertStringContainsString('<Files "handoff.html">', $pwa);
	}
	
	function test_report_only_policy_has_no_dangerous_sources() {
		$policy = self::parsePolicy(self::headerValue(self::read('src/.htaccess'), 'Content-Security-Policy-Report-Only'));
		$this->assertSame(["'self'"], $policy['default-src']);
		$this->assertSame(["'none'"], $policy['object-src']);
		$this->assertSame(["'self'"], $policy['base-uri']);
		$this->assertSame(["'self'"], $policy['form-action']);
		foreach($policy as $directive => $sources) {
			$this->assertNotContains("'unsafe-eval'", $sources, $directive);
			$this->assertNotContains('*', $sources, $directive);
			$this->assertNotContains('http:', $sources, $directive);
		}
		$this->assertNotContains("'unsafe-inline'", $policy['script-src'], 'inline script must stay forbidden');
		$this->assertSame(["'self'", 'blob:'], $policy['script-src']);
	}
	
	function test_handoff_policy_is_the_root_policy_plus_the_telegram_script() {
		$root = self::parsePolicy(self::headerValue(self::read('src/.htaccess'), 'Content-Security-Policy-Report-Only'));
		$handoff = self::parsePolicy(self::headerValue(self::read('web-pwa/public/.htaccess'), 'Content-Security-Policy-Report-Only'));
		$root['script-src'][] = 'https://telegram.org';
		$this->assertEquals($root, $handoff);
	}
	
	function test_dockerfile_enables_mod_headers() {
		$this->assertMatchesRegularExpression('/^RUN a2enmod headers\s*$/m', self::read('Dockerfile'));
	}
	
	function test_start_page_has_no_inline_event_handlers() {
		$page = self::read('src/index.php');
		$this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $page), 'index.php must not use inline on*= handlers');
		$this->assertStringContainsString('data-esmira-init=', $page);
	}
}
