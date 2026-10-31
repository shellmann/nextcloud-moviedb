import { describe, it, expect, vi } from 'vitest'
import { h } from 'vue'
import { mount } from '@vue/test-utils'
import MovieForm from '@/components/MovieForm.vue'

// The real @nextcloud/vue modules pull in CSS that Vitest can't load.
// The checkbox stub mirrors NcCheckboxRadioSwitch's v-model contract in nc/vue 9
// (modelValue / update:modelValue).
vi.mock('@nextcloud/vue', () => ({
	// Like the real NcTextField, which calls modelValue.toString() while rendering.
	NcTextField: {
		props: ['modelValue'],
		render() { return h('input', { value: this.modelValue.toString() }) },
	},
	NcSelect: { template: '<div />' },
	NcButton: { template: '<button><slot /></button>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="nc-checkbox"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))

vi.mock('@/services/api.js', () => ({
	default: { getTmdbMovieDetails: vi.fn() },
}))

const mountForm = (movie) => mount(MovieForm, {
	props: { movie: { title: 'Inception', ...movie } },
	global: { mocks: { t: (app, text) => text } },
})

describe('MovieForm favorite switch', () => {
	it('shows the stored favorite state', () => {
		const wrapper = mountForm({ isFavorite: true })

		const checkbox = wrapper.find('.nc-checkbox input')
		expect(checkbox.element.checked).toBe(true)
		expect(wrapper.find('.nc-checkbox').text()).toBe('Mark as Favorite')
	})

	it('submits the toggled favorite state', async () => {
		const wrapper = mountForm({ isFavorite: false })

		await wrapper.find('.nc-checkbox input').setValue(true)
		wrapper.vm.submit()

		expect(wrapper.emitted('submit')[0][0].isFavorite).toBe(true)
	})
})

describe('MovieForm text fields', () => {
	// NcTextField throws on a null model value, so movies without an original
	// title or a watch date must reach the fields as empty strings.
	it('never hands null to the text fields', () => {
		const wrapper = mountForm({ originalTitle: null, dateWatched: null })

		expect(wrapper.vm.formData.title).toBe('Inception')
		expect(wrapper.vm.formData.originalTitle).toBe('')
		expect(wrapper.vm.formData.dateWatched).toBe('')
	})

	it('submits empty optional text fields as null again', () => {
		const wrapper = mountForm({ originalTitle: null, dateWatched: null })

		wrapper.vm.submit()

		const submitted = wrapper.emitted('submit')[0][0]
		expect(submitted.originalTitle).toBeNull()
		expect(submitted.dateWatched).toBeNull()
	})

	it('keeps values that are set', () => {
		const wrapper = mountForm({ originalTitle: 'Inception', dateWatched: '2026-10-31' })

		wrapper.vm.submit()

		const submitted = wrapper.emitted('submit')[0][0]
		expect(submitted.originalTitle).toBe('Inception')
		expect(submitted.dateWatched).toBe('2026-10-31')
	})
})
