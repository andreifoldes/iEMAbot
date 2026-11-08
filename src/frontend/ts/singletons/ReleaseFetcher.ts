import {URL_RELEASES_LIST} from "../constants/urls"
import {compareSemVersion} from "../constants/methods"
import {PromiseCache} from "./PromiseCache"
import {Requests} from "./Requests"

export type LatestRelease = {
	version: string
	url: string
}

type GithubRelease = {
	tag_name: string
	prerelease: boolean
	html_url: string
	assets?: unknown[]
}

export const ReleaseFetcher = {
	/**
	 * Returns the newest stable release that is newer than `currentVersion` and installable,
	 * or `null` if there is none or GitHub cannot be reached. Never throws:
	 * a failed check must not disturb the admin page.
	 * @param needsDownload Only count releases with a download for the one-click update. A server that runs from a
	 * container image updates by replacing the image, so it wants to hear about every release.
	 */
	async loadLatestStable(currentVersion: string, needsDownload: boolean = true): Promise<LatestRelease | null> {
		let releases: GithubRelease[]
		try {
			releases = await PromiseCache.get("releases", async () => JSON.parse(await Requests.loadRaw(URL_RELEASES_LIST)))
		}
		catch (e) {
			return null
		}
		if(!Array.isArray(releases))
			return null

		let latest: LatestRelease | null = null
		for(const release of releases) {
			if(release.prerelease || (needsDownload && !release.assets?.length))
				continue
			if(!compareSemVersion(currentVersion, release.tag_name))
				continue
			if(latest === null || compareSemVersion(latest.version, release.tag_name))
				latest = {version: release.tag_name, url: release.html_url}
		}
		return latest
	}
}
