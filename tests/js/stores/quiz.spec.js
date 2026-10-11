import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useQuizStore } from '@/stores/quiz.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getQuizMeta: vi.fn(),
		getQuiz: vi.fn(),
		getQuizResults: vi.fn(),
		saveQuizResult: vi.fn(),
		resetQuizResults: vi.fn(),
	},
}))

import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '@/services/api.js'

function questions() {
	return [
		{ type: 'release_year', mode: 'year', answer: 2000, min: 1990, max: 2010, subject: { title: 'A' } },
		{ type: 'director', mode: 'choice', answer: '1', options: [{ key: '0', label: 'X' }, { key: '1', label: 'Y' }], subject: { title: 'B' } },
	]
}

describe('Quiz store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useQuizStore()
		vi.clearAllMocks()
		api.getQuizResults.mockResolvedValue({ data: { rounds: [], best: null, accuracy: {} } })
		api.saveQuizResult.mockResolvedValue({ data: {} })
	})

	it('starts a round and shows the first question', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })

		expect(await store.start(2)).toBe(true)

		expect(api.getQuiz).toHaveBeenCalledWith(2, undefined)
		expect(store.phase).toBe('playing')
		expect(store.currentQuestion.type).toBe('release_year')
	})

	it('passes the active library to the API', async () => {
		useLibrariesStore().activeLibraryId = 7
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })

		await store.start(2)

		expect(api.getQuiz).toHaveBeenCalledWith(2, 7)
	})

	it('does not start when the server returns no questions', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: [] } })

		expect(await store.start(5)).toBe(false)
		expect(store.phase).toBe('start')
		expect(showError).toHaveBeenCalled()
	})

	it('shows an error and stays on the start screen when the request fails', async () => {
		api.getQuiz.mockRejectedValue(new Error('boom'))

		expect(await store.start(5)).toBe(false)
		expect(store.phase).toBe('start')
		expect(showError).toHaveBeenCalled()
		expect(store.loading).toBe(false)
	})

	it('scores an answer once and ignores a second one', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		await store.start(2)

		expect(store.answer(2001)).toBe(7)
		expect(store.answer(2000)).toBeNull()
		expect(store.answers).toHaveLength(1)
	})

	it('only advances after the current question was answered', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		await store.start(2)

		await store.next()
		expect(store.index).toBe(0)

		store.answer(2000)
		await store.next()
		expect(store.index).toBe(1)
	})

	it('finishes after the last answer, saves the round and reloads the history', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		await store.start(2)
		store.answer(2000) // 10
		await store.next()
		store.answer('0') // wrong, 0
		await store.next()

		expect(store.phase).toBe('finished')
		expect(store.lastResult).toMatchObject({ score: 10, maxScore: 20, percent: 50, questionCount: 2 })
		expect(api.saveQuizResult).toHaveBeenCalledWith({
			questionCount: 2,
			score: 10,
			maxScore: 20,
			breakdown: {
				release_year: { points: 10, max: 10 },
				director: { points: 0, max: 10 },
			},
		}, undefined)
		expect(api.getQuizResults).toHaveBeenCalled()
	})

	it('flags a new personal best only when it beats an existing one', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		store.results.best = { percent: 40 }
		await store.start(2)
		store.answer(2000)
		await store.next()
		store.answer('1')
		await store.next()

		expect(store.isNewBest).toBe(true)
	})

	it('does not call a first round a personal best', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		await store.start(2)
		store.answer(2000)
		await store.next()
		store.answer('1')
		await store.next()

		expect(store.isNewBest).toBe(false)
	})

	it('keeps the result screen and shows an error when saving fails', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		api.saveQuizResult.mockRejectedValue(new Error('422'))
		await store.start(2)
		store.answer(2000)
		await store.next()
		store.answer('1')
		await store.next()

		expect(store.phase).toBe('finished')
		expect(store.lastResult.percent).toBe(100)
		expect(showError).toHaveBeenCalled()
	})

	it('resets the history and clears the local copy', async () => {
		api.resetQuizResults.mockResolvedValue({ data: { deleted: 3 } })
		store.results = { rounds: [{ id: 1 }], best: { percent: 90 }, accuracy: { rating: 50 } }

		expect(await store.resetResults()).toBe(true)

		expect(store.results).toEqual({ rounds: [], total: 0, best: null, accuracy: {} })
		expect(showSuccess).toHaveBeenCalled()
	})

	it('keeps the history and shows an error when the reset fails', async () => {
		api.resetQuizResults.mockRejectedValue(new Error('boom'))
		store.results = { rounds: [{ id: 1 }], best: null, accuracy: {} }

		expect(await store.resetResults()).toBe(false)

		expect(store.results.rounds).toHaveLength(1)
		expect(showError).toHaveBeenCalled()
	})

	it('reset() returns to the start screen and drops the round', async () => {
		api.getQuiz.mockResolvedValue({ data: { questions: questions() } })
		await store.start(2)
		store.answer(2000)

		store.reset()

		expect(store.phase).toBe('start')
		expect(store.questions).toEqual([])
		expect(store.answers).toEqual([])
		expect(store.index).toBe(0)
	})

	it('loads meta and results', async () => {
		api.getQuizMeta.mockResolvedValue({ data: { maxQuestions: 7, titleCount: 9 } })
		api.getQuizResults.mockResolvedValue({ data: { rounds: [{ id: 1 }], best: { percent: 80 }, accuracy: { rating: 60 } } })

		await store.fetchMeta()
		await store.fetchResults()

		expect(store.meta).toEqual({ maxQuestions: 7, titleCount: 9 })
		expect(store.results.best.percent).toBe(80)
	})
})
