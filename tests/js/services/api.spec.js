import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import api from '@/services/api.js'

describe('api library export / import', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('requests the export as a blob for the given library', () => {
		api.exportLibrary(7)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/export',
			{ params: { libraryId: 7 }, responseType: 'blob' },
		)
	})

	it('omits libraryId when none is given', () => {
		api.exportLibrary()
		expect(axios.get.mock.calls[0][1].params).toEqual({})
	})

	it('uploads the file as multipart field "file" with the library id', () => {
		const file = new File(['{}'], 'x.json', { type: 'application/json' })
		api.importLibrary(file, 7)

		const [url, body, options] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/moviedb/api/import')
		expect(body).toBeInstanceOf(FormData)
		expect(body.get('file')).toBeInstanceOf(File)
		expect(body.get('file').name).toBe('x.json')
		expect(options).toEqual({ params: { libraryId: 7 } })
	})
})

describe('api admin settings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('sends the instance-wide key with PUT', () => {
		api.updateAdminSettings({ tmdbApiKey: 'new-key' })
		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/admin/settings',
			{ tmdbApiKey: 'new-key' },
		)
	})
})

describe('api random watchlist pick', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('requests the random endpoint with the filters', () => {
		api.getRandomWatchlistItem({ genre: 28, exclude: 3, libraryId: 7 })
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/watchlist/random',
			{ params: { genre: 28, exclude: 3, libraryId: 7 } },
		)
	})
})

describe('api quiz', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('requests meta and a round for the library', () => {
		api.getQuizMeta(7)
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/moviedb/api/quiz/meta', { params: { libraryId: 7 } })

		api.getQuiz(12, 7)
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/moviedb/api/quiz', { params: { count: 12, libraryId: 7 } })
	})

	it('omits libraryId when none is given', () => {
		api.getQuiz(5)
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { count: 5 } })
	})

	it('posts a result with the library id in the body', () => {
		api.saveQuizResult({ questionCount: 2, score: 10, maxScore: 20, breakdown: {} }, 7)

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/quiz/results',
			{ questionCount: 2, score: 10, maxScore: 20, breakdown: {}, libraryId: 7 },
		)
	})

	it('reads and resets the history for the library', () => {
		api.getQuizResults(7)
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/moviedb/api/quiz/results', { params: { libraryId: 7 } })

		api.resetQuizResults(7)
		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/moviedb/api/quiz/results', { data: { libraryId: 7 } })
	})
})

describe('api continue watching', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads the row for a library', () => {
		api.getContinueWatching(10, 3)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/moviedb/api/series/continue', { params: { limit: 10, libraryId: 3 } })
	})

	it('sets and clears stop watching', () => {
		api.setSeriesStopped(7, true, 3)
		api.setSeriesStopped(7, false)
		expect(axios.post).toHaveBeenNthCalledWith(1, '/index.php/apps/moviedb/api/series/7/stopped', { stopped: true, libraryId: 3 })
		expect(axios.post).toHaveBeenNthCalledWith(2, '/index.php/apps/moviedb/api/series/7/stopped', { stopped: false })
	})
})
