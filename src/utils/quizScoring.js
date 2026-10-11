import { translate as t } from '@nextcloud/l10n'

/** Points for a perfect answer; the server checks maxScore = questions * this. */
export const MAX_POINTS = 10

/** Points for a year estimate that is 0, 1, 2 or 3 years off. */
export const YEAR_POINTS = [10, 7, 4, 2]
/** Points for a number answer (rating, seasons) that is 0 or 1 off. */
export const NUMBER_POINTS = [10, 5]

/**
 * Points (0..MAX_POINTS) for an answer to a quiz question.
 *
 * @param {object} question - A question as returned by GET /api/quiz
 * @param {string|number} value - The option key (choice) or the estimate (year/number)
 * @return {number}
 */
export function scoreAnswer(question, value) {
	if (question.mode === 'choice') {
		return String(value) === String(question.answer) ? MAX_POINTS : 0
	}
	const distance = Math.abs(Number(value) - Number(question.answer))
	const table = question.mode === 'year' ? YEAR_POINTS : NUMBER_POINTS
	return table[distance] ?? 0
}

/**
 * Sum points per question type, in the shape the server expects:
 * { type: { points, max } }.
 *
 * @param {Array<object>} questions - The round's questions
 * @param {Array<number>} points - Points per question, same order
 * @return {Object<string, {points: number, max: number}>}
 */
export function buildBreakdown(questions, points) {
	const breakdown = {}
	questions.forEach((question, i) => {
		const entry = breakdown[question.type] ?? { points: 0, max: 0 }
		entry.points += points[i] ?? 0
		entry.max += MAX_POINTS
		breakdown[question.type] = entry
	})
	return breakdown
}

/**
 * Human readable name of a question type (literal t() calls so the
 * translation audit can see them).
 *
 * @param {string} type - Question type
 * @return {string}
 */
export function quizTypeLabel(type) {
	switch (type) {
		case 'release_year': return t('moviedb', 'Release year')
		case 'watch_year': return t('moviedb', 'Watch year')
		case 'director': return t('moviedb', 'Director')
		case 'actor': return t('moviedb', 'Cast')
		case 'watched_first': return t('moviedb', 'Watch order')
		case 'poster': return t('moviedb', 'Poster')
		case 'rating': return t('moviedb', 'Your rating')
		case 'review': return t('moviedb', 'Your review')
		case 'seasons': return t('moviedb', 'Seasons')
		default: return type
	}
}
