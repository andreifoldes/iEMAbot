<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-store');

$startUrl = isset($_GET['start']) ? filter_var($_GET['start'], FILTER_SANITIZE_URL) : '/';

echo json_encode([
	'name'             => 'iEMAbot',
	'short_name'       => 'iEMAbot',
	'description'      => 'Participate in iEMAbot research studies',
	'display'          => 'standalone',
	'start_url'        => $startUrl,
	'background_color' => '#ffffff',
	'theme_color'      => '#00471c',
	'icons'            => [
		[
			'src'     => 'frontend/assets/iemabot/android-chrome-192x192.png',
			'sizes'   => '192x192',
			'type'    => 'image/png',
			'purpose' => 'any',
		],
		[
			'src'     => 'frontend/assets/iemabot/android-chrome-512x512.png',
			'sizes'   => '512x512',
			'type'    => 'image/png',
			'purpose' => 'any maskable',
		],
	],
], JSON_UNESCAPED_SLASHES);
