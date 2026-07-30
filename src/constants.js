import { translate as t, getLocale } from '@nextcloud/l10n'

/**
 * Shared constants for MovieDB app
 */

// TMDB API base URL for images
export const TMDB_IMAGE_BASE_URL = 'https://image.tmdb.org/t/p'

// Languages ordered by Nextcloud usage (top 5 first: en, de, fr, es, nl)
export const LANGUAGE_OPTIONS = [
	{ id: 'en', label: 'English', tmdbCode: 'en-US', flag: '🇬🇧' },
	{ id: 'de', label: 'Deutsch (German)', tmdbCode: 'de-DE', flag: '🇩🇪' },
	{ id: 'fr', label: 'Français (French)', tmdbCode: 'fr-FR', flag: '🇫🇷' },
	{ id: 'es', label: 'Español (Spanish)', tmdbCode: 'es-ES', flag: '🇪🇸' },
	{ id: 'nl', label: 'Nederlands (Dutch)', tmdbCode: 'nl-NL', flag: '🇳🇱' },
	{ id: 'it', label: 'Italiano (Italian)', tmdbCode: 'it-IT', flag: '🇮🇹' },
	{ id: 'pt', label: 'Português (Portuguese)', tmdbCode: 'pt-BR', flag: '🇧🇷' },
	{ id: 'ru', label: 'Русский (Russian)', tmdbCode: 'ru-RU', flag: '🇷🇺' },
	{ id: 'zh', label: '中文 (Chinese)', tmdbCode: 'zh-CN', flag: '🇨🇳' },
	{ id: 'ja', label: '日本語 (Japanese)', tmdbCode: 'ja-JP', flag: '🇯🇵' },
	{ id: 'ko', label: '한국어 (Korean)', tmdbCode: 'ko-KR', flag: '🇰🇷' },
	{ id: 'ar', label: 'العربية (Arabic)', tmdbCode: 'ar-SA', flag: '🇸🇦' },
	{ id: 'hi', label: 'हिन्दी (Hindi)', tmdbCode: 'hi-IN', flag: '🇮🇳' },
	{ id: 'bn', label: 'বাংলা (Bengali)', tmdbCode: 'bn-BD', flag: '🇧🇩' },
	{ id: 'pa', label: 'ਪੰਜਾਬੀ (Punjabi)', tmdbCode: 'pa-IN', flag: '🇮🇳' },
	{ id: 'id', label: 'Bahasa Indonesia', tmdbCode: 'id-ID', flag: '🇮🇩' },
]

/**
 * Get rating options with translated labels
 * Must be called as a function to ensure translations are loaded
 */
export function getRatingOptions() {
	return [
		{ id: 10, label: t('moviedb', '10 - Masterpiece') },
		{ id: 9, label: t('moviedb', '9 - Excellent') },
		{ id: 8, label: t('moviedb', '8 - Great') },
		{ id: 7, label: t('moviedb', '7 - Good') },
		{ id: 6, label: t('moviedb', '6 - Above Average') },
		{ id: 5, label: t('moviedb', '5 - Average') },
		{ id: 4, label: t('moviedb', '4 - Below Average') },
		{ id: 3, label: t('moviedb', '3 - Poor') },
		{ id: 2, label: t('moviedb', '2 - Bad') },
		{ id: 1, label: t('moviedb', '1 - Terrible') },
	]
}

/**
 * Get priority options with translated labels
 * Must be called as a function to ensure translations are loaded
 */
export function getPriorityOptions() {
	return [
		{ id: 0, label: t('moviedb', 'Normal'), color: null },
		{ id: 1, label: t('moviedb', 'High - Watch soon'), color: 'warning' },
		{ id: 2, label: t('moviedb', 'Very High - Must watch!'), color: 'error' },
	]
}

