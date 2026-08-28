import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useEpisodeWatchesStore } from '@/stores/episodeWatches.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getEpisodeWatches: vi.fn(),
		createEpisodeWatch: vi.fn(),
		updateEpisodeWatch: vi.fn(),
		deleteEpisodeWatch: vi.fn(),
	},
}))

import api from '@/services/api.js'

describe('Episode Watches Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEpisodeWatchesStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should start empty', () => {
			expect(store.watches).toEqual([])
			expect(store.episodeId).toBeNull()
			expect(store.loading).toBe(false)
		})
	})

	describe('fetchForEpisode', () => {
		it('loads watches and records the episode id', async () => {
			const watches = [
				{ id: 1, episodeId: 100, watchedAt: '2026-08-01' },
				{ id: 2, episodeId: 100, watchedAt: '2025-12-20' },
			]
			api.getEpisodeWatches.mockResolvedValue({ data: { watches } })

			await store.fetchForEpisode(100)

			expect(api.getEpisodeWatches).toHaveBeenCalledWith(100)
			expect(store.watches).toEqual(watches)
			expect(store.episodeId).toBe(100)
			expect(store.loading).toBe(false)
		})

		it('handles errors without throwing', async () => {
			api.getEpisodeWatches.mockRejectedValue(new Error('net'))
			await store.fetchForEpisode(1)
			expect(store.watches).toEqual([])
			expect(store.loading).toBe(false)
		})
	})

	describe('create', () => {
		it('prepends the new watch', async () => {
			const created = { id: 3, episodeId: 100, watchedAt: '2026-08-24', rating: 9 }
			api.createEpisodeWatch.mockResolvedValue({ data: { watch: created } })
			store.watches = [{ id: 1, episodeId: 100 }]

			const result = await store.create(100, { rating: 9 })

			expect(api.createEpisodeWatch).toHaveBeenCalledWith(100, { rating: 9 })
			expect(result).toEqual(created)
			expect(store.watches[0]).toEqual(created)
			expect(store.watches).toHaveLength(2)
		})

		it('returns null on failure', async () => {
			api.createEpisodeWatch.mockRejectedValue(new Error('x'))
			const result = await store.create(100, {})
			expect(result).toBeNull()
		})
	})

	describe('update', () => {
		it('replaces the matching watch', async () => {
			store.watches = [{ id: 1, episodeId: 100, rating: 5 }]
			const updated = { id: 1, episodeId: 100, rating: 8 }
			api.updateEpisodeWatch.mockResolvedValue({ data: { watch: updated } })

			await store.update(100, 1, { rating: 8 })

			expect(store.watches[0].rating).toBe(8)
		})
	})

	describe('delete', () => {
		it('removes the watch and returns true', async () => {
			store.watches = [{ id: 1 }, { id: 2 }]
			api.deleteEpisodeWatch.mockResolvedValue({})

			const result = await store.delete(100, 1)

			expect(result).toBe(true)
			expect(store.watches).toHaveLength(1)
			expect(store.watches[0].id).toBe(2)
		})

		it('returns false on error', async () => {
			api.deleteEpisodeWatch.mockRejectedValue(new Error('x'))
			const result = await store.delete(100, 1)
			expect(result).toBe(false)
		})
	})
})
