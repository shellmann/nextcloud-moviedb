import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useWatchlistStore } from '@/stores/watchlist.js'
import Watchlist from '@/views/Watchlist.vue'

vi.mock('@/components/WatchlistPicker.vue', () => ({ default: { template: '<div />' } }))

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcDialog: { template: '<div />' },
	NcEmptyContent: { template: '<div />' },
	NcLoadingIcon: { template: '<span />' },
	NcModal: { template: '<div />' },
	NcSelect: { template: '<div />' },
	NcTextField: { template: '<input />' },
}))

const IconStub = { template: '<span />' }

function mountView() {
	return mount(Watchlist, {
		global: {
			stubs: {
				Check: IconStub, Close: IconStub, Delete: IconStub, FerrisWheel: IconStub, Pencil: IconStub, PlaylistPlay: IconStub, Plus: IconStub,
			},
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
		},
	})
}

describe('Watchlist "New date" badge', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		store = useWatchlistStore()
		store.fetchAll = vi.fn().mockResolvedValue()
		store.acknowledgeReleaseDate = vi.fn().mockResolvedValue(true)
		store.items = [
			{ id: 1, title: 'Heat 2', mediaType: 'movie', priority: 0, releaseDate: '2027-03-12', releaseDateNews: true },
			{ id: 2, title: 'Dune', mediaType: 'movie', priority: 0, releaseDate: '2021-09-15', releaseDateNews: false },
		]
	})

	it('shows the badge only on items with news', async () => {
		const wrapper = mountView()
		await flushPromises()

		const badges = wrapper.findAll('.date-news-badge')
		expect(badges).toHaveLength(1)
		expect(badges[0].text()).toContain('New date')
		expect(wrapper.findAll('.watchlist-item')[0].find('.date-news-badge').exists()).toBe(true)
	})

	it('dismisses the badge through the store', async () => {
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('.date-news-dismiss').trigger('click')

		expect(store.acknowledgeReleaseDate).toHaveBeenCalledWith(1)
	})

	it('hides the dismiss button from users without edit permission', async () => {
		useLibrariesStore().libraries = [{ id: 1, name: 'Shared', isPersonal: false, role: 'member', permissionEdit: false }]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('.date-news-badge').exists()).toBe(true)
		expect(wrapper.find('.date-news-dismiss').exists()).toBe(false)
	})
})
