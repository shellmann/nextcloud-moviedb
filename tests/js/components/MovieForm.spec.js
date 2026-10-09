import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MovieForm from '@/components/MovieForm.vue'

// The real @nextcloud/vue modules pull in CSS that Vitest can't load.
// The checkbox stub mirrors NcCheckboxRadioSwitch's v-model contract in nc/vue 9
// (modelValue / update:modelValue).
vi.mock('@nextcloud/vue', () => ({
	NcTextField: { template: '<input />' },
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
