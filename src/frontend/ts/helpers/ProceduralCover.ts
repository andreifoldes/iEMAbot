// @ts-ignore — trianglify has no bundled type declarations
import trianglify from "trianglify";

/**
 * Low-poly procedural cover art via trianglify.
 * Shared by the study editor ({@link ../sections/studyDesc}, admin-generated artwork)
 * and the participant invite page ({@link ../sections/studyOverview}, auto fallback cover).
 */

// Design-system palettes from DESIGN.md (heritage greens + tonal neutrals)
export const TRIANGLIFY_PALETTES: Record<string, string[]> = {
	"Emerald (default)": ["#f7f9fc", "#eceef1", "#a5ede0", "#006129", "#00471c"],
	"Teal Mist":         ["#f7f9fc", "#a5ede0", "#3f9e90", "#226e63", "#0d3d35"],
	"Forest Night":      ["#191c1e", "#0d3d35", "#00471c", "#006129", "#a5ede0"],
	"Stone & Sage":      ["#eceef1", "#c8d0cc", "#7aab8a", "#3f6e52", "#1a3628"],
}

export interface ProceduralCoverOptions {
	palette?: string[]
	width?: number
	height?: number
	cellSize?: number
	variance?: number
	/** Stable seed for a deterministic, reproducible pattern. Omit for a random one. */
	seed?: string
}

/**
 * Generate a low-poly cover image as an SVG (base64) data URL.
 * Pass a stable `seed` (e.g. the study id) when the result must be reproducible —
 * e.g. an invite page opened across devices should always show the same cover.
 */
export function generateProceduralCover(options: ProceduralCoverOptions = {}): string {
	const pattern = trianglify({
		width: options.width ?? 800,
		height: options.height ?? 200,
		cellSize: options.cellSize ?? 70,
		variance: options.variance ?? 0.8,
		xColors: options.palette ?? TRIANGLIFY_PALETTES["Emerald (default)"],
		yColors: "match",
		seed: options.seed ?? Math.random().toString(36).slice(2),
	})
	// toSVG returns an SVGElement; serialise it to a base64 data URL via browser APIs
	const svgNode: SVGElement = pattern.toSVG(document.createElementNS("http://www.w3.org/2000/svg", "svg"))
	svgNode.setAttribute("xmlns", "http://www.w3.org/2000/svg")
	const svgString = new XMLSerializer().serializeToString(svgNode)
	const b64 = btoa(unescape(encodeURIComponent(svgString)))
	return `data:image/svg+xml;base64,${b64}`
}

/**
 * Pick one of the built-in palettes deterministically from a numeric key (e.g. study id),
 * so different studies get on-brand variety while any single study stays stable.
 */
export function paletteForKey(key: number): string[] {
	const names = Object.keys(TRIANGLIFY_PALETTES)
	const index = ((key % names.length) + names.length) % names.length
	return TRIANGLIFY_PALETTES[names[index]]
}
