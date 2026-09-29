/**
 * Helpers for the library export/import file (JSON, format version 1).
 * The server validates everything again; these checks only power the
 * confirmation dialog and early, readable errors.
 */

export const SUPPORTED_FORMAT_VERSION = 1

/**
 * Error thrown for files that cannot be imported. `code` is one of
 * 'notMoviedb' | 'newerVersion'.
 */
export class LibraryFileError extends Error {
	constructor(code) {
		super(code)
		this.code = code
	}
}

const count = (value) => (Array.isArray(value) ? value.length : 0)

/**
 * Summarize a parsed export file for the confirmation dialog.
 *
 * @param {object} data Parsed JSON
 * @return {{movies: number, series: number, episodes: number, watchlist: number, libraryName: string | null, exportedAt: Date | null}}
 * @throws {LibraryFileError} when the file is not a usable MovieDB export
 */
export function summarizeExport(data) {
	if (!data || typeof data !== 'object' || data.app !== 'moviedb' || !Number.isInteger(data.formatVersion) || data.formatVersion < 1) {
		throw new LibraryFileError('notMoviedb')
	}
	if (data.formatVersion > SUPPORTED_FORMAT_VERSION) {
		throw new LibraryFileError('newerVersion')
	}

	const series = Array.isArray(data.series) ? data.series : []
	return {
		movies: count(data.movies),
		series: series.length,
		episodes: series.reduce((sum, s) => sum + count(s?.episodes), 0),
		watchlist: count(data.watchlist),
		libraryName: typeof data.library?.name === 'string' && data.library.name.trim() !== ''
			? data.library.name.trim().slice(0, 128)
			: null,
		exportedAt: parseDate(data.exportedAt),
	}
}

/**
 * Parse an ISO date string, or null when missing or malformed.
 *
 * @param {unknown} value Raw value from the file
 * @return {Date | null}
 */
function parseDate(value) {
	if (typeof value !== 'string') { return null }
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * Filename from a Content-Disposition header, or a fallback.
 *
 * @param {string | undefined} header Content-Disposition header value
 * @param {string} fallback Name to use when the header has none
 * @return {string}
 */
export function filenameFromDisposition(header, fallback = 'moviedb-export.json') {
	const match = /filename="?([^";]+)"?/i.exec(header || '')
	return match ? match[1] : fallback
}

/**
 * Trigger a browser download for a Blob.
 *
 * @param {Blob} blob File content
 * @param {string} filename Suggested file name
 */
export function downloadBlob(blob, filename) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}
