/**
 * Shared formatting utilities for MovieDB app
 */

import { getLocale } from '@nextcloud/l10n'

/**
 * Format a date string for display using Nextcloud's locale
 *
 * @param {string} dateString - ISO date string
 * @param {object} options - Intl.DateTimeFormat options
 * @return {string} Formatted date or empty string if invalid
 */
export function formatDate(dateString, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
	if (!dateString) return ''
	const date = new Date(dateString)
	const locale = getLocale().replace('_', '-')
	return date.toLocaleDateString(locale, options)
}

/**
 * Format runtime in minutes to human-readable format
 *
 * @param {number} minutes - Runtime in minutes
 * @return {string} Formatted runtime (e.g., "2h 15m" or "45m")
 */
export function formatRuntime(minutes) {
	if (!minutes) return ''
	const hours = Math.floor(minutes / 60)
	const mins = minutes % 60
	return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`
}
