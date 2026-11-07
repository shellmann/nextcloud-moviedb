import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Watchlist from '@/views/Watchlist.vue'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useWatchlistStore } from '@/stores/watchlist.js'

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

const routerReplace = vi.fn()

function mountView(query = {}, route = { path: '/watchlist', query }) {
	return mount(Watchlist, {
		global: {
			stubs: {
				Check: IconStub,
				Close: IconStub,
				Delete: IconStub,
				FerrisWheel: IconStub,
				Pencil: IconStub,
				PlaylistPlay: IconStub,
				Plus: IconStub,
			},
			mocks: { t: (app, text) => text, $router: { push: vi.fn(), replace: routerReplace }, $route: route },
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

describe('Watchlist link from a notification (?item=)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		const store = useWatchlistStore()
		store.fetchAll = vi.fn().mockResolvedValue()
		store.items = [
			{ id: 1, title: 'Heat 2', mediaType: 'movie', priority: 0, releaseDate: '2027-03-12' },
			{ id: 2, title: 'Dune', mediaType: 'movie', priority: 0, releaseDate: '2021-09-15' },
		]
		Element.prototype.scrollIntoView = vi.fn()
	})

	it('scrolls to, focuses and highlights the linked entry', async () => {
		const wrapper = mountView({ item: '2' })
		await flushPromises()

		const entry = wrapper.find('#watchlist-item-2')
		expect(entry.classes()).toContain('watchlist-item--highlight')
		expect(wrapper.find('#watchlist-item-1').classes()).not.toContain('watchlist-item--highlight')
		expect(entry.attributes('tabindex')).toBe('-1')
		expect(Element.prototype.scrollIntoView).toHaveBeenCalled()
		// the stale ?item= is dropped from the URL
		expect(routerReplace).toHaveBeenCalledWith({ path: '/watchlist', query: {} })
	})

	it('ignores an item that is not in the list', async () => {
		const wrapper = mountView({ item: '99' })
		await flushPromises()

		expect(wrapper.findAll('.watchlist-item--highlight')).toHaveLength(0)
	})
})

describe('Watchlist link to an entry on a later page', () => {
	let store
	const pages = {
		1: [{ id: 1, title: 'Heat 2', mediaType: 'movie', priority: 0 }],
		2: [{ id: 60, title: 'Dune', mediaType: 'movie', priority: 0 }],
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		store = useWatchlistStore()
		store.totalPages = 2
		store.fetchAll = vi.fn(async () => { store.items = pages[store.page] })
		Element.prototype.scrollIntoView = vi.fn()
	})

	it('looks through the pages until the entry shows up', async () => {
		const wrapper = mountView({ item: '60' })
		await flushPromises()

		expect(store.page).toBe(2)
		expect(wrapper.find('#watchlist-item-60').classes()).toContain('watchlist-item--highlight')
	})

	it('goes back to the first page when the entry does not exist any more', async () => {
		const wrapper = mountView({ item: '999' })
		await flushPromises()

		expect(store.page).toBe(1)
		expect(wrapper.findAll('.watchlist-item--highlight')).toHaveLength(0)
	})
})

describe('Watchlist already open when a link arrives', () => {
	let store
	const pages = {
		1: [{ id: 1, title: 'Heat 2', mediaType: 'movie', priority: 0 }],
		2: [{ id: 60, title: 'Dune', mediaType: 'movie', priority: 0 }],
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		store = useWatchlistStore()
		store.totalPages = 2
		store.fetchAll = vi.fn(async () => { store.items = pages[store.page] })
		Element.prototype.scrollIntoView = vi.fn()
	})

	it('reloads and reveals the entry when ?item= is added to the open route', async () => {
		const route = reactive({ path: '/watchlist', query: {} })
		const wrapper = mountView({}, route)
		await flushPromises()
		expect(wrapper.findAll('.watchlist-item--highlight')).toHaveLength(0)

		route.query = { item: '60' }
		await flushPromises()

		expect(wrapper.find('#watchlist-item-60').classes()).toContain('watchlist-item--highlight')
	})

	it('a newer user action cancels the search for the linked entry', async () => {
		let releasePage2
		store.fetchAll = vi.fn(async () => {
			if (store.page === 2) {
				await new Promise((resolve) => { releasePage2 = resolve })
			}
			store.items = pages[store.page]
		})
		const wrapper = mountView({ item: '60' })
		await flushPromises()
		expect(store.page).toBe(2)

		wrapper.vm.onTypeChange(null) // the user picks another filter while the scan waits
		releasePage2()
		await flushPromises()

		expect(wrapper.findAll('.watchlist-item--highlight')).toHaveLength(0)
		// the filter change reset the page itself; the old scan did not move it again
		expect(store.page).toBe(1)
	})
})

describe('Watchlist cold links and removed links', () => {
	let store
	let libraries
	const pages = {
		1: [{ id: 1, title: 'Heat 2', mediaType: 'movie', priority: 0 }],
		2: [{ id: 60, title: 'Dune', mediaType: 'movie', priority: 0 }],
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = null
		store = useWatchlistStore()
		store.totalPages = 2
		store.fetchAll = vi.fn(async () => { store.items = pages[store.page] })
		Element.prototype.scrollIntoView = vi.fn()
	})

	it('a cold link survives the libraries finishing to load (the active library appears meanwhile)', async () => {
		let ready
		libraries.whenReady = vi.fn(() => new Promise((resolve) => { ready = resolve }))
		const wrapper = mountView({ item: '60' })
		await flushPromises()

		libraries.activeLibraryId = 1 // the initial library load switches the library
		await flushPromises()
		ready()
		await flushPromises()

		expect(wrapper.find('#watchlist-item-60').classes()).toContain('watchlist-item--highlight')
	})

	it('removing ?item= from the URL ends a running search', async () => {
		libraries.whenReady = vi.fn().mockResolvedValue()
		let releasePage2
		store.fetchAll = vi.fn(async () => {
			if (store.page === 2) {
				await new Promise((resolve) => { releasePage2 = resolve })
			}
			store.items = pages[store.page]
		})
		const route = reactive({ path: '/watchlist', query: { item: '60' } })
		const wrapper = mountView({}, route)
		await flushPromises()

		const callsBefore = store.fetchAll.mock.calls.length
		route.query = {} // back to the plain watchlist while page 2 is still loading
		await flushPromises()
		releasePage2()
		await flushPromises()

		expect(wrapper.findAll('.watchlist-item--highlight')).toHaveLength(0)
		// a fresh request for the list replaces the page the scan had moved to
		expect(store.fetchAll.mock.calls.length).toBeGreaterThan(callsBefore)
		// ... for the page the user was on, not the one the scan had moved to
		expect(store.page).toBe(1)
	})

	it('a stale scan does not restore its start page after the user chose another page or filter', async () => {
		libraries.whenReady = vi.fn().mockResolvedValue()
		let releasePage2
		store.fetchAll = vi.fn(async () => {
			if (store.page === 2) {
				await new Promise((resolve) => { releasePage2 = resolve })
			}
			store.items = pages[store.page]
		})
		const route = reactive({ path: '/watchlist', query: { item: '60' } })
		const wrapper = mountView({}, route)
		await flushPromises()

		wrapper.vm.onTypeChange(null) // the user's own choice takes over while the scan waits
		releasePage2()
		await flushPromises()
		const callsBefore = store.fetchAll.mock.calls.length
		route.query = {} // the link is removed later
		await flushPromises()

		expect(store.fetchAll.mock.calls.length).toBe(callsBefore) // nothing is requested behind the user's back
		expect(wrapper.exists()).toBe(true)
	})
})
