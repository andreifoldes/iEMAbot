<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\sqlite\CollectedDataDb;
use Throwable;

/**
 * Records and aggregates the notification funnel for a study, per delivery channel:
 *   scheduled — android: the app handed a notification to the phone's alarm service
 *   sent      — web: accepted by the push service (PushSender); android: a server nudge handed to the app
 *   failed    — web: rejected by the push service (expired / error)
 *   received  — the notification reached the device (web: service worker receipt; android: shown)
 *   clicked   — the participant tapped the notification (client receipt)
 * Rows without a channel predate the Android app's reporting and count as web.
 *
 * Besides the funnel, two participant-reported states are logged and aggregated per participant:
 *   welcome_confirmed / welcome_missed   — at onboarding, whether the welcome notification showed up
 *   notifications_off / notifications_on — the participant's switch in the app's settings
 *
 * Events are appended as JSONL to .push_events; per-participant client telemetry
 * (installed-as-PWA + device class) is stored one file per participant under .client_info/.
 */
class PushEvents {
	const FUNNEL = ['scheduled', 'sent', 'failed', 'received', 'clicked'];
	const WELCOME = ['welcome_confirmed', 'welcome_missed'];
	const SWITCH = ['notifications_off', 'notifications_on'];
	const EVENTS = [...self::FUNNEL, ...self::WELCOME, ...self::SWITCH];
	const CHANNELS = ['web', 'android'];
	const ONE_DAY = 86400000;
	const SERIES_DAYS = 14;

	/** Append one funnel event. Best-effort (never throws — telemetry must not break sends). */
	public static function log(int $studyId, string $userId, string $event, string $channel = 'web'): void {
		if(!in_array($event, self::EVENTS, true))
			return;
		if(!in_array($channel, self::CHANNELS, true))
			$channel = 'web';
		try {
			$now = Main::getMilliseconds();
			$line = json_encode(['t' => $now, 'u' => $userId, 'e' => $event, 'c' => $channel]) . "\n";
			@file_put_contents(PathsFS::filePushEvents($studyId), $line, FILE_APPEND | LOCK_EX);
			CollectedDataDb::addPushEvent($studyId, $userId, $event, $now);
		}
		catch(Throwable $e) { /* non-fatal */ }
	}

	/** Store/refresh a participant's client telemetry (latest wins). */
	public static function saveClientInfo(int $studyId, string $userId, bool $installed, string $device): void {
		try {
			$folder = PathsFS::folderClientInfo($studyId);
			if(!is_dir($folder))
				FileSystemBasics::createFolder($folder, true);
			$now = Main::getMilliseconds();
			FileSystemBasics::writeFile(PathsFS::fileClientInfo($studyId, $userId), json_encode([
				'installed' => $installed,
				'device'    => $device,
				'updated'   => $now,
			]));
			CollectedDataDb::saveClientInfo($studyId, $userId, $installed, $device, $now);
		}
		catch(Throwable $e) { /* non-fatal */ }
	}

