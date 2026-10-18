import { translate as t } from '@nextcloud/l10n'

/**
 * Highlights shown in the "What's new" dialog, newest first. Keep it to two
 * to four points a user notices, in plain words; the CHANGELOG has the rest.
 * Patch releases get an entry too when they fix something a user notices
 * (one to four points); purely internal fixes need none.
 * Every text must be a literal t() call (the translation audit scans for them).
 *
 * @return {Array<{ version: string, items: string[] }>}
 */
export function releaseNotes() {
	return [
		{
			version: '1.9.1',
			items: [
				t('moviedb', 'The language you watched in is back: as a flag on movie and TV show cards, and with its name in the watch history of a movie and on the TV show page.'),
			],
		},
		{
			version: '1.9.0',
			items: [
				t('moviedb', 'Continue watching: the dashboard shows the TV shows you are in the middle of, with the next episode, which you can mark as watched right there.'),
				t('moviedb', 'Stop watching a show you gave up on. It leaves "Continue watching" but keeps its episodes and history, and you can resume it at any time.'),
				t('moviedb', 'Every TV show card shows your episode progress, and the TV show list can be filtered by in progress, finished and stopped.'),
			],
		},
	]
}

/**
 * Compares two versions like "1.9.0" or "1.6.0-beta.1" by their numeric parts.
 * A pre-release counts as its release (good enough for release notes).
 *
 * @param {string} a - Version
 * @param {string} b - Version
 * @return {number} negative if a < b, 0 if equal, positive if a > b
 */
export function compareVersions(a, b) {
	const parts = (v) => String(v).split('-')[0].split('.').map((n) => parseInt(n, 10) || 0)
	const pa = parts(a)
	const pb = parts(b)
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		const diff = (pa[i] ?? 0) - (pb[i] ?? 0)
		if (diff !== 0) {
			return diff
		}
	}
	return 0
}

/**
 * Notes of the versions after `lastSeen` up to and including `current`,
 * newest first. Several versions when the user skipped some updates.
 *
 * @param {string} lastSeen - Version the user dismissed the dialog for
 * @param {string} current - Installed version
 * @param {Array} notes - Release notes (defaults to releaseNotes())
 * @return {Array<{ version: string, items: string[] }>}
 */
export function unseenNotes(lastSeen, current, notes = releaseNotes()) {
	if (!lastSeen || !current) {
		return []
	}
	return notes
		.filter((n) => compareVersions(n.version, lastSeen) > 0 && compareVersions(n.version, current) <= 0)
		.sort((a, b) => compareVersions(b.version, a.version))
}
