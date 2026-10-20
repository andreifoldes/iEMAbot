import {SectionContent} from "../site/SectionContent";
import m, {Vnode, VnodeDOM} from "mithril";
import {Lang} from "../singletons/Lang";
import {TitleRow} from "../components/TitleRow";
import {FILE_SAVE_ACCESS} from "../constants/urls";
import {Requests} from "../singletons/Requests";
import {StudiesDataType} from "../loader/StudyLoader";
import {DashRow} from "../components/DashRow";
import {DashElement} from "../components/DashElement";
import {SectionData} from "../site/SectionData";
import {Study} from "../data/study/Study";
import qrcode from "qrcode-generator";
import iemabotLogoSvg from "../../imgs/iemabot_logo.svg?raw";
import {generateProceduralCover, paletteForKey} from "../helpers/ProceduralCover";

// Toggled on <body> for the branded invite page to hide the redundant global site header (see style.css)
const HIDE_SITE_HEADER_CLASS = "hideSiteHeader"

const C = {
	primary:            "#00471c",
	primaryContainer:   "#006129",
	onPrimary:          "#ffffff",
	secondaryContainer: "#a5ede0",
	surface:            "#f7f9fc",
	surfaceContainer:   "#eceef1",
	surfaceLowest:      "#ffffff",
	onSurface:          "#191c1e",
	onSurfaceVariant:   "#3f4946",
} as const

