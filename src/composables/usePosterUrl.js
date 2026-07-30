import { TMDB_IMAGE_BASE_URL } from '../constants.js'

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
