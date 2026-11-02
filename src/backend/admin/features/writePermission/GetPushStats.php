<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\Configs;
use backend\Main;
use backend\notifications\NotificationPreview;
use backend\notifications\NotificationSpec;
use backend\notifications\ParticipantNotificationPrefs;
use backend\notifications\ParticipantWithdrawals;
use backend\notifications\PushEvents;
use backend\notifications\PushSender;

/**
 * Notification analytics for the study admin panel, across channels: VAPID status, subscriber count,
 * install-vs-browser + device breakdown, the delivery/engagement funnel overall and per channel
 * (web push: sent -> received -> clicked; Android app: scheduled/sent -> received -> clicked) with a
 * per-day series and per-participant breakdown, a preview of the notifications participants get
 * (identical on every channel, see NotificationPreview), and
 * the sender heartbeat (so the panel can flag a dead per-minute cron), and the participants
 * who have left the study (withdrawn). `nowMs` is the
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
			'withdrawn'       => ParticipantWithdrawals::listForStudy($this->studyId),
			// Participants who switched notifications off in the app (they stay in the study).
			'notificationsOff' => ParticipantNotificationPrefs::listForStudy($this->studyId),
			'preview'         => $this->preview(),
			'look'            => ['icon' => NotificationSpec::ICON, 'badge' => NotificationSpec::BADGE, 'accent' => NotificationSpec::ACCENT_COLOR],
			'nowMs'           => Main::getMilliseconds(),
		];
	}

	private function preview(): array {
		try {
			$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($this->studyId, Configs::get('defaultLang') ?: 'en'), true);
			return is_array($study) ? NotificationPreview::build($study, Main::getMilliseconds()) : [];
		}
		catch(\Throwable $e) {
			return [];
		}
	}
}
