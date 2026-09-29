import { describe, it, expect } from 'vitest'
import { filenameFromDisposition, LibraryFileError, summarizeExport } from '@/utils/libraryFile.js'

describe('libraryFile', () => {
	describe('summarizeExport', () => {
		it('counts movies, series, episodes and watchlist items', () => {
			const summary = summarizeExport({
				app: 'moviedb',
				formatVersion: 1,
				movies: [{}, {}, {}],
				series: [{ episodes: [{}, {}] }, { episodes: [{}] }, {}],
				watchlist: [{}],
			})
			expect(summary).toEqual({ movies: 3, series: 3, episodes: 3, watchlist: 1 })
		})

		it('treats missing sections as empty', () => {
			expect(summarizeExport({ app: 'moviedb', formatVersion: 1 }))
				.toEqual({ movies: 0, series: 0, episodes: 0, watchlist: 0 })
		})

		it.each([
			['null', null],
			['array', []],
			['other app', { app: 'other', formatVersion: 1 }],
			['missing version', { app: 'moviedb' }],
			['string version', { app: 'moviedb', formatVersion: '1' }],
		])('rejects %s as not a MovieDB file', (_name, data) => {
			expect(() => summarizeExport(data)).toThrow(LibraryFileError)
			try {
				summarizeExport(data)
			} catch (error) {
				expect(error.code).toBe('notMoviedb')
			}
		})

		it('rejects a newer format version', () => {
			try {
				summarizeExport({ app: 'moviedb', formatVersion: 2 })
				expect.unreachable()
			} catch (error) {
				expect(error).toBeInstanceOf(LibraryFileError)
				expect(error.code).toBe('newerVersion')
			}
		})
	})

	describe('filenameFromDisposition', () => {
		it('reads a quoted filename', () => {
			expect(filenameFromDisposition('attachment; filename="moviedb-Personal-2026-09-29.json"'))
				.toBe('moviedb-Personal-2026-09-29.json')
		})

		it('falls back when the header is missing', () => {
			expect(filenameFromDisposition(undefined)).toBe('moviedb-export.json')
			expect(filenameFromDisposition('', 'x.json')).toBe('x.json')
		})
	})
})
