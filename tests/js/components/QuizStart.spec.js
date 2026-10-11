import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import QuizStart from '@/components/quiz/QuizStart.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="count-input" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
}))

function mountStart(props = {}) {
	return mount(QuizStart, {
		props: { maxQuestions: 12, titleCount: 15, ...props },
		global: { mocks: { t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m) } },
	})
}

const buttons = (wrapper) => wrapper.findAll('button')
const preset = (wrapper, n) => buttons(wrapper).find((b) => b.text() === String(n))

describe('QuizStart', () => {
	it('shows the library size and the cap', () => {
		const wrapper = mountStart()
		expect(wrapper.text()).toContain('Your library has 15 titles, so you can answer up to 12 questions.')
	})

	it('shows an empty hint and no start button when nothing can be asked', () => {
		const wrapper = mountStart({ maxQuestions: 0, titleCount: 3 })

		expect(wrapper.text()).toContain('Add some titles to your library to play the quiz.')
		expect(wrapper.text()).not.toContain('Start quiz')
	})

	it('disables presets above the cap', () => {
		const wrapper = mountStart({ maxQuestions: 8, titleCount: 8 })

		expect(preset(wrapper, 5).attributes('disabled')).toBeUndefined()
		expect(preset(wrapper, 10).attributes('disabled')).toBeDefined()
		expect(preset(wrapper, 20).attributes('disabled')).toBeDefined()
	})

	it('defaults to 10, or to the cap when that is lower', () => {
		expect(mountStart().vm.count).toBe(10)
		expect(mountStart({ maxQuestions: 4, titleCount: 4 }).vm.count).toBe(4)
	})

	it('clamps typed numbers into 1..max and ignores garbage', async () => {
		const wrapper = mountStart()
		const input = wrapper.find('.count-input')

		await input.setValue('99')
		expect(wrapper.vm.count).toBe(12)

		await input.setValue('0')
		expect(wrapper.vm.count).toBe(1)

		await input.setValue('7')
		expect(wrapper.vm.count).toBe(7)

		await input.setValue('abc')
		expect(wrapper.vm.count).toBe(7)
	})

	it('shows the clamped number again when the field loses focus', async () => {
		const wrapper = mountStart()
		const input = wrapper.find('.count-input')

		await input.setValue('99') // snaps to the cap, count changes 10 -> 12
		await input.setValue('129') // count stays 12, so only `text` can show the typing
		expect(wrapper.vm.count).toBe(12)
		expect(wrapper.vm.text).toBe('129') // not forced back while typing

		await input.trigger('blur')

		expect(wrapper.vm.text).toBe('12')
		expect(input.element.value).toBe('12')
	})

	it('re-clamps the choice when the cap shrinks (library switch)', async () => {
		const wrapper = mountStart()
		expect(wrapper.vm.count).toBe(10)

		await wrapper.setProps({ maxQuestions: 6, titleCount: 6 })

		expect(wrapper.vm.count).toBe(6)
	})

	it('explains how points are calculated, using the real scoring table', () => {
		const wrapper = mountStart()
		const text = wrapper.find('.quiz-scoring').text()

		expect(text).toContain('How are points calculated?')
		expect(text).toContain('Every question is worth up to 10 points.')
		expect(text).toContain('Multiple choice: 10 points for the right answer, otherwise 0.')
		expect(text).toContain('Year estimates: 10 points if exact, 7 if 1 year off, 4 if 2 years off, 2 if 3 years off.')
		expect(text).toContain('Number answers (rating, seasons): 10 points if exact, 5 if 1 off.')
	})

	it('emits start with the chosen count', async () => {
		const wrapper = mountStart()
		await preset(wrapper, 5).trigger('click')

		await buttons(wrapper).find((b) => b.text() === 'Start quiz').trigger('click')

		expect(wrapper.emitted('start')[0]).toEqual([5])
	})
})
