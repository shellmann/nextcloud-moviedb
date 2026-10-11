import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import QuizQuestion from '@/components/quiz/QuizQuestion.vue'
import QuizStart from '@/components/quiz/QuizStart.vue'
import QuizSummary from '@/components/quiz/QuizSummary.vue'
import QuizAccuracyList from '@/components/quiz/QuizAccuracyList.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { template: '<input />' },
}))

const t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m)
const stubs = { Minus: true, Plus: true, Check: true, Close: true, ArrowRightThin: true, Play: true, Replay: true, TrophyOutline: true }

let wrapper
afterEach(() => wrapper?.unmount())

const subject = { id: 1, mediaType: 'movie', title: 'Echo Valley', posterPath: null, year: 2001 }
const choiceQ = () => ({
	type: 'director', mode: 'choice', subject, answer: '2',
	options: [{ key: '0', label: 'A1' }, { key: '1', label: 'B1' }, { key: '2', label: 'C1' }, { key: '3', label: 'D1' }],
})
const yearQ = () => ({ type: 'release_year', mode: 'year', subject, answer: 2000, min: 1990, max: 2010 })

const mountQuestion = (question, answer = null, props = {}) => {
	wrapper = mount(QuizQuestion, {
		props: { question, index: 1, total: 5, answer, ...props },
		global: { mocks: { t }, stubs },
		attachTo: document.body,
	})
	return wrapper
}

describe('Quiz accessibility', () => {
	describe('QuizQuestion', () => {
		it('moves focus to the question when it appears', async () => {
			mountQuestion(choiceQ())
			await flushPromises()

			expect(document.activeElement).toBe(wrapper.find('.quiz-prompt').element)
		})

		it('labels the region and the options group with the question', () => {
			mountQuestion(choiceQ())
			const promptId = wrapper.find('.quiz-prompt').attributes('id')

			expect(wrapper.find('section').attributes('aria-labelledby')).toBe(promptId)
			expect(wrapper.find('.quiz-options').attributes('aria-labelledby')).toBe(promptId)
		})

		it('exposes progress as a labelled progressbar', () => {
			mountQuestion(choiceQ())
			const bar = wrapper.find('[role=progressbar]')

			expect(bar.attributes('aria-valuemin')).toBe('1')
			expect(bar.attributes('aria-valuemax')).toBe('5')
			expect(bar.attributes('aria-valuenow')).toBe('2')
			expect(bar.attributes('aria-valuetext')).toBe('Question 2 of 5')
			expect(bar.attributes('aria-label')).toBe('Quiz progress')
		})

		it('moves focus to Next after answering so keyboard users keep their place', async () => {
			mountQuestion(yearQ())
			await flushPromises()

			await wrapper.setProps({ answer: { value: 2000, points: 10 } })
			await flushPromises()

			expect(document.activeElement.textContent.trim()).toBe('Next question')
			expect(document.activeElement.getAttribute('aria-describedby')).toBe(wrapper.find('.quiz-feedback').attributes('id'))
		})

		it('announces the result through a status region', () => {
			mountQuestion(yearQ(), { value: 2000, points: 10 })

			expect(wrapper.find('.quiz-feedback').attributes('role')).toBe('status')
		})

		it('does not rely on colour alone to show the right and the chosen wrong option', () => {
			mountQuestion(choiceQ(), { value: '0', points: 0 })
			const options = wrapper.findAll('.quiz-option')

			// correct option: icon + hidden text
			expect(options[2].find('.quiz-option-icon.is-correct').exists()).toBe(true)
			expect(options[2].find('.sr-only').text()).toBe('Correct answer')
			// wrong choice: icon + hidden text
			expect(options[0].find('.quiz-option-icon.is-wrong').exists()).toBe(true)
			expect(options[0].find('.sr-only').text()).toBe('Your answer')
			// untouched options carry no marker
			expect(options[1].find('.sr-only').exists()).toBe(false)
		})

		it('hides decorative option letters from assistive technology', () => {
			mountQuestion(choiceQ())

			expect(wrapper.find('.quiz-option-letter').attributes('aria-hidden')).toBe('true')
		})

		it('lets the slider announce its own value instead of a noisy live region', () => {
			mountQuestion(yearQ())

			expect(wrapper.find('.quiz-estimate-value').attributes('aria-hidden')).toBe('true')
			expect(wrapper.find('.quiz-estimate-value').attributes('aria-live')).toBeUndefined()
			expect(wrapper.find('input[type=range]').attributes('aria-label')).toBe('Your estimate')
		})

		it('gives the step buttons accessible names', () => {
			mountQuestion(yearQ())
			const labels = wrapper.findAll('button').map((b) => b.attributes('aria-label')).filter(Boolean)

			expect(labels).toEqual(['Decrease', 'Increase'])
		})
	})

	describe('QuizStart', () => {
		const mountStart = () => {
			wrapper = mount(QuizStart, {
				props: { maxQuestions: 12, titleCount: 12 },
				global: { mocks: { t }, stubs },
			})
			return wrapper
		}

		it('exposes the chosen preset as a pressed toggle button', async () => {
			mountStart()
			const presets = () => wrapper.findAll('.quiz-start-presets button')

			expect(presets().map((b) => b.attributes('aria-pressed'))).toEqual(['false', 'true', 'false'])

			await presets()[0].trigger('click')

			expect(presets().map((b) => b.attributes('aria-pressed'))).toEqual(['true', 'false', 'false'])
		})

		it('groups the presets under their heading', () => {
			mountStart()
			const group = wrapper.find('.quiz-start-presets')

			expect(group.attributes('role')).toBe('group')
			expect(wrapper.find('#' + group.attributes('aria-labelledby')).text()).toBe('How many questions?')
		})
	})

	describe('QuizSummary', () => {
		const result = { score: 22, maxScore: 50, percent: 44, breakdown: { rating: { points: 5, max: 10 }, director: { points: 0, max: 10 } } }

		const mountSummary = (props = {}) => {
			wrapper = mount(QuizSummary, {
				props: { result, ...props },
				global: { mocks: { t }, stubs },
				attachTo: document.body,
			})
			return wrapper
		}

		it('moves focus to the result heading', async () => {
			mountSummary()
			await flushPromises()

			expect(document.activeElement).toBe(wrapper.find('#quiz-summary-heading').element)
		})

		it('gives the score ring a text alternative', () => {
			mountSummary()

			expect(wrapper.find('svg').attributes('role')).toBe('img')
			expect(wrapper.find('svg').attributes('aria-label')).toBe('Score: 44%')
		})

		it.each([
			[95, 'Outstanding'],
			[75, 'Great job'],
			[50, 'Not bad'],
			[10, 'Time for some rewatching'],
		])('shows a friendly message for %i%%', (percent, text) => {
			mountSummary({ result: { ...result, percent } })

			expect(wrapper.find('.quiz-summary-message').text()).toContain(text)
		})
	})

	describe('QuizAccuracyList', () => {
		it('exposes every bar as a labelled meter with the value also written as text', () => {
			wrapper = mount(QuizAccuracyList, {
				props: { accuracy: { rating: 50, director: 0 } },
				global: { mocks: { t } },
			})
			const meters = wrapper.findAll('[role=meter]')

			expect(meters).toHaveLength(2)
			expect(meters[0].attributes('aria-valuenow')).toBe('50')
			expect(meters[0].attributes('aria-valuetext')).toBe('Your rating: 50%')
			expect(wrapper.text()).toContain('50%')
			expect(wrapper.text()).toContain('0%')
		})
	})
})
