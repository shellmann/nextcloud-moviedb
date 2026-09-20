import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useWatchlistStore } from '@/stores/watchlist.js'

// Mock API module
vi.mock('@/services/api.js', () => ({
	default: {
		getWatchlist: vi.fn(),
		addToWatchlist: vi.fn(),
		updateWatchlistItem: vi.fn(),
		removeFromWatchlist: vi.fn(),
		moveToWatched: vi.fn(),
	},
}))

import api from '@/services/api.js'

describe('Watchlist Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWatchlistStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should have empty items array', () => {
			expect(store.items).toEqual([])
		})

		it('should have loading set to false', () => {
			expect(store.loading).toBe(false)
		})

		it('should have default sort values', () => {
			expect(store.sort).toBe('priority')
			expect(store.dir).toBe('DESC')
		})

		it('should have total set to 0', () => {
			expect(store.total).toBe(0)
		})

		it('should have typeFilter defaulting to "all"', () => {
			expect(store.typeFilter).toBe('all')
		})
	})

	describe('getters', () => {
		it('hasItems should return false when items array is empty', () => {
			expect(store.hasItems).toBe(false)
		})

		it('hasItems should return true when items exist', () => {
			store.items = [{ id: 1, title: 'Test' }]
			expect(store.hasItems).toBe(true)
		})
	})

	describe('fetchAll action', () => {
		it('should set loading to true while fetching', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0 },
			})

			const fetchPromise = store.fetchAll()
			expect(store.loading).toBe(true)

			await fetchPromise
			expect(store.loading).toBe(false)
		})

		it('should update state with fetched items', async () => {
			const mockItems = [
				{ id: 1, title: 'Movie 1' },
				{ id: 2, title: 'Movie 2' },
			]
			api.getWatchlist.mockResolvedValue({
				data: { items: mockItems, total: 2, totalUnfiltered: 2, page: 1, totalPages: 1 },
			})

			await store.fetchAll()

			expect(store.items).toEqual(mockItems)
			expect(store.total).toBe(2)
		})

		it('should store totalUnfiltered separately from the filtered total', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 3, totalUnfiltered: 50, page: 1, totalPages: 1 },
			})

			await store.fetchAll()

			expect(store.total).toBe(3)
			expect(store.totalUnfiltered).toBe(50)
		})

		it('should pass sort, dir, page and limit to API call', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})

			store.sort = 'added_at'
			store.dir = 'ASC'
			await store.fetchAll()

			expect(api.getWatchlist).toHaveBeenCalledWith({
				sort: 'added_at',
				dir: 'ASC',
				page: 1,
				limit: 50,
			})
		})

		it('should include mediaType in params when a type filter is active', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})

			store.typeFilter = 'series'
			await store.fetchAll()

			expect(api.getWatchlist).toHaveBeenCalledWith(
				expect.objectContaining({ mediaType: 'series' }),
			)
		})

		it('should not include mediaType in params when typeFilter is "all"', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})

			await store.fetchAll()

			expect(api.getWatchlist).toHaveBeenCalledWith(
				expect.not.objectContaining({ mediaType: expect.anything() }),
			)
		})

		it('should update page and totalPages from the response', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 120, page: 2, totalPages: 3 },
			})

			await store.fetchAll()

			expect(store.page).toBe(2)
			expect(store.totalPages).toBe(3)
		})

		it('should handle API errors gracefully', async () => {
			const { showError } = await import('@nextcloud/dialogs')
			api.getWatchlist.mockRejectedValue(new Error('Network error'))

			await store.fetchAll()

			expect(store.loading).toBe(false)
			expect(showError).toHaveBeenCalled()
		})
	})

	describe('setSort action', () => {
		it('should update sort and dir', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})

			await store.setSort('added_at', 'ASC')

			expect(store.sort).toBe('added_at')
			expect(store.dir).toBe('ASC')
		})

		it('should trigger a fetch', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})

			await store.setSort('title', 'DESC')

			expect(api.getWatchlist).toHaveBeenCalledWith({
				sort: 'title',
				dir: 'DESC',
				page: 1,
				limit: 50,
			})
		})

		it('should reset to page 1 when changing sort from a later page', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})
			store.page = 3

			await store.setSort('title', 'DESC')

			expect(store.page).toBe(1)
			expect(api.getWatchlist).toHaveBeenCalledWith(
				expect.objectContaining({ page: 1 }),
			)
		})
	})

	describe('resetSort action', () => {
		it('should reset sort to priority DESC', () => {
			store.sort = 'added_at'
			store.dir = 'ASC'

			store.resetSort()

			expect(store.sort).toBe('priority')
			expect(store.dir).toBe('DESC')
		})

		it('should not trigger a fetch', () => {
			store.resetSort()

			expect(api.getWatchlist).not.toHaveBeenCalled()
		})
	})

	describe('resetFilters action', () => {
		it('should reset sort, type filter, and page to defaults', () => {
			store.sort = 'added_at'
			store.dir = 'ASC'
			store.typeFilter = 'series'
			store.page = 3

			store.resetFilters()

			expect(store.sort).toBe('priority')
			expect(store.dir).toBe('DESC')
			expect(store.typeFilter).toBe('all')
			expect(store.page).toBe(1)
		})

		it('should not trigger a fetch on its own', () => {
			// Watchlist.vue::created() and App.vue::onLibraryChange rely on
			// resetFilters() being synchronous and fetch-free — the caller
			// awaits librariesStore.whenReady() and calls fetchAll() itself,
			// exactly once, after the active library is known. If resetFilters()
			// ever fetched internally, that fetch could race ahead of
			// whenReady() and query the wrong (or no) library.
			store.resetFilters()

			expect(api.getWatchlist).not.toHaveBeenCalled()
		})
	})

	describe('create action', () => {
		it('should add new item to the beginning of the list', async () => {
			const newItem = { id: 1, title: 'New Movie' }
			api.addToWatchlist.mockResolvedValue({ data: { item: newItem, alreadyWatched: false } })

			const result = await store.create({ title: 'New Movie' })

			expect(result).toEqual({ item: newItem, alreadyWatched: false })
			expect(store.items[0]).toEqual(newItem)
			expect(store.total).toBe(1)
			expect(store.totalUnfiltered).toBe(1)
		})

		it('should return null on error', async () => {
			api.addToWatchlist.mockRejectedValue(new Error('Create failed'))

			const result = await store.create({ title: 'Test' })

			expect(result).toBeNull()
		})

		it('should pass mediaType through to the API', async () => {
			api.addToWatchlist.mockResolvedValue({ data: { item: { id: 3 }, alreadyWatched: false } })

			await store.create({ title: 'Show', mediaType: 'series' })

			expect(api.addToWatchlist).toHaveBeenCalledWith(
				expect.objectContaining({ mediaType: 'series' }),
			)
		})
	})

	describe('update action', () => {
		it('should update item in the list', async () => {
			store.items = [{ id: 1, title: 'Old Title', priority: 0 }]
			const updatedItem = { id: 1, title: 'Old Title', priority: 2 }
			api.updateWatchlistItem.mockResolvedValue({ data: { item: updatedItem } })

			await store.update(1, { priority: 2 })

			expect(store.items[0].priority).toBe(2)
		})

		it('should return null on error', async () => {
			api.updateWatchlistItem.mockRejectedValue(new Error('Update failed'))

			const result = await store.update(1, { priority: 2 })

			expect(result).toBeNull()
		})
	})

	describe('delete action', () => {
		it('should remove item from the list', async () => {
			store.items = [{ id: 1 }, { id: 2 }]
			store.total = 2
			store.totalUnfiltered = 2
			api.removeFromWatchlist.mockResolvedValue({})

			await store.delete(1)

			expect(store.items).toHaveLength(1)
			expect(store.items[0].id).toBe(2)
			expect(store.total).toBe(1)
			expect(store.totalUnfiltered).toBe(1)
		})

		it('should return true on success', async () => {
			api.removeFromWatchlist.mockResolvedValue({})

			const result = await store.delete(1)

			expect(result).toBe(true)
		})

		it('should return false on error', async () => {
			api.removeFromWatchlist.mockRejectedValue(new Error('Delete failed'))

			const result = await store.delete(1)

			expect(result).toBe(false)
		})
	})

	describe('setTypeFilter action', () => {
		it('should set the type filter and reset to page 1', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 1, totalPages: 0 },
			})
			store.page = 3

			await store.setTypeFilter('series')

			expect(store.typeFilter).toBe('series')
			expect(api.getWatchlist).toHaveBeenCalledWith(
				expect.objectContaining({ mediaType: 'series', page: 1 }),
			)
		})
	})

	describe('setPage action', () => {
		it('should set the page and trigger a fetch', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0, page: 2, totalPages: 3 },
			})

			await store.setPage(2)

			expect(api.getWatchlist).toHaveBeenCalledWith(
				expect.objectContaining({ page: 2 }),
			)
		})
	})

	describe('moveToWatched action', () => {
		it('should remove item from list and return the movie payload', async () => {
			store.items = [{ id: 1, title: 'Test' }, { id: 2, title: 'Other' }]
			store.total = 2
			store.totalUnfiltered = 2
			const movie = { id: 10, title: 'Test', rating: 8 }
			api.moveToWatched.mockResolvedValue({ data: { movie } })

			const result = await store.moveToWatched(1, { rating: 8 })

			expect(result).toEqual({ movie })
			expect(store.items).toHaveLength(1)
			expect(store.items[0].id).toBe(2)
			expect(store.total).toBe(1)
			expect(store.totalUnfiltered).toBe(1)
		})

		it('should return the series payload when a series is imported', async () => {
			store.items = [{ id: 1, title: 'Show', mediaType: 'series' }]
			store.total = 1
			const series = { id: 42, title: 'Show' }
			api.moveToWatched.mockResolvedValue({ data: { series } })

			const result = await store.moveToWatched(1, { language: 'en-US' })

			expect(result).toEqual({ series })
			expect(result.series.id).toBe(42)
			expect(store.items).toHaveLength(0)
			expect(store.total).toBe(0)
		})

		it('should return null on error', async () => {
			api.moveToWatched.mockRejectedValue(new Error('Move failed'))

			const result = await store.moveToWatched(1, {})

			expect(result).toBeNull()
		})
	})
})
