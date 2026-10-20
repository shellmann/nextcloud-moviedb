/**
 * Shared formatting utilities for MovieDB app
 */

import { getLocale } from '@nextcloud/l10n'

/**
 * Today's calendar date (YYYY-MM-DD) in the user's own time zone.
 * `new Date().toISOString()` would give the UTC date, which is the previous
 * day for a few hours after local midnight in time zones east of UTC.
 *
 * @param {Date} date - Moment to format (defaults to now)
 * @return {string}
 */
export function todayLocal(date = new Date()) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * Parse a plain calendar date ("2026-10-11") as that day in the local zone.
 * `new Date('2026-10-11')` is midnight UTC, which displays as the previous day
 * in time zones west of UTC.
 *
 * @param {string} value - Date string from the server
 * @return {Date}
 */
function parseCalendarDate(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value).trim())
	return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : new Date(value)
}

/**
 * Format a date string for display using Nextcloud's locale
 *
 * @param {string} dateString - ISO date string
 * @param {object} options - Intl.DateTimeFormat options
 * @return {string} Formatted date or empty string if invalid
 */
export function formatDate(dateString, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
	if (!dateString) { return '' }
	const date = parseCalendarDate(dateString)
	if (Number.isNaN(date.getTime())) { return '' }
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
	if (!minutes) { return '' }
	const hours = Math.floor(minutes / 60)
	const mins = minutes % 60
	return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`
}

/**
 * Short episode code like "S02E05".
 *
 * @param {{ seasonNumber: number, episodeNumber: number }} episode - Episode
 * @return {string}
 */
export function episodeCode(episode) {
	const s = String(episode.seasonNumber).padStart(2, '0')
	const e = String(episode.episodeNumber).padStart(2, '0')
	return `S${s}E${e}`
}

/**
 * Calendar days from one date to another (both "YYYY-MM-DD"), whole days, so
 * daylight saving changes never give 6.96 days.
 *
 * @param {string} from - Start date
 * @param {string} to - End date
 * @return {number} Negative if `to` is before `from`
 */
function daysBetween(from, to) {
	const a = parseCalendarDate(from)
	const b = parseCalendarDate(to)
	const utc = (d) => Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())
	return Math.round((utc(b) - utc(a)) / 86400000)
}

/**
 * "Today", "Tomorrow" or "In 3 days" for an air date up to six days away, in
 * the user's language; null for anything later, earlier or invalid, where the
 * plain date reads better.
 *
 * @param {string} dateString - Air date ("YYYY-MM-DD")
 * @param {string} today - Today ("YYYY-MM-DD", defaults to the local date)
 * @return {string|null}
 */
export function relativeAirDate(dateString, today = todayLocal()) {
	if (!dateString || Number.isNaN(parseCalendarDate(dateString).getTime())) { return null }
	const days = daysBetween(today, dateString)
	if (days < 0 || days > 6) { return null }
	const locale = getLocale().replace('_', '-')
	const text = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(days, 'day')
	return text.charAt(0).toLocaleUpperCase(locale) + text.slice(1)
}
