import { ref, watch } from 'vue'
import api from '../services/api.js'

/**
 * Loads the trailer and the streaming offers of a title ("where to watch").
 *
 * Both are optional extras, so every failure is silent: the page simply shows
 * nothing. A newer request (another title, another language) always wins over
 * an older one that is still in flight.
 *
 * @param {() => ({ type: 'movie'|'tv', tmdbId: number }|null)} source - The title to load, null for none
 * @param {() => string} language - The TMDB language, e.g. "de-DE"
 * @return {{ extras: import('vue').Ref<{trailer: object|null, watch: object|null}|null> }}
 */
export function useTmdbExtras(source, language) {
	const extras = ref(null)
	let latest = 0

	watch(
		() => {
			const title = source()
			return title?.tmdbId ? `${title.type}:${title.tmdbId}:${language()}` : ''
		},
		async (key) => {
			const request = ++latest
			extras.value = null
			if (!key) { return }
			const [type, tmdbId, lang] = key.split(':')
			try {
				const { data } = await api.getTmdbExtras(type, tmdbId, lang)
				if (request === latest) {
					extras.value = data
				}
			} catch {
				// Optional extras: no key, TMDB busy or offline all just mean "show nothing".
			}
		},
		{ immediate: true },
	)

	return { extras }
}