	/**
	 * Aggregate the funnel: overall totals, a per-day series (last SERIES_DAYS days), and
	 * a per-participant breakdown. Streams the JSONL so a large log doesn't blow memory.
	 * Welcome receipts and the notification switch are not funnel steps: they come back as `welcome`
	 * totals and per participant (`welcome`: their latest answer, `off`: whether their latest switch is off).
	 * @return array{totals:array, channels:array, welcome:array, series:array, participants:array}
	 */
	public static function aggregate(int $studyId): array {
		$totals = ['scheduled' => 0, 'sent' => 0, 'failed' => 0, 'received' => 0, 'clicked' => 0];
		$channels = [];
		foreach(self::CHANNELS as $c)
			$channels[$c] = $totals;
		$welcome = ['confirmed' => 0, 'missed' => 0];
		$perUser = [];   // userId => [sent,received,clicked,failed]
		$userWelcome = []; // userId => 'confirmed' | 'missed' (latest)
		$userOff = [];     // userId => bool (latest switch)
		$perDay = [];    // dayMs => [sent,received,clicked]
		$now = Main::getMilliseconds();
		$todayMidnight = intdiv($now, self::ONE_DAY) * self::ONE_DAY;
		$seriesFrom = $todayMidnight - (self::SERIES_DAYS - 1) * self::ONE_DAY;

		$file = PathsFS::filePushEvents($studyId);
		if(is_file($file) && ($fp = @fopen($file, 'r')) !== false) {
			while(($line = fgets($fp)) !== false) {
				$row = json_decode($line, true);
				if(!is_array($row) || !isset($row['e'], $row['t']))
					continue;
				$e = $row['e'];
				$u = (string) ($row['u'] ?? '');
				if(in_array($e, self::WELCOME, true)) {
					$w = $e === 'welcome_confirmed' ? 'confirmed' : 'missed';
					$welcome[$w]++;
					if($u !== '')
						$userWelcome[$u] = $w;
					continue;
				}
				if(in_array($e, self::SWITCH, true)) {
					if($u !== '')
						$userOff[$u] = $e === 'notifications_off';
					continue;
				}
				if(!isset($totals[$e]))
					continue;
				$totals[$e]++;
				$c = in_array($row['c'] ?? 'web', self::CHANNELS, true) ? ($row['c'] ?? 'web') : 'web';
				$channels[$c][$e]++;
				if($u !== '') {
					if(!isset($perUser[$u]))
						$perUser[$u] = ['scheduled' => 0, 'sent' => 0, 'received' => 0, 'clicked' => 0, 'failed' => 0, 'c' => $c];
					$perUser[$u][$e]++;
					$perUser[$u]['c'] = $c; // the channel the participant was last reached on
				}
				$t = (int) $row['t'];
				if($t >= $seriesFrom && in_array($e, ['sent', 'received', 'clicked'], true)) {
					$day = intdiv($t, self::ONE_DAY) * self::ONE_DAY;
					if(!isset($perDay[$day]))
						$perDay[$day] = ['sent' => 0, 'received' => 0, 'clicked' => 0];
					$perDay[$day][$e]++;
				}
			}
			fclose($fp);
		}

		$series = [];
		for($d = 0; $d < self::SERIES_DAYS; $d++) {
			$day = $seriesFrom + $d * self::ONE_DAY;
			$c = $perDay[$day] ?? ['sent' => 0, 'received' => 0, 'clicked' => 0];
			$series[] = ['day' => $day, 'sent' => $c['sent'], 'received' => $c['received'], 'clicked' => $c['clicked']];
		}

		// A participant who only answered the welcome (or flipped the switch) is listed too: a missed
		// welcome is exactly the participant a researcher needs to see before anything was sent.
		foreach(array_keys($userWelcome + $userOff) as $u) {
			if(!isset($perUser[$u]))
				$perUser[$u] = ['scheduled' => 0, 'sent' => 0, 'received' => 0, 'clicked' => 0, 'failed' => 0, 'c' => 'web'];
		}
		$participants = [];
		foreach($perUser as $u => $c)
			$participants[] = [
				'u' => (string) $u, 'channel' => $c['c'], 'scheduled' => $c['scheduled'], 'sent' => $c['sent'], 'received' => $c['received'], 'clicked' => $c['clicked'],
				'welcome' => $userWelcome[$u] ?? null, 'off' => $userOff[$u] ?? false,
			];
		usort($participants, fn($a, $b) => ($b['sent'] + $b['scheduled']) <=> ($a['sent'] + $a['scheduled']));

		return ['totals' => $totals, 'channels' => $channels, 'welcome' => $welcome, 'series' => $series, 'participants' => $participants];
	}

	/** Aggregate install/device telemetry across participants. */
	public static function aggregateClientInfo(int $studyId): array {
		$installed = 0; $browser = 0;
		$devices = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 0];
		$folder = PathsFS::folderClientInfo($studyId);
		if(is_dir($folder)) {
			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				$info = json_decode(@file_get_contents($folder . $entry), true);
				if(!is_array($info))
					continue;
				if(!empty($info['installed'])) $installed++; else $browser++;
				$dev = $info['device'] ?? 'unknown';
				if(!isset($devices[$dev])) $dev = 'unknown';
				$devices[$dev]++;
			}
		}
		return ['installed' => $installed, 'browser' => $browser, 'total' => $installed + $browser, 'devices' => $devices];
	}
}
