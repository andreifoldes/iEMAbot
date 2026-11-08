import DOMPurify from "dompurify"

/**
 * Study content (question text, description, post-study note, chart descriptions, publication titles, ...) is HTML
 * written in the rich-text editor by researchers, saved in the study config and shown to other accounts. It must never be
 * able to run script in someone else's session, so it is passed through this allowlist before it is rendered.
 *
 * Allowed: what the editor (RichText.tsx) can produce plus a few harmless text containers. Everything else
 * (script, style, iframe, form controls, svg, event handlers, classes, ids, javascript: URLs, ...) is dropped.
 */
const ALLOWED_TAGS = [
	"p", "br", "div", "span", "hr",
	"b", "strong", "i", "em", "u", "s", "strike", "del", "mark", "small", "sub", "sup", "code", "pre", "blockquote",
	"h1", "h2", "h3", "h4", "h5", "h6",
	"ul", "ol", "li",
	"a", "img"
]
const ALLOWED_ATTR = ["href", "target", "rel", "src", "alt", "title", "width", "height", "style"]
const TEXT_ALIGN_ONLY = /^\s*text-align\s*:\s*(left|right|center|justify)\s*;?\s*$/i

let purifier: ReturnType<typeof DOMPurify> | null = null

function getPurifier(): ReturnType<typeof DOMPurify> | null {
	if(purifier)
		return purifier
	if(typeof window === "undefined")
		return null
	const instance = DOMPurify(window)
	if(!instance.isSupported)
		return null
	
	instance.addHook("afterSanitizeAttributes", (node: Element) => {
		if(node.hasAttribute("style") && !TEXT_ALIGN_ONLY.test(node.getAttribute("style") ?? ""))
			node.removeAttribute("style")
		
		if(node.tagName === "A") {
			if(node.getAttribute("target") !== "_blank")
				node.removeAttribute("target")
			if(node.hasAttribute("href"))
				node.setAttribute("rel", "noopener noreferrer")
		}
	})
	purifier = instance
	return instance
}

/**
 * Returns HTML that is safe to render with innerHTML / m.trust().
 * Without a DOM (never in a browser) nothing is returned rather than something unsafe.
 */
export function sanitizeHtml(html: string | null | undefined): string {
	if(!html)
		return ""
	const instance = getPurifier()
	if(!instance)
		return ""
	return instance.sanitize(String(html), {
		ALLOWED_TAGS: ALLOWED_TAGS,
		ALLOWED_ATTR: ALLOWED_ATTR,
		ALLOW_DATA_ATTR: false,
		ALLOW_ARIA_ATTR: false,
		KEEP_CONTENT: true,
		RETURN_TRUSTED_TYPE: false
	}) as string
}

/**
 * For URLs from data that is not trusted (publication links, participant user agents, ...) used as href:
 * only http(s), mailto and relative URLs are returned, everything else (javascript:, data:, ...) becomes "".
 */
export function safeUrl(url: string | null | undefined): string {
	const trimmed = (url ?? "").trim()
	if(!trimmed)
		return ""
	// the browser ignores tab/newline/control characters inside a scheme ("java\tscript:"), so remove them before checking
	const normalized = trimmed.replace(/[\u0000- \u007f-\u009f]/g, "")
	const scheme = /^([a-z][a-z0-9+.\-]*):/i.exec(normalized)
	if(!scheme)
		return trimmed
	return ["http", "https", "mailto"].includes(scheme[1].toLowerCase()) ? trimmed : ""
}
