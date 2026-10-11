import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Quiz from '@/views/Quiz.vue'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useQuizStore } from '@/stores/quiz.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcDialog: { props: ['open', 'name'], template: '<div v-if="open" class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcTextField: { template: '<input />' },
	NcProgressBar: { template: '<div></div>' },
}))

const t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m)

describe('Quiz view', () => {
	let quiz
	let libraries

	const mountView = async () => {
		const wrapper = mount(Quiz, {
			global: { mocks: { t }, stubs: { Minus: true, Plus: true } },
		})
		await flushPromises()
		return wrapper
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		quiz = useQuizStore()
		libraries = useLibrariesStore()
		libraries.whenReady = vi.fn().mockResolvedValue()
		libraries.activeLibraryId = 1
		quiz.fetchMeta = vi.fn().mockImplementation(async () => { quiz.meta = { maxQuestions: 10, titleCount: 12 } })
		quiz.fetchResults = vi.fn().mockResolvedValue()
		quiz.resetResults = vi.fn().mockResolvedValue(true)
	})

	it('loads meta and results and shows the start screen', async () => {
		const wrapper = await mountView()

		expect(quiz.fetchMeta).toHaveBeenCalled()
		expect(quiz.fetchResults).toHaveBeenCalled()
		expect(wrapper.text()).toContain('Start quiz')
		expect(wrapper.text()).toContain('Your results')
	})

	it('hides the history while a round is running', async () => {
		const wrapper = await mountView()
		quiz.questions = [{ type: 'rating', mode: 'number', subject: { title: 'X', mediaType: 'movie' }, answer: 5, min: 1, max: 10 }]
		quiz.phase = 'playing'
		await flushPromises()

		expect(wrapper.text()).not.toContain('Your results')
		expect(wrapper.text()).toContain('Question 1 of 1')
	})

	it('explains what the bars in the history mean, but only once there is history', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('.quiz-chart-caption').exists()).toBe(false)

		quiz.results = { rounds: [{ id: 1, percent: 50, playedAt: '2026-10-01 10:00:00' }], best: { percent: 50 }, accuracy: {} }
		await flushPromises()

		expect(wrapper.find('.quiz-chart-caption').text()).toContain('share of the maximum points')
	})

	it('says when only the latest rounds are shown and numbers them from where they start', async () => {
		const wrapper = await mountView()
		const rounds = Array.from({ length: 30 }, (_, i) => ({ id: i + 1, percent: 50, playedAt: '2026-10-01 10:00:00' }))
		quiz.results = { rounds, total: 58, best: { percent: 100 }, accuracy: {} }
		await flushPromises()

		expect(wrapper.text()).toContain('Showing your latest 30 of 58 rounds.')
		expect(wrapper.findAllComponents({ name: 'QuizHistoryChart' })[0].props('firstNumber')).toBe(29)
	})

	it('stays silent about cutting the history when everything is shown', async () => {
		const wrapper = await mountView()
		quiz.results = { rounds: [{ id: 1, percent: 50, playedAt: '2026-10-01 10:00:00' }], total: 1, best: { percent: 50 }, accuracy: {} }
		await flushPromises()

		expect(wrapper.text()).not.toContain('Showing your latest')
	})

	it('only offers the reset button when there is history', async () => {
		const wrapper = await mountView()
		expect(wrapper.text()).not.toContain('Reset quiz history')

		quiz.results = { rounds: [{ id: 1, percent: 50, playedAt: '2026-10-01 10:00:00' }], best: { percent: 50 }, accuracy: { rating: 50 } }
		await flushPromises()

		expect(wrapper.text()).toContain('Reset quiz history')
	})

	it('asks for confirmation before resetting and only then calls the store', async () => {
		quiz.results = { rounds: [{ id: 1, percent: 50, playedAt: '2026-10-01 10:00:00' }], best: { percent: 50 }, accuracy: {} }
		const wrapper = await mountView()

		await wrapper.findAll('button').find((b) => b.text() === 'Reset quiz history').trigger('click')
		expect(wrapper.find('.dialog').exists()).toBe(true)
		expect(wrapper.find('.dialog').text()).toContain('This cannot be undone.')
		expect(quiz.resetResults).not.toHaveBeenCalled()

		await wrapper.find('.dialog').findAll('button').find((b) => b.text() === 'Reset').trigger('click')
		await flushPromises()

		expect(quiz.resetResults).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('cancelling the dialog does not reset anything', async () => {
		quiz.results = { rounds: [{ id: 1, percent: 50, playedAt: '2026-10-01 10:00:00' }], best: null, accuracy: {} }
		const wrapper = await mountView()
		await wrapper.findAll('button').find((b) => b.text() === 'Reset quiz history').trigger('click')

		await wrapper.find('.dialog').findAll('button').find((b) => b.text() === 'Cancel').trigger('click')

		expect(quiz.resetResults).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('offers to quit only while playing, and asks before dropping the round', async () => {
		const wrapper = await mountView()
		expect(wrapper.findAll('button').some((b) => b.text() === 'Quit quiz')).toBe(false)

		quiz.questions = [{ type: 'rating', mode: 'number', subject: { title: 'X', mediaType: 'movie' }, answer: 5, min: 1, max: 10 }]
		quiz.phase = 'playing'
		await flushPromises()
		await wrapper.findAll('button').find((b) => b.text() === 'Quit quiz').trigger('click')

		const dialog = wrapper.find('.dialog')
		expect(dialog.text()).toContain('Your answers so far will not be saved.')
		expect(quiz.phase).toBe('playing')

		await dialog.findAll('button').find((b) => b.text() === 'Keep playing').trigger('click')
		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(quiz.phase).toBe('playing')

		await wrapper.findAll('button').find((b) => b.text() === 'Quit quiz').trigger('click')
		await wrapper.find('.dialog').findAll('button').find((b) => b.text() === 'Quit quiz').trigger('click')

		expect(quiz.phase).toBe('start')
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('drops a running round and reloads when the library changes', async () => {
		await mountView()
		quiz.phase = 'playing'
		quiz.questions = [{}]
		quiz.fetchMeta.mockClear()
		quiz.fetchResults.mockClear()

		libraries.activeLibraryId = 2
		await flushPromises()

		expect(quiz.phase).toBe('start')
		expect(quiz.fetchMeta).toHaveBeenCalled()
		expect(quiz.fetchResults).toHaveBeenCalled()
	})

	it('always begins at the start screen, even after an earlier finished round', async () => {
		quiz.phase = 'finished'
		quiz.lastResult = { score: 1, maxScore: 10, percent: 10, breakdown: {} }

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Start quiz')
	})
})
