import { showInfo } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { useLibrariesStore } from '../stores/libraries.js'

/**
 * Handles `?library=<id>` on a route (links from notifications and calendar
 * events carry it, so a show of a shared library opens in the right library):
 * switches the active library if the user can access it, announces the switch
 * with a toast (so it is not silent for screen reader users), and returns the
 * same route without the query. Returns true when the route is fine as it is.
 *
 * @param {import('vue-router').RouteLocationNormalized} to - Target route
 * @return {Promise<true | import('vue-router').RouteLocationRaw>}
 */
export async function resolveLibraryQuery(to) {
	if (to.query.library === undefined) {
		return true
	}

	const libraries = useLibrariesStore()
	await libraries.whenReady()

	const id = Number.parseInt(String(to.query.library), 10)
	const library = Number.isInteger(id) ? libraries.libraries.find((l) => l.id === id) : undefined
	if (library && library.id !== libraries.activeLibraryId) {
		libraries.setActive(library.id)
		showInfo(t('moviedb', 'Switched to library "{name}"', { name: library.name }))
	}

	const query = { ...to.query }
	delete query.library
	return { path: to.path, query, hash: to.hash, replace: true }
}
