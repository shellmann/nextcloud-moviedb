import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useWatchesStore } from '@/stores/watches.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getWatches: vi.fn(),
		createWatch: vi.fn(),
		updateWatch: vi.fn(),
		deleteWatch: vi.fn(),
	},
}))

import api from '@/services/api.js'

describe('Watches Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWatchesStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should have empty watches array', () => {
			expect(store.watches).toEqual([])
		})

		it('should have loading set to false', () => {
			expect(store.loading).toBe(false)
		})

		it('should have null movieId', () => {
			expect(store.movieId).toBeNull()
		})
	})

	describe('fetchForMovie', () => {
		it('should load watches and set movieId', async () => {
			const watches = [
				{ id: 1, movieId: 42, watchedAt: '2026-08-01', rating: 8 },
				{ id: 2, movieId: 42, watchedAt: '2025-12-20', rating: 7 },
			]
			api.getWatches.mockResolvedValue({ data: { watches } })

			await store.fetchForMovie(42)

			expect(api.getWatches).toHaveBeenCalledWith(42, undefined)
			expect(store.watches).toEqual(watches)
			expect(store.movieId).toBe(42)
			expect(store.loading).toBe(false)
		})

		it('should show error on failure', async () => {
			api.getWatches.mockRejectedValue(new Error('Network error'))

			await store.fetchForMovie(1)

			expect(store.watches).toEqual([])
		})
	})

	describe('create', () => {
		it('should prepend new watch to list', async () => {
			const newWatch = { id: 3, movieId: 42, watchedAt: '2026-08-24', rating: 9 }
			api.createWatch.mockResolvedValue({ data: { watch: newWatch } })
			store.watches = [{ id: 1, movieId: 42, watchedAt: '2025-01-01', rating: 7 }]

			const result = await store.create(42, { watchedAt: '2026-08-24', rating: 9 })

			expect(result).toEqual(newWatch)
			expect(store.watches[0]).toEqual(newWatch)
			expect(store.watches).toHaveLength(2)
		})

		it('should return null on failure', async () => {
			api.createWatch.mockRejectedValue(new Error('Server error'))

			const result = await store.create(42, {})

			expect(result).toBeNull()
		})
	})

	describe('update', () => {
		it('should replace the updated watch in the list', async () => {
			const original = { id: 1, movieId: 42, watchedAt: '2026-01-01', rating: 5 }
			const updated = { id: 1, movieId: 42, watchedAt: '2026-01-01', rating: 8 }
			store.watches = [original]
			api.updateWatch.mockResolvedValue({ data: { watch: updated } })

			await store.update(42, 1, { rating: 8 })

			expect(store.watches[0].rating).toBe(8)
		})
	})

	describe('delete', () => {
		it('should remove the deleted watch from the list', async () => {
			store.watches = [
				{ id: 1, movieId: 42, watchedAt: '2026-01-01' },
				{ id: 2, movieId: 42, watchedAt: '2025-01-01' },
			]
			api.deleteWatch.mockResolvedValue({})

			const result = await store.delete(42, 1)

			expect(result).toBe(true)
			expect(store.watches).toHaveLength(1)
			expect(store.watches[0].id).toBe(2)
		})
	})
})
