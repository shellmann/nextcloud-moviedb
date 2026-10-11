import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import QuizQuestion from '@/components/quiz/QuizQuestion.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcProgressBar: { template: '<div class="progress"></div>' },
}))

const t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m)

function mountQ(question, answer = null, extra = {}) {
	return mount(QuizQuestion, {
		props: { question, index: 0, total: 3, answer, ...extra },
		global: { mocks: { t }, stubs: { Minus: true, Plus: true } },
	})
}

const subject = (o = {}) => ({ id: 1, mediaType: 'movie', title: 'Secret Title', posterPath: '/p.jpg', year: 2001, ...o })

function choiceQ() {
	return {
		type: 'director',
		mode: 'choice',
		subject: subject(),
		answer: '2',
		options: [{ key: '0', label: 'A' }, { key: '1', label: 'B' }, { key: '2', label: 'C' }, { key: '3', label: 'D' }],
	}
}
const yearQ = (o = {}) => ({ type: 'release_year', mode: 'year', subject: subject(), answer: 2000, min: 1990, max: 2010, ...o })
function posterQ() {
	return {
		type: 'poster',
		mode: 'choice',
		subject: subject(),
		answer: '0',
		options: [{ key: '0', label: 'Secret Title' }, { key: '1', label: 'B' }, { key: '2', label: 'C' }, { key: '3', label: 'D' }],
	}
}

describe('QuizQuestion', () => {
	it('shows progress text and the prompt with the title', () => {
		const wrapper = mountQ(choiceQ())

		expect(wrapper.text()).toContain('Question 1 of 3')
		expect(wrapper.text()).toContain('Who directed "Secret Title"?')
	})

	it('emits the option key when a choice is clicked', async () => {
		const wrapper = mountQ(choiceQ())

		await wrapper.findAll('.quiz-option')[1].trigger('click')

		expect(wrapper.emitted('answer')[0]).toEqual(['1'])
	})

	it('marks the correct and the wrongly chosen option after answering', () => {
		const wrapper = mountQ(choiceQ(), { value: '0', points: 0 })
		const options = wrapper.findAll('.quiz-option')

		expect(options[2].classes()).toContain('is-correct')
		expect(options[0].classes()).toContain('is-wrong')
		expect(options[1].classes()).not.toContain('is-wrong')
		expect(options.every((o) => o.attributes('disabled') !== undefined)).toBe(true)
	})

	it('keeps the title out of the poster question until it is answered', () => {
		const wrapper = mountQ(posterQ())

		expect(wrapper.find('.quiz-prompt').text()).not.toContain('Secret Title')
		expect(wrapper.find('.quiz-poster').classes()).toContain('blurred')
		expect(wrapper.find('.quiz-poster').attributes('alt')).not.toContain('Secret Title')
	})

	it('unblurs the poster after answering', () => {
		const wrapper = mountQ(posterQ(), { value: '0', points: 10 })

		expect(wrapper.find('.quiz-poster').classes()).not.toContain('blurred')
	})

	it('shows the masked review for review questions', () => {
		const q = { ...choiceQ(), type: 'review', extra: { review: 'A … masterpiece' } }

		expect(mountQ(q).find('.quiz-review').text()).toBe('A … masterpiece')
	})

	it('starts a year estimate in the middle and emits it on Answer', async () => {
		const wrapper = mountQ(yearQ())
		expect(wrapper.vm.estimate).toBe(2000 + 1) // middle is 2000 = the answer, so it shifts

		await wrapper.findAll('button').find((b) => b.text() === 'Answer').trigger('click')

		expect(wrapper.emitted('answer')[0]).toEqual([2001])
	})

	it('never starts the estimate on the answer', () => {
		for (const answer of [1995, 2000, 2005, 2010, 1990]) {
			expect(mountQ(yearQ({ answer })).vm.estimate).not.toBe(answer)
		}
	})

	it('stays inside the range at the edges', () => {
		const wrapper = mountQ(yearQ({ min: 5, max: 6, answer: 6 }))

		expect(wrapper.vm.estimate).toBeGreaterThanOrEqual(5)
		expect(wrapper.vm.estimate).toBeLessThanOrEqual(6)
		expect(wrapper.vm.estimate).not.toBe(6)
	})

	it('tells how a year estimate is rated', () => {
		expect(mountQ(yearQ()).find('.quiz-scoring-hint').text())
			.toBe('Exact: 10 points · 1 year off: 7 · 2 years off: 4 · 3 years off: 2')
	})

	it('tells how a number answer is rated', () => {
		const q = { type: 'rating', mode: 'number', subject: subject(), answer: 7, min: 1, max: 10 }

		expect(mountQ(q).find('.quiz-scoring-hint').text()).toBe('Exact: 10 points · 1 off: 5')
	})

	it('shows no estimate hint for multiple choice', () => {
		expect(mountQ(choiceQ()).find('.quiz-scoring-hint').exists()).toBe(false)
	})

	it('shows feedback with points, the correct answer and the right next button', () => {
		const wrapper = mountQ(yearQ(), { value: 2001, points: 7 })

		expect(wrapper.text()).toContain('Close!')
		expect(wrapper.text()).toContain('The correct answer was 2000.')
		expect(wrapper.text()).toContain('+7 points')
		expect(wrapper.text()).toContain('Next question')
	})

	it('offers Show result on the last question and emits next', async () => {
		const wrapper = mountQ(yearQ(), { value: 2000, points: 10 }, { index: 2 })

		expect(wrapper.text()).toContain('Correct!')
		await wrapper.findAll('button').find((b) => b.text() === 'Show result').trigger('click')

		expect(wrapper.emitted('next')).toHaveLength(1)
	})

	it('names the correct option label in feedback for choice questions', () => {
		const wrapper = mountQ(choiceQ(), { value: '0', points: 0 })

		expect(wrapper.text()).toContain('Not quite.')
		expect(wrapper.text()).toContain('The correct answer was C.')
	})

	it('uses first-air wording for series', () => {
		const q = yearQ({ subject: subject({ mediaType: 'series' }) })

		expect(mountQ(q).text()).toContain('first air')
	})
})
