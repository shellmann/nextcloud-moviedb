import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useSeriesStore } from '@/stores/series.js'
import SeriesList from '@/views/SeriesList.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty"><h2>{{ name }}</h2><slot name="action" /></div>' },
	NcLoadingIcon: { template: '<span />' },
	NcTextField: { template: '<input />' },
	// Renders the options so a test can pick one, like a user would.
	NcSelect: {
		props: ['modelValue', 'options', 'ariaLabel'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select" :data-label="ariaLabel"><button v-for="o in options" :key="String(o.id)" class="option" @click="$emit(\'update:modelValue\', o)">{{ o.label }}</button><span class="selected">{{ modelValue?.label }}</span></div>',
	},
}))

const IconStub = { template: '<span />' }

function mountList() {
	return mount(SeriesList, {
		global: {
			stubs: {
				SeriesCard: { props: ['series'], template: '<div class="series-card-stub" />' },
				Heart: IconStub, Plus: IconStub, SortAscending: IconStub, SortDescending: IconStub, Television: IconStub,
			},
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
		},
	})
}

const statusSelect = (wrapper) => wrapper.find('.nc-select[data-label="Watch status"]')

describe('SeriesList status filter', () => {
	let store

	beforeEach(() => {
		localStorage.clear()
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		store = useSeriesStore()
		store.fetchAll = vi.fn().mockResolvedValue()
	})

	it('starts with "All shows" and offers the three statuses', async () => {
		const wrapper = mountList()
		await flushPromises()

		expect(statusSelect(wrapper).find('.selected').text()).toBe('All shows')
		expect(statusSelect(wrapper).findAll('.option').map((o) => o.text())).toEqual(['All shows', 'In progress', 'Finished', 'Stopped'])
	})

	it('filters by the chosen status and remembers it', async () => {
		const wrapper = mountList()
		await flushPromises()

		await statusSelect(wrapper).findAll('.option')[2].trigger('click')

		expect(store.filters.watchStatus).toBe('finished')
		expect(localStorage.getItem('moviedb_seriesStatusFilter')).toBe('finished')
		expect(store.fetchAll).toHaveBeenCalledTimes(2)
	})

	it('opens with the remembered status', async () => {
		localStorage.setItem('moviedb_seriesStatusFilter', 'stopped')
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		useSeriesStore().fetchAll = vi.fn().mockResolvedValue()

		const wrapper = mountList()
		await flushPromises()

		expect(statusSelect(wrapper).find('.selected').text()).toBe('Stopped')
		expect(useSeriesStore().filters.watchStatus).toBe('stopped')
	})

	it('explains an empty filtered list without offering to add a first show', async () => {
		const wrapper = mountList()
		await flushPromises()

		await statusSelect(wrapper).findAll('.option')[3].trigger('click')
		await flushPromises()

		expect(wrapper.find('.empty h2').text()).toBe('No TV shows you stopped watching')
		expect(wrapper.find('.empty .nc-button').exists()).toBe(false)
	})

	it('still offers to add a first show when the library is really empty', async () => {
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('.empty h2').text()).toBe('No TV shows found')
		expect(wrapper.find('.empty .nc-button').text()).toBe('Add your first TV show')
	})
})
