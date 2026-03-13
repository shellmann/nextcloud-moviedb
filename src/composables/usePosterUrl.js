import { computed } from 'vue'
import { TMDB_IMAGE_BASE_URL } from '../constants.js'

/**
 * Composable for generating poster URLs
 *
 * @param {import('vue').Ref<string|null>} posterPath - Reactive poster path
 * @param {string} size - Image size (w200, w300, w500, original)
 * @return {import('vue').ComputedRef<string|null>} Computed poster URL
 */
export function usePosterUrl(posterPath, size = 'w300') {
	return computed(() => {
		const path = posterPath.value ?? posterPath
		if (!path) return null
		if (typeof path === 'string' && path.startsWith('http')) return path
		const cleanPath = String(path).replace(/^\//, '')
		return `${TMDB_IMAGE_BASE_URL}/${size}/${cleanPath}`
	})
}

/**
 * Get poster URL (non-reactive version)
 *
 * @param {string|null} posterPath - Poster path from TMDB
 * @param {string} size - Image size (w200, w300, w500, original)
 * @return {string|null} Full poster URL or null
 */
export function getPosterUrl(posterPath, size = 'w300') {
	if (!posterPath) return null
	if (posterPath.startsWith('http')) return posterPath
	const cleanPath = posterPath.replace(/^\//, '')
	return `${TMDB_IMAGE_BASE_URL}/${size}/${cleanPath}`
}
