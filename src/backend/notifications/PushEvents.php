<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\sqlite\CollectedDataDb;
use Throwable;

/**
 * Records and aggregates the web-push funnel for a study:
 *   sent     — accepted by the push service (server side, PushSender)
 *   failed   — rejected by the push service (expired / error)
 *   received — the participant's service worker actually got the push (client receipt)
 *   clicked  — the participant tapped the notification (client receipt)
 *
 * Events are appended as JSONL to .push_events; per-participant client telemetry
 * (installed-as-PWA + device class) is stored one file per participant under .client_info/.
 */
class PushEvents {
	const EVENTS = ['sent', 'failed', 'received', 'clicked'];
	const ONE_DAY = 86400000;
	const SERIES_DAYS = 14;

	/** Append one funnel event. Best-effort (never throws — telemetry must not break sends). */
	public static function log(int $studyId, string $userId, string $event): void {
		if(!in_array($event, self::EVENTS, true))
			return;
		try {
			$now = Main::getMilliseconds();
			$line = json_encode(['t' => $now, 'u' => $userId, 'e' => $event]) . "\n";
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
	 * @return array{totals:array, series:array, participants:array}
	 */
	public static function aggregate(int $studyId): array {
		$totals = ['sent' => 0, 'failed' => 0, 'received' => 0, 'clicked' => 0];
		$perUser = [];   // userId => [sent,received,clicked,failed]
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
				if(!isset($totals[$e]))
					continue;
				$totals[$e]++;
				$u = (string) ($row['u'] ?? '');
				if($u !== '') {
					if(!isset($perUser[$u]))
						$perUser[$u] = ['sent' => 0, 'received' => 0, 'clicked' => 0, 'failed' => 0];
					$perUser[$u][$e]++;
				}
				$t = (int) $row['t'];
				if($t >= $seriesFrom && $e !== 'failed') {
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

		$participants = [];
		foreach($perUser as $u => $c)
			$participants[] = ['u' => $u, 'sent' => $c['sent'], 'received' => $c['received'], 'clicked' => $c['clicked']];
		usort($participants, fn($a, $b) => $b['sent'] <=> $a['sent']);

		return ['totals' => $totals, 'series' => $series, 'participants' => $participants];
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
