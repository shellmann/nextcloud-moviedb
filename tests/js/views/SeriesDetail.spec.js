import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useSeriesStore } from '@/stores/series.js'
import SeriesDetail from '@/views/SeriesDetail.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcDialog: { template: '<div />' },
	NcProgressBar: { template: '<div class="nc-progress" />' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
}))

const IconStub = { template: '<span />' }

const show = (extra = {}) => ({
	id: 3,
	title: 'Fleabag',
	seasons: [],
	watchedEpisodeCount: 0,
	airedEpisodeCount: 0,
	progress: 0,
	caughtUp: false,
	nextEpisode: null,
	watchStatus: 'in_progress',
	...extra,
})

async function mountDetail(series) {
	const store = useSeriesStore()
	store.fetchOne = vi.fn(async () => { store.currentSeries = series })
	store.setStopped = vi.fn().mockResolvedValue(series)
	const wrapper = mount(SeriesDetail, {
		props: { id: '3' },
		global: {
			stubs: {
				ArrowLeft: IconStub, Check: IconStub, CheckAll: IconStub, ChevronDown: IconStub, ChevronRight: IconStub,
				Delete: IconStub, EyeOff: IconStub, Pencil: IconStub, PlayCircleOutline: IconStub, Television: IconStub,
			},
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
		},
	})
	await flushPromises()
	return { wrapper, store }
}

const buttons = (wrapper) => wrapper.findAll('.progress-actions .nc-button').map((b) => b.text())

describe('SeriesDetail progress block', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('explains a show without episode data instead of showing 0 / 0 and useless buttons', async () => {
		const { wrapper } = await mountDetail(show())

		expect(wrapper.find('.no-episodes').text()).toBe('No episodes are known for this show yet.')
		expect(wrapper.find('.nc-progress').exists()).toBe(false)
		expect(wrapper.find('.progress-actions').exists()).toBe(false)
	})

	it('says when episodes are known but none has aired', async () => {
		const { wrapper } = await mountDetail(show({ seasons: [{ seasonNumber: 1, episodes: [{ id: 1 }] }] }))

		expect(wrapper.find('.no-episodes').text()).toBe('No episodes have aired yet.')
	})

	it('still offers to resume a stopped show without aired episodes', async () => {
		const { wrapper } = await mountDetail(show({ watchStatus: 'stopped' }))

		expect(buttons(wrapper)).toEqual(['Resume watching'])
	})

	it('shows progress and all actions once episodes have aired', async () => {
		const { wrapper, store } = await mountDetail(show({ watchedEpisodeCount: 2, airedEpisodeCount: 5, progress: 40 }))

		expect(wrapper.find('.no-episodes').exists()).toBe(false)
		expect(wrapper.find('.nc-progress').exists()).toBe(true)
		expect(buttons(wrapper)).toEqual(['Mark series watched', 'Mark series unwatched', 'Stop watching'])

		await wrapper.findAll('.progress-actions .nc-button')[2].trigger('click')
		expect(store.setStopped).toHaveBeenCalledWith(3, true)
	})
})

describe('SeriesDetail watch summary', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('shows the language watched with its flag', async () => {
		const { wrapper } = await mountDetail(show({ languageWatched: 'de' }))

		expect(wrapper.find('.series-watch-summary').text()).toContain('Language: 🇩🇪 Deutsch (German)')
	})
})