export const GENRE_OPTIONS = [
	{ id: 28, label: 'Action' },
	{ id: 12, label: 'Adventure' },
	{ id: 16, label: 'Animation' },
	{ id: 35, label: 'Comedy' },
	{ id: 80, label: 'Crime' },
	{ id: 99, label: 'Documentary' },
	{ id: 18, label: 'Drama' },
	{ id: 10751, label: 'Family' },
	{ id: 14, label: 'Fantasy' },
	{ id: 36, label: 'History' },
	{ id: 27, label: 'Horror' },
	{ id: 10402, label: 'Music' },
	{ id: 9648, label: 'Mystery' },
	{ id: 10749, label: 'Romance' },
	{ id: 878, label: 'Science Fiction' },
	{ id: 53, label: 'Thriller' },
	{ id: 10752, label: 'War' },
	{ id: 37, label: 'Western' },
]

// Keep static versions for backwards compatibility (used for lookups by id)
export const RATING_OPTIONS = [
	{ id: 10, label: '10 - Masterpiece' },
	{ id: 9, label: '9 - Excellent' },
	{ id: 8, label: '8 - Great' },
	{ id: 7, label: '7 - Good' },
	{ id: 6, label: '6 - Above Average' },
	{ id: 5, label: '5 - Average' },
	{ id: 4, label: '4 - Below Average' },
	{ id: 3, label: '3 - Poor' },
	{ id: 2, label: '2 - Bad' },
	{ id: 1, label: '1 - Terrible' },
]

export const PRIORITY_OPTIONS = [
	{ id: 0, label: 'Normal', color: null },
	{ id: 1, label: 'High', color: 'warning' },
	{ id: 2, label: 'Very High', color: 'error' },
]

/**
 * Get priority label by id
 *
 * @param {number} priorityId - Priority id (0, 1, 2)
 * @return {string} Priority label
 */
export function getPriorityLabel(priorityId) {
	const priority = PRIORITY_OPTIONS.find(p => p.id === priorityId)
	return priority?.label || 'Normal'
}

/**
 * Get priority color by id
 *
 * @param {number} priorityId - Priority id (0, 1, 2)
 * @return {string|null} Priority color class
 */
export function getPriorityColor(priorityId) {
	const priority = PRIORITY_OPTIONS.find(p => p.id === priorityId)
	return priority?.color || null
}

/**
 * Get language flag by code
 *
 * @param {string} langCode - Language code (e.g., 'en', 'de')
 * @return {string} Flag emoji or uppercase code if not found
 */
export function getLanguageFlag(langCode) {
	const lang = LANGUAGE_OPTIONS.find(l => l.id === langCode)
	return lang?.flag || langCode.toUpperCase()
}

/**
 * Get language name by code, localized to user's locale
 *
 * @param {string} langCode - Language code (e.g., 'en', 'de')
 * @return {string} Localized language name or uppercase code if not found
 */
export function getLanguageName(langCode) {
	if (!langCode) return ''
	try {
		const locale = getLocale().replace('_', '-')
		const displayNames = new Intl.DisplayNames([locale], { type: 'language' })
		return displayNames.of(langCode)
	} catch {
		// Fallback to static label if Intl.DisplayNames fails
		const lang = LANGUAGE_OPTIONS.find(l => l.id === langCode)
		return lang?.label || langCode.toUpperCase()
	}
}

/**
 * Get TMDB code by language code
 *
 * @param {string} langCode - Language code (e.g., 'en', 'de')
 * @return {string|null} TMDB language code (e.g., 'en-US') or null
 */
export function getTmdbCode(langCode) {
	const lang = LANGUAGE_OPTIONS.find(l => l.id === langCode)
	return lang?.tmdbCode || null
}

/**
 * Get language options formatted for TMDB API settings
 * Uses tmdbCode as id for direct API compatibility
 *
 * @return {Array} Language options with tmdbCode as id
 */
export function getTmdbLanguageOptions() {
	return LANGUAGE_OPTIONS.map(l => ({
		id: l.tmdbCode,
		label: l.label,
	}))
}
