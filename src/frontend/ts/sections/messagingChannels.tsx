import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang, LangKey } from "../singletons/Lang";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN } from "../constants/urls";
import { TitleRow } from "../components/TitleRow";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { safeConfirm } from "../constants/methods";
import { ServerInfo } from "../constants/ServerInfo";

/** Secret-free bot status from TelegramSetup::status() (backend/channels/telegram/TelegramSetup.php). */
export interface TelegramStatus {
	configured: boolean
	username: string
	transport: "polling" | "webhook"
	encryptionKey: boolean
	publicBaseUrl: string
	lastPollMs: number
	lastPollError: string
	dispatcherLastRunMs: number
	boundParticipants: number
	nowMs: number
	webhookUrl?: string
	webhookLastError?: string
	pendingUpdates?: number
	apiError?: string
}

/** "x s" / "x min" / "x h" since `thenMs`, or null if it never happened. */
export function telegramAge(thenMs: number, nowMs: number): { text: string, seconds: number } | null {
	if (!thenMs)
		return null
	const seconds = Math.max(0, Math.round((nowMs - thenMs) / 1000))
	let text
	if (seconds < 90)
		text = `${seconds} s`
	else if (seconds < 5400)
		text = `${Math.round(seconds / 60)} min`
	else
		text = `${Math.round(seconds / 3600)} h`
	return { text: text, seconds: seconds }
}

/**
 * Server-wide messaging channels (currently the Telegram bot): bot token, how updates arrive
 * (polling or webhook), the public base URL and the liveness of the poller and reminder dispatcher.
 * Every action is applied immediately through its own admin endpoint; the token is write-only.
 */
export class Content extends SectionContent {
	private status: TelegramStatus
	private token = ""
	private transport: "polling" | "webhook"
	private webhookUrl: string
	private baseUrl: string

	public static preLoad(_sectionData: SectionData): Promise<any>[] {
		return [Requests.loadJson(`${FILE_ADMIN}?type=GetTelegramStatus`)]
	}

	constructor(sectionData: SectionData, status: TelegramStatus) {
		super(sectionData)
		this.status = status
		this.transport = status.transport
		this.baseUrl = status.publicBaseUrl
		this.webhookUrl = status.webhookUrl || this.suggestWebhookUrl()
	}

	public title(): string {
		return Lang.get("messaging_channels")
	}

	private suggestWebhookUrl(): string {
		const base = this.baseUrl || this.status.publicBaseUrl
		return base ? `${base.replace(/\/?$/, "/")}api/channel_webhook.php?channel=telegram` : ""
	}

	private setStatus(status: TelegramStatus): void {
		this.status = status
		ServerInfo.telegramConfigured = status.configured
		ServerInfo.telegramBotUsername = status.username
		m.redraw()
	}

	private async run(type: string, data: string = ""): Promise<any> {
		try {
			return await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=${type}`, "post", data)
		}
		catch (e) {
			return null //error is already shown by the loader
		}
	}

	private async reload(): Promise<void> {
		const status = await this.run("GetTelegramStatus")
		if (status)
			this.setStatus(status)
	}

	private async saveToken(): Promise<void> {
		const token = this.token.trim()
		if (!token)
			return
		const result = await this.run("SaveTelegramToken", `token=${encodeURIComponent(token)}`)
		if (!result)
			return
		this.token = ""
		this.transport = "polling"
		await this.reload()
		this.sectionData.loader.info(Lang.get("telegram_token_saved", result.username))
	}

	private async removeToken(): Promise<void> {
		if (!safeConfirm(Lang.get("confirm_telegram_remove_bot")))
			return
		const status = await this.run("RemoveTelegramToken")
		if (status)
			this.setStatus(status)
	}

	private async applyTransport(): Promise<void> {
		const data = this.transport == "webhook"
			? `transport=webhook&url=${encodeURIComponent(this.webhookUrl.trim())}`
			: "transport=polling"
		const status = await this.run("SetTelegramTransport", data)
		if (status) {
			this.setStatus(status)
			this.sectionData.loader.info(Lang.get("info_successful"))
		}
	}

	private async saveBaseUrl(): Promise<void> {
		const status = await this.run("SetChannelsBaseUrl", `url=${encodeURIComponent(this.baseUrl.trim())}`)
		if (!status)
			return
		this.baseUrl = status.publicBaseUrl
		if (!this.status.webhookUrl)
			this.webhookUrl = this.suggestWebhookUrl()
		this.setStatus({ ...this.status, publicBaseUrl: status.publicBaseUrl })
		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	/** Age of a heartbeat, flagged when older than `staleSec`. */
	private heartbeatView(label: LangKey, thenMs: number, staleSec: number): Vnode<any, any> {
		const age = telegramAge(thenMs, this.status.nowMs)
		return <div>
			<span>{Lang.getWithColon(label)} </span>
			{!age
				? <span class="highlight">{Lang.get("telegram_never")}</span>
				: age.seconds > staleSec
					? <span class="highlight">⚠ {Lang.get("telegram_stale", age.text)}</span>
					: <span>{Lang.get("telegram_ago", age.text)}</span>}
		</div>
	}

	private statusView(): Vnode<any, any> {
		const s = this.status
		const usesWebhook = s.transport == "webhook"
		return <div class="center">
			<div class="spacingBottom">
				<span>{Lang.getWithColon("telegram_bot")} </span>
				{s.configured && s.username
					? <a href={`https://t.me/${s.username}`} target="_blank"><b>@{s.username}</b></a>
					: <span class="highlight">{Lang.get("telegram_no_bot")}</span>}
			</div>
			{s.configured && <div>
				<div>{Lang.getWithColon("telegram_transport")} {usesWebhook ? Lang.get("telegram_webhook") : Lang.get("telegram_polling")}</div>
				{usesWebhook
					? s.webhookUrl !== undefined && <div><small style="word-break:break-all">{s.webhookUrl}</small></div>
					: this.heartbeatView("telegram_last_poll", s.lastPollMs, 180)}
				{this.heartbeatView("telegram_dispatcher", s.dispatcherLastRunMs, 180)}
				<div>{Lang.getWithColon("telegram_connected_participants")} {s.boundParticipants}</div>
				{!!s.pendingUpdates && <div>{Lang.getWithColon("telegram_pending_updates")} {s.pendingUpdates}</div>}
				{!usesWebhook && s.lastPollError && <div class="highlight">{Lang.getWithColon("telegram_last_error")} {s.lastPollError}</div>}
				{usesWebhook && s.webhookLastError && <div class="highlight">{Lang.getWithColon("telegram_last_error")} {s.webhookLastError}</div>}
				{s.apiError && <div class="highlight">{Lang.getWithColon("telegram_api_error")} {s.apiError}</div>}
				{!s.encryptionKey && <div class="highlight">{Lang.get("telegram_no_encryption_key")}</div>}
			</div>}
			<div class="spacingTop">
				<input type="button" value={Lang.get("reload")} onclick={this.reload.bind(this)} />
			</div>
		</div>
	}

