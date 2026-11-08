<?php
declare(strict_types=1);

namespace backend;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../autoload.php';

class SelfHostTest extends TestCase {
	protected function tearDown(): void {
		putenv(SelfHost::ENV_CONTAINER_IMAGE);
		putenv(SelfHost::ENV_PUBLIC_URL);
	}
	
	function test_every_address_follows_from_the_public_url() {
		$this->assertSame([
			'wearables_redirect_uri' => 'https://ema.example.org/esmira/api/wearables_oauth.php',
			'channels_public_base_url' => 'https://ema.example.org/esmira/',
		], SelfHost::configFor('https://ema.example.org'));
	}
	
	function test_a_trailing_slash_or_port_makes_no_trouble() {
		$config = SelfHost::configFor('https://localhost:8443/');
		$this->assertSame('https://localhost:8443/esmira/', $config['channels_public_base_url']);
	}
	
	function test_the_contact_email_identifies_the_server_to_push_services() {
		$config = SelfHost::configFor('https://ema.example.org', 'lab@example.org');
		$this->assertSame('mailto:lab@example.org', $config['vapid_subject']);
	}
	
	/** @dataProvider unusableAddresses */
	function test_unusable_addresses_are_refused(string $url) {
		$this->expectException(InvalidArgumentException::class);
		SelfHost::configFor($url);
	}
	public function unusableAddresses(): array {
		return [
			'empty' => [''],
			'no scheme' => ['ema.example.org'],
			'other scheme' => ['ftp://ema.example.org'],
			'with a path' => ['https://ema.example.org/esmira'],
		];
	}
	
	function test_an_unusable_email_is_refused() {
		$this->expectException(InvalidArgumentException::class);
		SelfHost::configFor('https://ema.example.org', 'not an address');
	}
	
	function test_only_what_differs_counts_as_a_change() {
		$wanted = ['a' => '1', 'b' => '2', 'c' => '3'];
		$this->assertSame(['b' => '2', 'c' => '3'], SelfHost::changedValues($wanted, ['a' => '1', 'b' => 'old', 'other' => 'x']));
		$this->assertSame([], SelfHost::changedValues($wanted, $wanted));
	}
	
	function test_the_first_run_page_stays_shut_on_a_kit_server() {
		$this->assertTrue(SelfHost::firstRunPageAllowed());
		putenv(SelfHost::ENV_PUBLIC_URL . '=https://ema.example.org');
		$this->assertFalse(SelfHost::firstRunPageAllowed());
	}
	
	function test_it_knows_when_it_runs_from_an_image() {
		$this->assertFalse(SelfHost::runsFromContainerImage());
		putenv(SelfHost::ENV_CONTAINER_IMAGE . '=1');
		$this->assertTrue(SelfHost::runsFromContainerImage());
	}
}
