import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import api from '@/services/api.js'
import MovieDetail from '@/views/MovieDetail.vue'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useMoviesStore } from '@/stores/movies.js'
import { useWatchesStore } from '@/stores/watches.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcDialog: { template: '<div />' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
}))

const IconStub = { template: '<span />' }

async function mountDetail(watches, extras = { trailer: null, watch: null }, movie = {}) {
	vi.spyOn(api, 'getTmdbExtras').mockResolvedValue({ data: extras })
	const movies = useMoviesStore()
	movies.fetchOne = vi.fn(async () => { movies.currentMovie = { id: 5, title: 'Amélie', tmdbId: 194, ...movie } })
	const watchesStore = useWatchesStore()
	watchesStore.fetchForMovie = vi.fn(async (id) => { watchesStore.movieId = id; watchesStore.watches = watches })
	const wrapper = mount(MovieDetail, {
		props: { id: '5' },
		global: {
			stubs: { ArrowLeft: IconStub, Delete: IconStub, Movie: IconStub, Pencil: IconStub, Play: IconStub, Plus: IconStub, RatingStars: IconStub },
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

describe('MovieDetail trailer and where to watch', () => {
	const extras = {
		trailer: { site: 'YouTube', key: 'abc123', name: 'Trailer', url: 'https://www.youtube.com/watch?v=abc123' },
		watch: {
			region: 'DE',
			link: 'https://www.themoviedb.org/movie/194/watch?locale=DE',
			stream: [{ id: 8, name: 'Netflix', logoPath: '/n.jpg' }],
			free: [],
			rent: [],
			buy: [],
		},
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'viewer', permissionEdit: false }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('offers the trailer as an external link and lists where to watch', async () => {
		const wrapper = await mountDetail([], extras)

		const trailer = wrapper.find('.trailer-button')
		expect(trailer.attributes('href')).toBe('https://www.youtube.com/watch?v=abc123')
		expect(trailer.attributes('target')).toBe('_blank')
		expect(trailer.text()).toContain('Play trailer')
		expect(wrapper.find('.where-to-watch img').attributes('alt')).toBe('Netflix')
		expect(api.getTmdbExtras).toHaveBeenCalledWith('movie', '194', 'de-DE')
	})

	it('shows neither when TMDB has nothing or no key is set', async () => {
		const wrapper = await mountDetail([])

		expect(wrapper.find('.trailer-button').exists()).toBe(false)
		expect(wrapper.find('.where-to-watch').exists()).toBe(false)
	})

	it('asks TMDB nothing for a movie without a TMDB id', async () => {
		await mountDetail([], extras, { tmdbId: null })

		expect(api.getTmdbExtras).not.toHaveBeenCalled()
	})
})

describe('MovieDetail route changes', () => {
	it('loads the other movie when only the id changes', async () => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.whenReady = vi.fn().mockResolvedValue()
		const movies = useMoviesStore()
		movies.fetchOne = vi.fn(async (id) => { movies.currentMovie = { id: Number(id), title: 'Movie ' + id } })
		const watchesStore = useWatchesStore()
		watchesStore.fetchForMovie = vi.fn(async (id) => { watchesStore.movieId = id; watchesStore.watches = [] })
		const wrapper = mount(MovieDetail, {
			props: { id: '5' },
			global: {
				stubs: { ArrowLeft: IconStub, Delete: IconStub, Movie: IconStub, Pencil: IconStub, Play: IconStub, Plus: IconStub, RatingStars: IconStub },
				mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
			},
		})
		await flushPromises()

		await wrapper.setProps({ id: '9' })
		await flushPromises()

		expect(movies.fetchOne).toHaveBeenLastCalledWith('9')
		expect(watchesStore.fetchForMovie).toHaveBeenLastCalledWith('9')
	})
})

describe('MovieDetail dialogs and stale loads', () => {
	function setup() {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		const movies = useMoviesStore()
		movies.fetchOne = vi.fn(async (id) => { movies.currentMovie = { id: Number(id), title: 'Movie ' + id } })
		const watchesStore = useWatchesStore()
		watchesStore.fetchForMovie = vi.fn(async (id) => { watchesStore.movieId = id; watchesStore.watches = [] })
		const wrapper = mount(MovieDetail, {
			props: { id: '5' },
			global: {
				stubs: { ArrowLeft: IconStub, Delete: IconStub, Movie: IconStub, Pencil: IconStub, Play: IconStub, Plus: IconStub, RatingStars: IconStub },
				mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
			},
		})
		return { wrapper, movies, watchesStore }
	}

	it('closes an open dialog when the route moves to another movie', async () => {
		const { wrapper } = setup()
		await flushPromises()
		wrapper.vm.showDeleteDialog = true
		wrapper.vm.showLogDialog = true

		await wrapper.setProps({ id: '6' })

		expect(wrapper.vm.showDeleteDialog).toBe(false)
		expect(wrapper.vm.showLogDialog).toBe(false)
	})

	it('a load that finishes after the page was unmounted starts no further request', async () => {
		const { wrapper, movies, watchesStore } = setup()
		let finish
		movies.fetchOne = vi.fn(() => new Promise((resolve) => { finish = resolve }))
		watchesStore.fetchForMovie.mockClear()
		const loading = wrapper.vm.loadMovie()
		await flushPromises()
		wrapper.unmount()
		finish()
		await loading

		expect(watchesStore.fetchForMovie).not.toHaveBeenCalledWith('5')
	})

	it('shows only the history of the movie of this route', async () => {
		const { wrapper, watchesStore } = setup()
		await flushPromises()
		watchesStore.movieId = 4
		watchesStore.watches = [{ id: 1, movieId: 4, watchedAt: '2026-01-01', rating: 5 }]
		await flushPromises()

		expect(wrapper.vm.watches).toEqual([])
	})
})
