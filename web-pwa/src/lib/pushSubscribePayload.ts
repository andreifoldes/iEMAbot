import type { EsmiraStudy } from '../types.ts';
import { getCurrentLang } from './i18n.ts';

/**
 * Body of the `push_subscribe.php` request. Besides the subscription it carries `tzOffset` (so the server
 * schedules "08:00" in the participant's time) and two languages: `uiLang`, the app's interface language, for the
 * server's own notification wording, and `studyLang`, the language the study arrived in, so the researcher's
 * reminder text is loaded in the same one.
 */
export function pushSubscribePayload(args: {
  study: Pick<EsmiraStudy, 'id' | 'lang'>;
  serverVersion: number;
  userId: string;
  subscription: PushSubscriptionJSON;
}) {
  return {
    userId: args.userId,
    studyId: args.study.id,
    serverVersion: args.serverVersion,
    subscription: args.subscription,
    tzOffset: new Date().getTimezoneOffset(),
    uiLang: getCurrentLang(),
    studyLang: args.study.lang,
  };
}
