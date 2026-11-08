<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\sqlite\CollectedDataDb;

/**
 * Permanent record of every provider notification for a participant who linked a wearable, for the
 * researcher's download. Unlike WearablesEventStore (a 3 day hand-off to the prompt scheduler that
 * only holds events some trigger waits for), this keeps all of them until the study is deleted (a participant who disconnects
 * stops new ones, the earlier ones stay). A notification carries no measurement, only that data of a kind changed,
 * and the period it covers when the provider says so.
 *
 * Columns of the researcher's CSV download (WearableExport) and of the wearable_events table:
 *   userId, provider
 *   event          normalised kind (sleep, activity, weight, bed_out, ...)
 *   detail         the provider's own type (Google Health `steps`, `exercise`, ...; Withings `appli` code)
 *   received_at    when the webhook reached this server (ms)
 *   interval_start / interval_end   the period in UTC (ms); empty when the provider omits it, which
 *                  Google Health does for daily rollups and full-day activities
 *   local_start / local_end         the period as the user's local clock time (ISO 8601, no zone), if sent
 *
 * An interval is passed as ['from' => ?int, 'to' => ?int, 'localFrom' => ?string, 'localTo' => ?string].
 */
class WearablesEventArchive {
	const COLUMNS = ['userId', 'provider', 'event', 'detail', 'received_at', 'interval_start', 'interval_end', 'local_start', 'local_end'];
	/** An identical notification this soon after the last one is a repeat (one batch can carry several types that map to one kind). */
	const DUPLICATE_WINDOW_MS = 60000;

	/**
	 * Keep one provider notification in the database, unless it repeats one stored moments ago (DUPLICATE_WINDOW_MS).
	 * @param array{from?:?int, to?:?int, localFrom?:?string, localTo?:?string}|null $interval
	 * @return bool false when it repeats a notification stored moments ago
	 * @throws \Throwable when the database refuses
	 */
	public static function append(int $studyId, string $userId, string $provider, string $kind, int $receivedMs, ?array $interval = null, string $detail = ''): bool {
		return CollectedDataDb::storeWearableEvent($studyId, $userId, $provider, $kind, $detail, $receivedMs, $interval, self::DUPLICATE_WINDOW_MS);
	}
}
