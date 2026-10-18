import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useMoviesStore } from '@/stores/movies.js'
import { useWatchesStore } from '@/stores/watches.js'
import MovieDetail from '@/views/MovieDetail.vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcDialog: { template: '<div />' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
}))

const IconStub = { template: '<span />' }

async function mountDetail(watches) {
	const movies = useMoviesStore()
	movies.fetchOne = vi.fn(async () => { movies.currentMovie = { id: 5, title: 'Amélie' } })
	const watchesStore = useWatchesStore()
	watchesStore.fetchForMovie = vi.fn(async () => { watchesStore.watches = watches })
	const wrapper = mount(MovieDetail, {
		props: { id: '5' },
		global: {
			stubs: { ArrowLeft: IconStub, Delete: IconStub, Movie: IconStub, Pencil: IconStub, Plus: IconStub, RatingStars: IconStub },
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
		},
	})
	await flushPromises()
	return wrapper
}

describe('MovieDetail watch history', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	// The language disappeared from the detail page in 1.2.0 with the old summary box.
	it('shows the language of each watch with its flag', async () => {
		const wrapper = await mountDetail([
			{ id: 2, watchedAt: '2025-01-02', languageWatched: 'fr' },
			{ id: 1, watchedAt: '2024-01-02', languageWatched: null },
		])
		const entries = wrapper.findAll('.watch-entry')

		expect(entries[0].find('.watch-language').text()).toBe('🇫🇷 French')
		expect(entries[1].find('.watch-language').exists()).toBe(false)
	})
})