export class Content extends SectionContent {
	private readonly isRedirected: boolean = false
	private readonly pwaUrl: string = ""
	private readonly inviteUrl: string = ""
	private readonly qrDataUrl: string = ""
	// Same code at a size meant for scanning from across a desk (opened from the "enlarge" lightbox)
	private readonly qrLargeDataUrl: string = ""
	// Shown to the participant to write down: the PWA asks for it after installing (iOS keeps no state)
	private readonly accessKey: string = ""
	private readonly invitedPid: string = ""
	private qrEnlarged: boolean = false
	private qrTrigger: HTMLElement | null = null
	private qrCloseButton: HTMLElement | null = null
	// Deterministic procedural cover, generated once when the study has no artwork of its own
	private readonly fallbackCoverUrl: string = ""
	private linkCopied: boolean = false

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			sectionData.siteData.studyLoader.loadAvailableStudies(sectionData.getDynamic("accessKey", "").get(), false, true)
		]
	}
	constructor(sectionData: SectionData, studies: StudiesDataType) {
		super(sectionData)
		const count = studies.getCount()

		let study
		if (count == 0)
			throw new Error(`Could not find study`)
		else if (count == 1) {
			study = studies.getFirst()
			if (study)
				this.sectionData.setStatic("id", study.id.get())
		}
		else
			study = this.getStudyOrNull()


		if (!study) {
			this.newSection("studies:studyOverview", this.sectionData.depth - 1)
			this.isRedirected = true
			return
		}
		else if (!study.publishedWeb.get()) {
			this.newSection("appInstall", this.sectionData.depth - 1)
			this.isRedirected = true
			return
		}

		if (study.webPushEnabled.get()) {
			const accessKey = sectionData.getDynamic("accessKey", "").get()
			const studyId = study.id.get()
			this.pwaUrl = `${window.location.origin}/pwa/?key=${encodeURIComponent(accessKey)}&id=${studyId}`
			// Canonical invite page URL (no hash) — used for the QR code and cross-device links
			// A personalised link may carry the participant ID (same names and precedence as the PWA)
			const query = new URLSearchParams(window.location.search)
			this.invitedPid = (query.get("pid") ?? query.get("uid") ?? query.get("user_id") ?? query.get("userId") ?? "").trim()
			this.inviteUrl = window.location.origin + window.location.pathname
				+ (this.invitedPid ? `?pid=${encodeURIComponent(this.invitedPid)}` : "")

			const qr = qrcode(0, 'M')
			qr.addData(this.inviteUrl)
			qr.make()
			this.qrDataUrl = qr.createDataURL(5)
			this.qrLargeDataUrl = qr.createDataURL(12)
			this.accessKey = accessKey

			// Studies without their own artwork get a deterministic procedural cover.
			// Seeding by study id keeps it identical across devices and reloads.
			if (!study.studyArtwork.get()) {
				try {
					this.fallbackCoverUrl = generateProceduralCover({
						seed: String(studyId),
						palette: paletteForKey(studyId),
						height: 220,
					})
				} catch (e) {
					console.warn("Could not generate procedural cover", e)
				}
			}

			this.applyIemabotBranding(study.title.get(), this.pwaUrl)
			document.body.classList.add(HIDE_SITE_HEADER_CLASS)
		}

		Requests.loadJson(FILE_SAVE_ACCESS, "post", `study_id=${study.id.get()}&page_name=${this.sectionData.depth ? "study" : "navigatedFromHome"}`)
	}

	public destroy(): void {
		document.removeEventListener("keydown", this.onQrKey)
		document.body.classList.remove(HIDE_SITE_HEADER_CLASS)
		super.destroy()
	}

	public title(): string {
		if (this.isRedirected)
			return Lang.get("study_description")
		const study = this.getStudyOrNull()
		if (study?.webPushEnabled.get())
			return study.title.get() || Lang.get("study_description")
		return Lang.get("study_description")
	}

	public getView(): Vnode<any, any> {
		if (this.isRedirected)
			return <div></div>

		const study = this.getStudyOrThrow()

		if (study.webPushEnabled.get())
			return this.renderStudyInvitePage(study)

		return <div>
			{study.studyOver.get() &&
				<div>
					{DashRow(DashElement("stretched", { highlight: true, small: true, content: <div>{Lang.get("study_over_message")}</div> }))}
					{study.postStudyNote.get() && <div class="horizontalPadding verticalPadding">{m.trust(study.postStudyNote.get())}</div>}
				</div>
			}

			{study.studyDescription.get() &&
				<div class="scrollBox spacingBottom">{m.trust(study.studyDescription.get())}</div>
			}

			{!study.studyOver.get() && <>
				{TitleRow(Lang.getWithColon("questionnaires"))}
				<div class="vertical">
					{study.questionnaires.get().map((questionnaire) =>
						questionnaire.isActive(Date.now(), Date.now()) &&
						<a class="verticalPadding" href={this.getUrl(`attend,qId:${questionnaire.internalId.get()}`)}>{questionnaire.getTitle()}</a>
					)}
				</div>
			</>}
		</div>
	}

	private renderStudyInvitePage(study: Study): Vnode<any, any> {
		const isOver = study.studyOver.get()
		const outerStyle = [
			"padding:24px 20px 40px",
			"max-width:680px",
			"margin:0 auto",
			`font-family:'Inter',ui-sans-serif,system-ui,sans-serif`,
		].join(";")

		return <div style={outerStyle}>
			{this.renderHeroCard(study)}
			{!isOver && this.renderHowToJoin()}
			{isOver && study.postStudyNote.get() &&
				<div style={`margin-top:16px;font-size:14px;line-height:1.7;color:${C.onSurfaceVariant};`}>
					{m.trust(study.postStudyNote.get())}
				</div>
			}
			{this.renderQrLightbox()}
		</div>
	}

	private renderHeroCard(study: Study): Vnode<any, any> {
		const isOver = study.studyOver.get()

		const cardStyle = [
			`background:${C.surfaceLowest}`,
			"border-radius:16px",
			"box-shadow:0 8px 24px rgba(25,28,30,0.06)",
			"overflow:hidden",
			"margin-bottom:20px",
		].join(";")

		const bodyStyle = [
			`background:${C.surface}`,
			"padding:32px 24px 28px",
			"text-align:center",
		].join(";")

		const badgeStyle = isOver
			? `background:${C.surfaceContainer};color:${C.onSurfaceVariant};border-radius:9999px;padding:4px 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;`
			: `background:rgba(0,71,28,0.10);color:${C.primary};border-radius:9999px;padding:4px 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;`

		const coverUrl = study.studyArtwork.get() || this.fallbackCoverUrl
		const isGeneratedCover = !study.studyArtwork.get()

		return <div style={cardStyle}>
			{coverUrl &&
				<div style="position:relative;width:100%;height:200px;overflow:hidden;">
					<img
						src={coverUrl}
						alt={study.title.get()}
						style="width:100%;height:100%;object-fit:cover;display:block;"
					/>
					{isGeneratedCover &&
						<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
							<div style={`width:92px;height:92px;border-radius:50%;background:${C.primary};border:4px solid rgba(255,255,255,0.92);box-shadow:0 6px 18px rgba(25,28,30,0.28);display:flex;align-items:center;justify-content:center;padding:13px;box-sizing:border-box;`}>
								{m.trust(iemabotLogoSvg)}
							</div>
						</div>
					}
				</div>
			}
			<div style={bodyStyle}>
				{!coverUrl &&
					<div style={`width:80px;height:80px;margin:0 auto 20px;border-radius:50%;background:${C.primary};display:flex;align-items:center;justify-content:center;padding:10px;box-sizing:border-box;`}>
						{m.trust(iemabotLogoSvg)}
					</div>
				}
				<div style="margin-bottom:16px;">
					<span style={badgeStyle}>
						<span style="font-size:7px;line-height:1;">⬤</span>
						{isOver ? "Study closed" : "Accepting participants"}
					</span>
				</div>
				{study.title.get() &&
					<div style={`font-size:1.9rem;font-weight:700;letter-spacing:-0.02em;color:${C.onSurface};margin-bottom:14px;line-height:1.2;`}>
						{study.title.get()}
					</div>
				}
				{study.studyDescription.get() &&
					<div style={`font-size:15px;line-height:1.7;color:${C.onSurfaceVariant};text-align:left;`}>
						{m.trust(study.studyDescription.get())}
					</div>
				}
			</div>
		</div>
	}

	private isDesktop(): boolean {
		return window.innerWidth >= 1024 || !('ontouchstart' in window)
	}

	private renderHowToJoin(): Vnode<any, any> {
		const compatible = 'serviceWorker' in navigator
			&& 'PushManager' in window
			&& !/\bFirefox\//i.test(navigator.userAgent)
		const desktop = this.isDesktop()

		if (!desktop && !compatible)
			return this.renderIncompatibleBrowserWarning()

		const cardStyle = [
			`background:${C.surfaceLowest}`,
			"border-radius:16px",
			"box-shadow:0 8px 24px rgba(25,28,30,0.06)",
			"overflow:hidden",
		].join(";")

		const headerStyle = [
			`background:linear-gradient(135deg,${C.primary} 0%,${C.primaryContainer} 100%)`,
			"position:relative",
			`color:${C.onPrimary}`,
			"padding:28px 20px 16px",
		].join(";")

		const tonalDividerStyle = `background:${C.secondaryContainer};height:4px;`

		const bodyStyle = [
			`background:${C.surface}`,
			"padding:24px 20px",
		].join(";")

		return <div style={cardStyle}>
			<div style={headerStyle}>
				<div style="position:absolute;inset:0;background:rgba(255,255,255,0.10);pointer-events:none;" />
				<div style="font-size:18px;font-weight:700;">How to join</div>
				<div style={`font-size:13px;line-height:1.6;color:rgba(255,255,255,0.88);margin-top:6px;`}>
					Follow the steps below to participate in this study.
				</div>
			</div>
			<div style={tonalDividerStyle} />
			<div style={bodyStyle}>
				{desktop ? this.renderDesktopJoin() : this.renderMobileJoin()}
			</div>
		</div>
	}

	private readonly onQrKey = (e: KeyboardEvent): void => {
		if (e.key === "Escape") {
			this.closeQr()
			m.redraw()
		} else if (e.key === "Tab") {
			// The close button is the only control in the dialog: keep focus on it
			e.preventDefault()
			this.qrCloseButton?.focus()
		}
	}

	private openQr(): void {
		this.qrEnlarged = true
		document.addEventListener("keydown", this.onQrKey)
	}

	private closeQr(): void {
		this.qrEnlarged = false
		document.removeEventListener("keydown", this.onQrKey)
		this.qrTrigger?.focus()
	}

	/** Enlarged QR code on a dimmed backdrop; closes on Esc, the X, or a click outside the code. */
	private renderQrLightbox(): Vnode<any, any> | null {
		if (!this.qrEnlarged)
			return null
		return <div
			role="dialog"
			aria-modal="true"
			aria-label="QR code, enlarged"
			onclick={() => this.closeQr()}
			style="position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;"
		>
			<div style="position:relative;" onclick={(e: Event) => e.stopPropagation()}>
				<button
					type="button"
					aria-label="Close enlarged QR code"
					oncreate={(v: VnodeDOM<any, any>) => {
						this.qrCloseButton = v.dom as HTMLElement
						this.qrCloseButton.focus()
					}}
					onclick={() => this.closeQr()}
					style="position:absolute;top:-14px;right:-14px;width:40px;height:40px;border-radius:50%;border:0;background:#fff;color:#191c1e;font-size:24px;line-height:1;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.35);"
				>×</button>
				<img
					alt="QR code — scan to join study"
					src={this.qrLargeDataUrl}
					style="display:block;width:min(88vw,480px);height:auto;background:#fff;border-radius:12px;image-rendering:pixelated;"
				/>
			</div>
		</div>
	}

	/**
	 * What the participant types into the installed app. iOS gives the installed app empty
	 * storage, so the invite code (and a personalised link's participant ID) don't carry over.
	 */
	private renderWriteDown(): Vnode<any, any> | null {
		const rows = [
			{label: "Invite code", value: this.accessKey},
			{label: "Participant ID", value: this.invitedPid},
		].filter(r => r.value)
		if (!rows.length)
			return null
		const valueStyle = `font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:22px;font-weight:700;letter-spacing:.06em;color:${C.onSurface};user-select:all;word-break:break-all;text-align:right;`
		return <div style={`width:100%;box-sizing:border-box;background:${C.surfaceContainer};border-radius:12px;padding:14px 16px;`}>
			<div style={`font-size:13px;line-height:1.5;color:${C.onSurfaceVariant};margin-bottom:8px;`}>
				{rows.length > 1 ? "Write these down — you might need them:" : "Write this down — you might need it:"}
			</div>
			{rows.map(r =>
				<div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;padding:3px 0;">
					<span style={`font-size:13px;color:${C.onSurfaceVariant};`}>{r.label}</span>
					<span style={valueStyle}>{r.value}</span>
				</div>
			)}
		</div>
	}

	/**
	 * Desktop view of "How to join": what to do, the code to note, the QR code centred as the
	 * main route (tap to enlarge), the secondary routes underneath, then a browser tip.
	 */
	private renderDesktopJoin(): Vnode<any, any> {
		// Studies restricted to smartphones in the editor shouldn't invite tablets
		const device = this.getStudyOrNull()?.webPhoneOnly.get() ? "smartphone" : "smartphone or tablet"

		const hintStyle = [
			`background:${C.surfaceContainer}`,
			"border-radius:12px",
			"padding:13px 15px",
			"display:flex",
			"align-items:flex-start",
			"gap:10px",
		].join(";")

		const sectionLabelStyle = [
			"width:100%",
			"font-size:11px",
			"font-weight:700",
			"letter-spacing:.08em",
			"text-transform:uppercase",
			`color:${C.onSurfaceVariant}`,
		].join(";")

		return <div style="display:flex;flex-direction:column;align-items:center;gap:20px;">
			<div style={`width:100%;font-size:15px;line-height:1.6;color:${C.onSurface};`}>
				This study runs on a phone. Open this link on your <strong>{device}</strong> to install the app and join the study.
			</div>

			{this.renderWriteDown()}

			<div style="display:flex;flex-direction:column;align-items:center;gap:10px;">
				<button
					type="button"
					aria-label="Enlarge QR code"
					aria-haspopup="dialog"
					oncreate={(v: VnodeDOM<any, any>) => { this.qrTrigger = v.dom as HTMLElement }}
					onclick={() => this.openQr()}
					style="background:#fff;border:0;border-radius:12px;padding:6px;cursor:zoom-in;line-height:0;box-shadow:0 2px 10px rgba(25,28,30,.12);"
				>
					<img alt="" src={this.qrDataUrl} style="display:block;border-radius:8px;image-rendering:pixelated;" />
				</button>
				<div style={`font-size:13px;color:${C.onSurfaceVariant};text-align:center;line-height:1.5;`}>
					Scan with your phone camera, or tap the code to enlarge it.
				</div>
			</div>

			<div style="width:100%;display:flex;flex-direction:column;gap:12px;">
				<div style={sectionLabelStyle}>Other ways to open it</div>
				<div style={hintStyle}>
					<span style="font-size:15px;flex-shrink:0;">📱</span>
					<div style={`font-size:13px;color:${C.onSurfaceVariant};line-height:1.65;`}>
						<strong style={`color:${C.onSurface};`}>On your phone?</strong>{" "}
						<a href={this.inviteUrl} style={`color:${C.primary};font-weight:600;text-decoration:none;`}>
							Tap to open directly
						</a>
					</div>
				</div>
				{this.renderCopyLink()}
			</div>

			<div style={`font-size:12px;color:${C.onSurfaceVariant};line-height:1.55;text-align:center;`}>
				Tip: open it in <strong>Safari</strong> or <strong>Chrome</strong> (iPhone), or <strong>Chrome</strong> (Android).
			</div>
		</div>
	}

	private applyIemabotBranding(studyTitle: string, pwaUrl: string): void {
		document.title = studyTitle || 'iEMAbot'

		// Browser tab favicon — SVG data URL works in all modern browsers
		const faviconUrl = `data:image/svg+xml,${encodeURIComponent(iemabotLogoSvg)}`
		document.querySelectorAll<HTMLLinkElement>('link[rel~="icon"]').forEach(l => {
			l.href = faviconUrl
			l.type = 'image/svg+xml'
			l.removeAttribute('sizes')
		})

		// Web manifest — dynamic PHP endpoint so start_url points to the PWA
		const manifestLink = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')
		if (manifestLink) manifestLink.href = `studyManifest.php?start=${encodeURIComponent(pwaUrl)}`

		// Apple touch icon — swap all sizes to iEMAbot PNG
		document.querySelectorAll<HTMLLinkElement>('link[rel~="apple-touch-icon"]').forEach(l => {
			l.href = 'frontend/assets/iemabot/apple-touch-icon.png'
		})

		// App name meta tags
		const appNameMeta = document.querySelector<HTMLMetaElement>('meta[name="application-name"]')
		if (appNameMeta) appNameMeta.content = 'iEMAbot'
		const appTitleMeta = document.querySelector<HTMLMetaElement>('meta[name="apple-mobile-web-app-title"]')
		if (appTitleMeta) appTitleMeta.content = 'iEMAbot'
	}

	private renderCopyLink(): Vnode<any, any> {
		const wrapStyle = [
			`background:${C.surfaceContainer}`,
			"border-radius:12px",
			"padding:13px 15px",
			"display:flex",
			"align-items:flex-start",
			"gap:10px",
		].join(";")

		const btnStyle = [
			`background:${this.linkCopied ? C.primary : C.surfaceLowest}`,
			`color:${this.linkCopied ? C.onPrimary : C.primary}`,
			`border:1.5px solid ${C.primary}`,
			"border-radius:8px",
			"padding:6px 14px",
			"font-size:13px",
			"font-weight:600",
			"cursor:pointer",
			"white-space:nowrap",
			"flex-shrink:0",
			"line-height:1.4",
			`font-family:'Inter',ui-sans-serif,system-ui,sans-serif`,
		].join(";")

		return <div style={wrapStyle}>
			<span style="font-size:15px;flex-shrink:0;margin-top:1px;">🔗</span>
			<div style="flex:1;min-width:0;">
				<div style={`font-size:13px;color:${C.onSurface};line-height:1.55;margin-bottom:8px;`}>
					<strong>Copy the link</strong> to send it to yourself
				</div>
				<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
					<button
						style={btnStyle}
						onclick={() => {
							navigator.clipboard.writeText(this.inviteUrl).then(() => {
								this.linkCopied = true
								m.redraw()
								setTimeout(() => { this.linkCopied = false; m.redraw() }, 2000)
							})
						}}
					>
						{this.linkCopied ? "✓ Copied!" : "Copy link"}
					</button>
				</div>
				<div style={`font-size:12px;color:${C.onSurfaceVariant};margin-top:8px;line-height:1.55;`}>
					Tip: email or message the link to yourself, then open it on your phone.
				</div>
			</div>
		</div>
	}

	private renderMobileJoin(): Vnode<any, any> {
		const isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent)
		const isAndroid = /Android/i.test(navigator.userAgent)
		const isAndroidChrome = isAndroid
			&& /Chrome\//.test(navigator.userAgent)
			&& !/Chromium\/|EdgA\/|OPR\/|SamsungBrowser\//i.test(navigator.userAgent)

		const btnStyle = [
			"display:flex",
			"align-items:center",
			"justify-content:center",
			"gap:10px",
			`background:linear-gradient(135deg,${C.primary} 0%,${C.primaryContainer} 100%)`,
			`color:${C.onPrimary}`,
			"text-decoration:none",
			"padding:15px 24px",
			"border-radius:9999px",
			"font-size:15px",
			"font-weight:700",
			"letter-spacing:.01em",
			`font-family:'Inter',ui-sans-serif,system-ui,sans-serif`,
			"margin-bottom:20px",
		].join(";")

		const stepsCard = [
			`background:${C.surfaceContainer}`,
			"border-radius:12px",
			"padding:16px",
			"display:flex",
			"flex-direction:column",
			"gap:12px",
		].join(";")

		const stepRow = "display:flex;align-items:flex-start;gap:12px;"

		const stepBadge = [
			`background:${C.primary}`,
			`color:${C.onPrimary}`,
			"border-radius:9999px",
			"width:22px",
			"height:22px",
			"flex-shrink:0",
			"display:flex",
			"align-items:center",
			"justify-content:center",
			"font-size:11px",
			"font-weight:700",
			"margin-top:1px",
		].join(";")

		const stepText = `font-size:13px;color:${C.onSurface};line-height:1.55;`

		if (isIOS) {
			return <div>
				<div style={`font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:${C.onSurfaceVariant};margin-bottom:10px;`}>
					iPhone &amp; iPad
				</div>
				<div style={stepsCard}>
					<div style={stepRow}>
						<span style={stepBadge}>1</span>
						<span style={stepText}>Open the link in <strong>Safari</strong></span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>2</span>
						<span style={stepText}>Tap the <strong>⋯ More</strong> button at the bottom-right of the screen</span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>3</span>
						<span style={stepText}>Tap <strong>Share</strong></span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>4</span>
						<span style={stepText}>Scroll to the end and tap <strong>Add to Home Screen</strong></span>
					</div>
				</div>
			</div>
		}

		if (isAndroid) {
			const doneStepBadge = [
				`background:${C.surfaceContainer}`,
				`color:${C.onSurfaceVariant}`,
				"border-radius:9999px",
				"width:22px",
				"height:22px",
				"flex-shrink:0",
				"display:flex",
				"align-items:center",
				"justify-content:center",
				"font-size:11px",
				"font-weight:700",
				"margin-top:1px",
			].join(";")
			const doneStepText = `font-size:13px;color:${C.onSurfaceVariant};line-height:1.55;`
			return <div>
				<div style={`font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:${C.onSurfaceVariant};margin-bottom:10px;`}>
					Android
				</div>
				<div style={stepsCard}>
					<div style={stepRow}>
						<span style={isAndroidChrome ? doneStepBadge : stepBadge}>{isAndroidChrome ? "✓" : "1"}</span>
						<span style={isAndroidChrome ? doneStepText : stepText}>
							{isAndroidChrome
								? <>Open the link in <strong style={`color:${C.onSurfaceVariant};`}>Chrome</strong></>
								: <>Open <a href={this.inviteUrl} style={`color:${C.primary};font-weight:600;`}>this link</a> in <strong>Chrome</strong></>
							}
						</span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>2</span>
						<span style={stepText}>Open the study app: <a href={this.pwaUrl} style={`color:${C.primary};font-weight:600;`}>tap here</a></span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>3</span>
						<span style={stepText}>Tap the <strong>⋮</strong> menu (top-right or bottom-right)</span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>4</span>
						<span style={stepText}>Tap <strong>Install app</strong> or <strong>Add to Home Screen</strong></span>
					</div>
					<div style={stepRow}>
						<span style={stepBadge}>5</span>
						<span style={stepText}>Once installed, find the app on your home screen and open it</span>
					</div>
				</div>
			</div>
		}

		// Generic fallback for other platforms (e.g. desktop OS with touch)
		return <div>
			<a href={this.pwaUrl} style={btnStyle}>
				<span style="font-size:20px;">📲</span>
				Open &amp; install the study app
			</a>
			<div style={stepsCard}>
				<div style={stepRow}>
					<span style={stepBadge}>1</span>
					<span style={stepText}>Open the link in <strong>Chrome</strong>, <strong>Edge</strong>, or <strong>Safari</strong> (iOS 16.4+)</span>
				</div>
				<div style={stepRow}>
					<span style={stepBadge}>2</span>
					<span style={stepText}>Use the browser menu to tap <strong>Install app</strong> or <strong>Add to Home Screen</strong></span>
				</div>
			</div>
		</div>
	}

	private renderIncompatibleBrowserWarning(): Vnode<any, any> {
		const card: string = [
			`background:${C.surface}`,
			"border-radius:16px",
			`box-shadow:0 8px 24px rgba(25,28,30,0.06)`,
			"overflow:hidden",
			"margin:16px 0",
			`font-family:'Inter',ui-sans-serif,system-ui,sans-serif`,
		].join(";")
		const header: string = [
			`background:${C.surfaceContainer}`,
			"padding:22px 20px 16px",
			"display:flex",
			"align-items:flex-start",
			"gap:14px",
		].join(";")
		const body: string = [
			`background:${C.surface}`,
			"padding:18px 20px 22px",
		].join(";")
		const browserList: string = [
			`background:${C.surfaceContainer}`,
			"border-radius:12px",
			"padding:13px 16px",
			"margin-top:12px",
			"display:flex",
			"flex-direction:column",
			"gap:6px",
		].join(";")
		const browserItem: string = [
			`color:${C.onSurface}`,
			"font-size:13px",
			"display:flex",
			"align-items:center",
			"gap:8px",
			"line-height:1.5",
		].join(";")
		return <div style={card}>
			<div style={header}>
				<span style="font-size:28px;flex-shrink:0;">⚠️</span>
				<div>
					<div style={`font-weight:700;font-size:16px;color:${C.onSurface};margin-bottom:6px;`}>
						Browser not compatible
					</div>
					<div style={`font-size:13px;color:${C.onSurfaceVariant};line-height:1.6;`}>
						You do not appear to be using a web-app compatible browser.
						This study requires push notifications, which need a supported browser.
					</div>
				</div>
			</div>
			<div style={body}>
				<div style={`font-size:13px;font-weight:600;color:${C.onSurface};`}>
					Please switch to one of the following browsers:
				</div>
				<div style={browserList}>
					<div style={browserItem}><span>🌐</span><span><strong>Chrome</strong> — Android or desktop</span></div>
					<div style={browserItem}><span>🌐</span><span><strong>Microsoft Edge</strong> — Android or desktop</span></div>
					<div style={browserItem}><span>🌐</span><span><strong>Samsung Internet</strong> — Android</span></div>
					<div style={browserItem}><span>🍎</span><span><strong>Safari</strong> — iOS 16.4 or later</span></div>
				</div>
				<div style={`font-size:11px;color:${C.onSurfaceVariant};margin-top:12px;line-height:1.6;`}>
					Then return to this link: <strong style={`color:${C.primary};word-break:break-all;`}>{window.location.href}</strong>
				</div>
			</div>
		</div>
	}
}