	private tokenView(): Vnode<any, any> {
		return <div class="center">
			<label>
				<small>{Lang.get(this.status.configured ? "telegram_bot_token_replace" : "telegram_bot_token")}</small>
				<input type="password" autocomplete="off" placeholder="123456789:AA…" value={this.token}
					oninput={(e: InputEvent) => { this.token = (e.target as HTMLInputElement).value }} />
			</label>
			<input type="button" value={Lang.get("telegram_save_token")} disabled={!this.token.trim()} onclick={this.saveToken.bind(this)} />
			{this.status.configured &&
				<div class="spacingTop">
					<input type="button" value={Lang.get("telegram_remove_bot")} onclick={this.removeToken.bind(this)} />
				</div>}
		</div>
	}

	private transportView(): Vnode<any, any> {
		const choose = (transport: "polling" | "webhook") => () => {
			this.transport = transport
			if (transport == "webhook" && !this.webhookUrl)
				this.webhookUrl = this.suggestWebhookUrl()
		}
		return <div class="center">
			<label class="noTitle noDesc">
				<input type="radio" name="telegramTransport" checked={this.transport == "polling"} onchange={choose("polling")} />
				<span>{Lang.get("telegram_polling")}</span>
			</label>
			<label class="noTitle noDesc">
				<input type="radio" name="telegramTransport" checked={this.transport == "webhook"} onchange={choose("webhook")} />
				<span>{Lang.get("telegram_webhook")}</span>
			</label>
			{this.transport == "webhook" &&
				<label class="line">
					<small>{Lang.get("telegram_webhook_url")}</small>
					<input type="text" placeholder="https://…/api/channel_webhook.php?channel=telegram" value={this.webhookUrl}
						oninput={(e: InputEvent) => { this.webhookUrl = (e.target as HTMLInputElement).value }} />
				</label>}
			<div class="spacingTop">
				<input type="button" value={Lang.get("telegram_apply_transport")} disabled={!this.status.configured} onclick={this.applyTransport.bind(this)} />
			</div>
		</div>
	}

	private baseUrlView(): Vnode<any, any> {
		return <div class="center">
			<label class="line">
				<small>{Lang.get("channels_public_base_url")}</small>
				<input type="text" placeholder="https://example.org/esmira/" value={this.baseUrl}
					oninput={(e: InputEvent) => { this.baseUrl = (e.target as HTMLInputElement).value }} />
			</label>
			<input type="button" value={Lang.get("save")} disabled={!this.baseUrl.trim()} onclick={this.saveBaseUrl.bind(this)} />
			<div><small>{Lang.get("channels_public_base_url_info")}</small></div>
		</div>
	}

	public getView(): Vnode<any, any> {
		return <div>
			{TitleRow(Lang.get("telegram"))}
			<div class="center spacingBottom"><small>{Lang.get("telegram_setup_info")}</small></div>
			{DashRow(
				DashElement(null, { content: this.statusView() }),
				DashElement(null, { content: this.tokenView() })
			)}
			{TitleRow(Lang.getWithColon("telegram_transport"))}
			{DashRow(DashElement("stretched", { content: this.transportView() }))}
			{TitleRow(Lang.getWithColon("channels_public_base_url"))}
			{DashRow(DashElement("stretched", { content: this.baseUrlView() }))}
		</div>
	}
}
