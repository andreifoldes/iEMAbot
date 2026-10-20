<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\Configs;
use backend\Main;
use backend\notifications\PushEvents;
use backend\notifications\PushSender;

/**
 * Full web-push analytics for the study admin panel: VAPID status, subscriber count,
 * install-vs-browser + device breakdown, the delivery/engagement funnel
 * (sent -> received -> clicked) with a per-day series and per-participant breakdown, and
 * the sender heartbeat (so the panel can flag a dead per-minute cron). `nowMs` is the
 * server clock, so the frontend computes heartbeat age without client-clock skew.
 */
class GetPushStats extends HasWritePermission {
	function exec(): array {
		return [
			'vapidConfigured' => !empty(Configs::get('vapid_public_key')),
			'subscriptions'   => PushSender::countSubscriptions($this->studyId),
			'clients'         => PushEvents::aggregateClientInfo($this->studyId),
			'events'          => PushEvents::aggregate($this->studyId),
			'sender'          => PushSender::readHeartbeat(),
			'nowMs'           => Main::getMilliseconds(),
		];
	}
}
