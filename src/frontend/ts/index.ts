import {CompatibilityCheck} from "./site/CompatibilityCheck";
import {Lang} from "./singletons/Lang";
import {Site} from "./site/Site";
import {ServerInfo} from "./constants/ServerInfo";

declare const PACKAGE_VERSION: string

let site: Site

export function init(startHash: string, serverName: string, serverVersion: number, serverAccessKey: string, langCode: string, type: string, webOnlyMode = false, telegramConfigured = false, telegramBotUsername = ""): void {
	ServerInfo.webOnlyMode = webOnlyMode
	ServerInfo.telegramConfigured = telegramConfigured
	ServerInfo.telegramBotUsername = telegramBotUsername
	if(type)
		document.body.classList.add(type)
	if(process.env.NODE_ENV !== 'production')
		document.body.classList.add("localhost")
	
	const check = new CompatibilityCheck()
	if(!check.isCompatible()) { //detect IE, very old browsers or IE. It also detects IE
		console.warn("You shall not pass!")
		check.toggleUrl()
		return
	}
	
	const pluginsLangs: Record<string, Record<string, string>> = JSON.parse(document.getElementById("pluginLangData")?.innerHTML || "{}")
	Lang.init(langCode, PACKAGE_VERSION, pluginsLangs)
	
	site = new Site(serverName, startHash, serverVersion, PACKAGE_VERSION, serverAccessKey)
	
	document.body.classList.add("isInit")
	Lang.awaitPromise().then(() => {
		document.getElementById("legalLink")!.innerText = Lang.get("impressum")
	})
}

/**
 * The server passes the arguments of init() in the data-esmira-init attribute of <body> (a JSON array, see index.php)
 * instead of an inline onload handler, so a Content-Security-Policy can forbid inline script.
 * Runs once when the page has loaded, like the former onload handler.
 */
function initFromPage(): void {
	const raw = document.body.dataset.esmiraInit
	if(!raw)
		return
	const args = JSON.parse(raw) as Parameters<typeof init>
	init(...args)
}
if(document.readyState === "complete")
	initFromPage()
else
	window.addEventListener("load", initFromPage)
