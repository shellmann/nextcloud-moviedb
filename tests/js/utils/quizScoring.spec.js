import { describe, expect, it } from 'vitest'
import { buildBreakdown, MAX_POINTS, quizTypeLabel, scoreAnswer } from '@/utils/quizScoring.js'

describe('scoreAnswer', () => {
	it('gives full points for the right choice and none for a wrong one', () => {
		const q = { mode: 'choice', answer: '2' }
		expect(scoreAnswer(q, '2')).toBe(MAX_POINTS)
		expect(scoreAnswer(q, 2)).toBe(MAX_POINTS)
		expect(scoreAnswer(q, '1')).toBe(0)
	})

	it.each([
		[2000, 10],
		[2001, 7],
		[1999, 7],
		[2002, 4],
		[1998, 4],
		[2003, 2],
		[1997, 2],
		[2004, 0],
		[1900, 0],
	])('scores a year estimate of %i with %i points (answer 2000)', (estimate, points) => {
		expect(scoreAnswer({ mode: 'year', answer: 2000 }, estimate)).toBe(points)
	})

	it.each([
		[8, 10],
		[7, 5],
		[9, 5],
		[6, 0],
		[10, 0],
	])('scores a number answer of %i with %i points (answer 8)', (value, points) => {
		expect(scoreAnswer({ mode: 'number', answer: 8 }, value)).toBe(points)
	})
})

describe('buildBreakdown', () => {
	it('sums points and maximum per question type', () => {
		const questions = [{ type: 'rating' }, { type: 'release_year' }, { type: 'rating' }]

		expect(buildBreakdown(questions, [10, 4, 5])).toEqual({
			rating: { points: 15, max: 20 },
			release_year: { points: 4, max: 10 },
		})
	})

	it('adds up to the totals the server validates', () => {
		const questions = [{ type: 'actor' }, { type: 'director' }, { type: 'actor' }]
		const breakdown = buildBreakdown(questions, [10, 0, 10])

		const points = Object.values(breakdown).reduce((s, e) => s + e.points, 0)
		const max = Object.values(breakdown).reduce((s, e) => s + e.max, 0)
		expect(points).toBe(20)
		expect(max).toBe(questions.length * MAX_POINTS)
	})
})

describe('quizTypeLabel', () => {
	it('has a label for every question type the server can send', () => {
		for (const type of ['release_year', 'watch_year', 'director', 'actor', 'watched_first', 'poster', 'rating', 'review', 'seasons']) {
			expect(quizTypeLabel(type)).not.toBe(type)
		}
	})

	it('falls back to the raw type for unknown ones', () => {
		expect(quizTypeLabel('mystery')).toBe('mystery')
	})
})
