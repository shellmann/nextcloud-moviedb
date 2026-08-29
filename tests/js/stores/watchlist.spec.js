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

		it('filteredItems returns all items when typeFilter is "all"', () => {
			store.items = [
				{ id: 1, mediaType: 'movie' },
				{ id: 2, mediaType: 'series' },
			]
			store.typeFilter = 'all'
			expect(store.filteredItems).toHaveLength(2)
		})

		it('filteredItems returns only movies when typeFilter is "movie"', () => {
			store.items = [
				{ id: 1, mediaType: 'movie' },
				{ id: 2, mediaType: 'series' },
				{ id: 3 }, // legacy row without mediaType → treated as movie
			]
			store.typeFilter = 'movie'
			const ids = store.filteredItems.map(i => i.id)
			expect(ids).toEqual([1, 3])
		})

		it('filteredItems returns only series when typeFilter is "series"', () => {
			store.items = [
				{ id: 1, mediaType: 'movie' },
				{ id: 2, mediaType: 'series' },
			]
			store.typeFilter = 'series'
			expect(store.filteredItems.map(i => i.id)).toEqual([2])
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
				data: { items: mockItems, total: 2 },
			})

			await store.fetchAll()

			expect(store.items).toEqual(mockItems)
			expect(store.total).toBe(2)
		})

		it('should pass sort and dir to API call', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0 },
			})

			store.sort = 'added_at'
			store.dir = 'ASC'
			await store.fetchAll()

			expect(api.getWatchlist).toHaveBeenCalledWith({
				sort: 'added_at',
				dir: 'ASC',
			})
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
				data: { items: [], total: 0 },
			})

			await store.setSort('added_at', 'ASC')

			expect(store.sort).toBe('added_at')
			expect(store.dir).toBe('ASC')
		})

		it('should trigger a fetch', async () => {
			api.getWatchlist.mockResolvedValue({
				data: { items: [], total: 0 },
			})

			await store.setSort('title', 'DESC')

			expect(api.getWatchlist).toHaveBeenCalledWith({
				sort: 'title',
				dir: 'DESC',
			})
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

	describe('create action', () => {
		it('should add new item to the beginning of the list', async () => {
			const newItem = { id: 1, title: 'New Movie' }
			api.addToWatchlist.mockResolvedValue({ data: { item: newItem, alreadyWatched: false } })

			const result = await store.create({ title: 'New Movie' })

			expect(result).toEqual({ item: newItem, alreadyWatched: false })
			expect(store.items[0]).toEqual(newItem)
			expect(store.total).toBe(1)
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
			api.removeFromWatchlist.mockResolvedValue({})

			await store.delete(1)

			expect(store.items).toHaveLength(1)
			expect(store.items[0].id).toBe(2)
			expect(store.total).toBe(1)
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
		it('should set the type filter', () => {
			store.setTypeFilter('series')
			expect(store.typeFilter).toBe('series')
		})
	})

	describe('moveToWatched action', () => {
		it('should remove item from list and return the movie payload', async () => {
			store.items = [{ id: 1, title: 'Test' }, { id: 2, title: 'Other' }]
			store.total = 2
			const movie = { id: 10, title: 'Test', rating: 8 }
			api.moveToWatched.mockResolvedValue({ data: { movie } })

			const result = await store.moveToWatched(1, { rating: 8 })

			expect(result).toEqual({ movie })
			expect(store.items).toHaveLength(1)
			expect(store.items[0].id).toBe(2)
			expect(store.total).toBe(1)
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
