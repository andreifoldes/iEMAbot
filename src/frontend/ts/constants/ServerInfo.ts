// Server-wide flags injected at page load by index.php via ESMira.init().
// Read by admin sections to adapt the UI to this server's configuration.
export const ServerInfo = {
	// When true, iOS/Android-only controls are greyed out (web PWA only deployment).
	webOnlyMode: false,
	// Whether a Telegram bot token is set on this server, and the bot's public @username
	// (without "@"). Telegram is optional: its study panels and switches only show when a bot is set up.
	telegramConfigured: false,
	telegramBotUsername: "",
}
