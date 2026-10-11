import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api from '../services/api.js'
import { buildBreakdown, MAX_POINTS, scoreAnswer } from '../utils/quizScoring.js'
import { useLibrariesStore } from './libraries.js'

const emptyResults = () => ({ rounds: [], total: 0, best: null, accuracy: {} })

/**
 * Quiz store - one running round (kept only in memory) plus the saved history.
 * A reload starts over; only finished rounds are stored on the server.
 */
export const useQuizStore = defineStore('quiz', {
	state: () => ({
		/** @type {{maxQuestions: number, titleCount: number}|null} */
		meta: null,
		/** @type {Array<object>} Questions of the running round */
		questions: [],
		/** @type {number} Index of the current question */
		index: 0,
		/** @type {Array<{value: string|number, points: number}>} Answers given so far */
		answers: [],
		/** @type {'start'|'playing'|'finished'} */
		phase: 'start',
		/** @type {{rounds: Array<object>, best: object|null, accuracy: Object<string, number>}} */
		results: emptyResults(),
		/** @type {object|null} Summary of the round that just finished */
		lastResult: null,
		/** @type {boolean} Whether the finished round beat the previous best */
		isNewBest: false,
		loading: false,
	}),

	getters: {
		currentQuestion: (state) => state.questions[state.index] ?? null,
		currentAnswer: (state) => state.answers[state.index] ?? null,
		isLastQuestion: (state) => state.index >= state.questions.length - 1,
	},

	actions: {
		_libraryId() {
			const id = useLibrariesStore().activeLibraryId
			return id !== null ? id : undefined
		},

		async fetchMeta() {
			try {
				const response = await api.getQuizMeta(this._libraryId())
				this.meta = response.data
			} catch (error) {
				console.error('Failed to load quiz info:', error)
				showError(t('moviedb', 'Failed to load the quiz. Please try again.'))
			}
		},

		async fetchResults() {
			try {
				const response = await api.getQuizResults(this._libraryId())
				this.results = {
					rounds: response.data.rounds ?? [],
					total: response.data.total ?? (response.data.rounds ?? []).length,
					best: response.data.best ?? null,
					accuracy: response.data.accuracy ?? {},
				}
			} catch (error) {
				console.error('Failed to load quiz results:', error)
				showError(t('moviedb', 'Failed to load quiz results.'))
			}
		},

		/**
		 * Starts a round.
		 *
		 * @param {number} count - Number of questions wanted
		 * @return {Promise<boolean>} Whether a round was started
		 */
		async start(count) {
			this.loading = true
			try {
				const response = await api.getQuiz(count, this._libraryId())
				const questions = response.data.questions ?? []
				if (!questions.length) {
					showError(t('moviedb', 'Failed to start the quiz. Please try again.'))
					return false
				}
				this.questions = questions
				this.index = 0
				this.answers = []
				this.lastResult = null
				this.isNewBest = false
				this.phase = 'playing'
				return true
			} catch (error) {
				console.error('Failed to start quiz:', error)
				showError(t('moviedb', 'Failed to start the quiz. Please try again.'))
				return false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Records the answer to the current question (once).
		 *
		 * @param {string|number} value - Option key or estimate
		 * @return {number|null} Points awarded, or null if there was nothing to answer
		 */
		answer(value) {
			const question = this.currentQuestion
			if (this.phase !== 'playing' || !question || this.currentAnswer) {
				return null
			}
			const points = scoreAnswer(question, value)
			this.answers.push({ value, points })
			return points
		},

		/**
		 * Moves on to the next question, or finishes the round after the last one.
		 */
		async next() {
			if (!this.currentAnswer) {
				return
			}
			if (this.isLastQuestion) {
				await this.finish()
			} else {
				this.index++
			}
		},

		async finish() {
			const points = this.answers.map((a) => a.points)
			const score = points.reduce((sum, p) => sum + p, 0)
			const maxScore = this.questions.length * MAX_POINTS
			const breakdown = buildBreakdown(this.questions, points)
			const previousBest = this.results.best

			this.lastResult = {
				questionCount: this.questions.length,
				score,
				maxScore,
				percent: Math.round(score * 100 / maxScore),
				breakdown,
			}
			this.isNewBest = previousBest !== null && this.lastResult.percent > previousBest.percent
			this.phase = 'finished'

			try {
				await api.saveQuizResult(
					{ questionCount: this.questions.length, score, maxScore, breakdown },
					this._libraryId(),
				)
				await this.fetchResults()
			} catch (error) {
				console.error('Failed to save quiz result:', error)
				showError(t('moviedb', 'Failed to save quiz result. Please try again.'))
			}
		},

		/**
		 * Deletes the user's own quiz history in the active library.
		 *
		 * @return {Promise<boolean>}
		 */
		async resetResults() {
			try {
				await api.resetQuizResults(this._libraryId())
				this.results = emptyResults()
				showSuccess(t('moviedb', 'Quiz history reset.'))
				return true
			} catch (error) {
				console.error('Failed to reset quiz history:', error)
				showError(t('moviedb', 'Failed to reset quiz history. Please try again.'))
				return false
			}
		},

		/**
		 * Back to the start screen (new round, library switch).
		 */
		reset() {
			this.questions = []
			this.index = 0
			this.answers = []
			this.lastResult = null
			this.isNewBest = false
			this.phase = 'start'
		},
	},
})
