import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import TmdbSearchSection from '@/components/TmdbSearchSection.vue'

// The component imports Nc* components from @nextcloud/vue, whose real modules
// pull in CSS that Vitest's Node resolver can't load. Mock them with stubs.
vi.mock('@nextcloud/vue', () => ({
	NcTextField: { template: '<input />' },
	NcButton: { template: '<button><slot /></button>' },
	NcLoadingIcon: { template: '<span></span>' },
	NcCheckboxRadioSwitch: { template: '<div><slot /></div>' },
}))

vi.mock('@/services/api.js', () => ({
	default: {
		searchTmdb: vi.fn(),
		searchTmdbSeries: vi.fn(),
	},
}))

import api from '@/services/api.js'

const IconStub = { template: '<span class="icon-stub"></span>' }
const InputStub = { template: '<input />' }

const mountComponent = (props = {}) => {
	return mount(TmdbSearchSection, {
		props,
		global: {
			stubs: {
				NcTextField: InputStub,
				NcButton: { template: '<button><slot /></button>' },
				NcLoadingIcon: IconStub,
				NcCheckboxRadioSwitch: { template: '<div><slot /></div>' },
				Magnify: IconStub,
			},
			mocks: {
				t: (app, text) => text,
			},
		},
	})
}

describe('TmdbSearchSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	it('defaults to movie media type and hides the toggle', () => {
		const wrapper = mountComponent()
		expect(wrapper.vm.mediaType).toBe('movie')
		expect(wrapper.find('.media-type-toggle').exists()).toBe(false)
	})

	it('shows the toggle and honours initialMediaType when enabled', () => {
		const wrapper = mountComponent({ allowTypeToggle: true, initialMediaType: 'series' })
		expect(wrapper.vm.mediaType).toBe('series')
		expect(wrapper.find('.media-type-toggle').exists()).toBe(true)
	})

	it('getResultTitle prefers title, falls back to name', () => {
		const wrapper = mountComponent()
		expect(wrapper.vm.getResultTitle({ title: 'Movie' })).toBe('Movie')
		expect(wrapper.vm.getResultTitle({ name: 'Show' })).toBe('Show')
		expect(wrapper.vm.getResultTitle({})).toBe('')
	})

	it('getResultYear reads release_date or first_air_date', () => {
		const wrapper = mountComponent()
		expect(wrapper.vm.getResultYear({ release_date: '2024-05-01' })).toBe('2024')
		expect(wrapper.vm.getResultYear({ first_air_date: '2019-09-10' })).toBe('2019')
		expect(wrapper.vm.getResultYear({})).toBe('')
	})

	it('switchMediaType clears prior results and resets searched flag', () => {
		const wrapper = mountComponent({ allowTypeToggle: true })
		wrapper.vm.searchResults = [{ id: 1 }]
		wrapper.vm.searched = true

		wrapper.vm.switchMediaType('series')

		expect(wrapper.vm.mediaType).toBe('series')
		expect(wrapper.vm.searchResults).toEqual([])
		expect(wrapper.vm.searched).toBe(false)
	})

	it('search calls the movie endpoint when media type is movie', async () => {
		api.searchTmdb.mockResolvedValue({ data: { results: [{ id: 1, title: 'M' }] } })
		const wrapper = mountComponent()
		wrapper.vm.searchQuery = 'matrix'

		await wrapper.vm.search()

		expect(api.searchTmdb).toHaveBeenCalled()
		expect(api.searchTmdbSeries).not.toHaveBeenCalled()
		expect(wrapper.vm.searchResults).toHaveLength(1)
		expect(wrapper.vm.searched).toBe(true)
	})

	it('search calls the series endpoint when media type is series', async () => {
		api.searchTmdbSeries.mockResolvedValue({ data: { results: [{ id: 2, name: 'S' }] } })
		const wrapper = mountComponent({ allowTypeToggle: true, initialMediaType: 'series' })
		wrapper.vm.searchQuery = 'wire'

		await wrapper.vm.search()

		expect(api.searchTmdbSeries).toHaveBeenCalled()
		expect(api.searchTmdb).not.toHaveBeenCalled()
		expect(wrapper.vm.searchResults).toHaveLength(1)
	})

	it('search is a no-op with an empty query', async () => {
		const wrapper = mountComponent()
		wrapper.vm.searchQuery = ''
		await wrapper.vm.search()
		expect(api.searchTmdb).not.toHaveBeenCalled()
	})
})
