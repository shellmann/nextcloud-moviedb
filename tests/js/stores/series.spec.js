import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useSeriesStore } from '@/stores/series.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getSeries: vi.fn(),
		getSeriesItem: vi.fn(),
		createSeries: vi.fn(),
		updateSeries: vi.fn(),
		deleteSeries: vi.fn(),
		markEpisodeWatched: vi.fn(),
		markSeasonWatched: vi.fn(),
		markSeriesWatched: vi.fn(),
		setSeriesStopped: vi.fn(),
	},
}))

import api from '@/services/api.js'

describe('Series Store', () => {
	let store

	beforeEach(() => {
		localStorage.clear()
		setActivePinia(createPinia())
		store = useSeriesStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should have empty series array', () => {
			expect(store.series).toEqual([])
		})

		it('should have default pagination values', () => {
			expect(store.page).toBe(1)
			expect(store.limit).toBe(24)
			expect(store.total).toBe(0)
		})

		it('should have default filter values (no platform filter)', () => {
			expect(store.filters.sort).toBe('date_watched')
			expect(store.filters.dir).toBe('DESC')
			expect(store.filters.favorite).toBe(false)
			expect(store.filters.genre).toBeNull()
			expect(store.filters.search).toBe('')
			expect('platform' in store.filters).toBe(false)
		})
	})

	describe('getters', () => {
		it('hasSeries reflects the array', () => {
			expect(store.hasSeries).toBe(false)
			store.series = [{ id: 1, title: 'Show' }]
			expect(store.hasSeries).toBe(true)
		})
	})

	describe('fetchAll action', () => {
		it('should update state with fetched series', async () => {
			const mockSeries = [{ id: 1, title: 'A' }, { id: 2, title: 'B' }]
			api.getSeries.mockResolvedValue({
				data: { series: mockSeries, total: 2, page: 1, totalPages: 1 },
			})

			await store.fetchAll()

			expect(store.series).toEqual(mockSeries)
			expect(store.total).toBe(2)
			expect(store.loading).toBe(false)
		})

		it('should include favorite param only when true', async () => {
			api.getSeries.mockResolvedValue({
				data: { series: [], total: 0, page: 1, totalPages: 0 },
			})

			store.filters.favorite = true
			await store.fetchAll()
			expect(api.getSeries).toHaveBeenCalledWith(
				expect.objectContaining({ favorite: 1 }),
			)
		})

		it('should handle errors gracefully', async () => {
			const { showError } = await import('@nextcloud/dialogs')
			api.getSeries.mockRejectedValue(new Error('boom'))

			await store.fetchAll()

			expect(store.loading).toBe(false)
			expect(showError).toHaveBeenCalled()
		})
	})

	describe('create action', () => {
		it('should prepend created series and increment total', async () => {
			const created = { id: 7, title: 'New' }
			api.createSeries.mockResolvedValue({ data: { series: created } })

			const result = await store.create({ title: 'New' })

			expect(result).toEqual(created)
			expect(store.series[0]).toEqual(created)
			expect(store.total).toBe(1)
		})

		it('should return a duplicate marker on 409', async () => {
			api.createSeries.mockRejectedValue({
				response: { status: 409, data: { existingId: 42 } },
			})

			const result = await store.create({ title: 'Dup' })

			expect(result).toEqual({ duplicate: true, existingId: 42 })
			expect(store.series).toHaveLength(0)
		})

		it('should return null on generic error', async () => {
			api.createSeries.mockRejectedValue(new Error('server'))
			const result = await store.create({ title: 'X' })
			expect(result).toBeNull()
		})
	})

	describe('fetchOne action', () => {
		it('sets currentSeries and returns it', async () => {
			const series = { id: 3, title: 'S', progress: 50 }
			api.getSeriesItem.mockResolvedValue({ data: { series } })

			const result = await store.fetchOne(3)

			expect(api.getSeriesItem).toHaveBeenCalledWith(3, undefined)
			expect(store.currentSeries).toEqual(series)
			expect(result).toEqual(series)
		})

		it('returns null on error', async () => {
			api.getSeriesItem.mockRejectedValue(new Error('nope'))
			const result = await store.fetchOne(9)
			expect(result).toBeNull()
		})
	})

	describe('delete action', () => {
		it('removes series and decrements total', async () => {
			store.series = [{ id: 1 }, { id: 2 }]
			store.total = 2
			api.deleteSeries.mockResolvedValue({})

			const result = await store.delete(1)

			expect(result).toBe(true)
			expect(store.series).toHaveLength(1)
			expect(store.series[0].id).toBe(2)
			expect(store.total).toBe(1)
		})

		it('returns false on error', async () => {
			api.deleteSeries.mockRejectedValue(new Error('x'))
			const result = await store.delete(1)
			expect(result).toBe(false)
		})
	})

	describe('mark-watched actions refresh currentSeries from server', () => {
		it('markEpisodeWatched sets currentSeries to server payload', async () => {
			const refreshed = { id: 5, progress: 10, watchedEpisodeCount: 1 }
			api.markEpisodeWatched.mockResolvedValue({ data: { series: refreshed } })

			const result = await store.markEpisodeWatched(5, 101, true)

			expect(api.markEpisodeWatched).toHaveBeenCalledWith(5, 101, true, undefined)
			expect(store.currentSeries).toEqual(refreshed)
			expect(result).toEqual(refreshed)
		})

		it('markEpisodeWatched forwards a false flag to untick', async () => {
			const refreshed = { id: 5, progress: 0, watchedEpisodeCount: 0 }
			api.markEpisodeWatched.mockResolvedValue({ data: { series: refreshed } })

			await store.markEpisodeWatched(5, 101, false)

			expect(api.markEpisodeWatched).toHaveBeenCalledWith(5, 101, false, undefined)
			expect(store.currentSeries).toEqual(refreshed)
		})

		it('markSeasonWatched sets currentSeries to server payload', async () => {
			const refreshed = { id: 5, progress: 40 }
			api.markSeasonWatched.mockResolvedValue({ data: { series: refreshed } })

			await store.markSeasonWatched(5, 2)

			expect(api.markSeasonWatched).toHaveBeenCalledWith(5, 2, true, undefined)
			expect(store.currentSeries).toEqual(refreshed)
		})

		it('markSeriesWatched sets currentSeries to server payload', async () => {
			const refreshed = { id: 5, progress: 100, caughtUp: true }
			api.markSeriesWatched.mockResolvedValue({ data: { series: refreshed } })

			await store.markSeriesWatched(5)

			expect(store.currentSeries).toEqual(refreshed)
		})

		it('returns null and leaves currentSeries untouched on error', async () => {
			store.currentSeries = { id: 5, progress: 0 }
			api.markEpisodeWatched.mockRejectedValue(new Error('fail'))

			const result = await store.markEpisodeWatched(5, 101)

			expect(result).toBeNull()
			expect(store.currentSeries.progress).toBe(0)
		})
	})

	describe('resetFilters', () => {
		it('resets to defaults without a platform key', () => {
			store.filters.genre = 16
			store.filters.search = 'x'
			store.page = 4

			store.resetFilters()

			expect(store.filters.genre).toBeNull()
			expect(store.filters.search).toBe('')
			expect(store.page).toBe(1)
			expect('platform' in store.filters).toBe(false)
		})
	})

	describe('watch status filter', () => {
		it('sends the status filter to the API', async () => {
			api.getSeries.mockResolvedValue({ data: { series: [], total: 0, page: 1, totalPages: 0 } })

			store.setFilters({ watchStatus: 'in_progress' })
			await Promise.resolve()

			expect(api.getSeries.mock.calls[0][0].watchStatus).toBe('in_progress')
		})

		it('omits the status for "all shows"', async () => {
			api.getSeries.mockResolvedValue({ data: { series: [], total: 0, page: 1, totalPages: 0 } })

			store.setFilters({ watchStatus: null })
			await Promise.resolve()

			expect('watchStatus' in api.getSeries.mock.calls[0][0]).toBe(false)
		})

		it('remembers the status across visits and resets', () => {
			api.getSeries.mockResolvedValue({ data: { series: [], total: 0, page: 1, totalPages: 0 } })
			store.setFilters({ watchStatus: 'finished' })

			store.resetFilters()
			expect(store.filters.watchStatus).toBe('finished')

			// A fresh store (new page load) starts with the remembered status too.
			setActivePinia(createPinia())
			expect(useSeriesStore().filters.watchStatus).toBe('finished')
		})

		it('ignores an unknown stored status', () => {
			localStorage.setItem('moviedb_seriesStatusFilter', 'bogus')
			setActivePinia(createPinia())

			expect(useSeriesStore().filters.watchStatus).toBeNull()
		})

		it('forgets the status when switching back to all shows', () => {
			api.getSeries.mockResolvedValue({ data: { series: [], total: 0, page: 1, totalPages: 0 } })
			store.setFilters({ watchStatus: 'stopped' })
			store.setFilters({ watchStatus: null })

			expect(localStorage.getItem('moviedb_seriesStatusFilter')).toBeNull()
		})
	})

	describe('setStopped', () => {
		it('updates the open series with the server payload and shows a toast', async () => {
			store.currentSeries = { id: 5, watchStatus: 'in_progress' }
			api.setSeriesStopped.mockResolvedValue({ data: { series: { id: 5, watchStatus: 'stopped' } } })

			const result = await store.setStopped(5, true)

			expect(api.setSeriesStopped).toHaveBeenCalledWith(5, true, undefined)
			expect(store.currentSeries.watchStatus).toBe('stopped')
			expect(result.watchStatus).toBe('stopped')
			expect(showSuccess).toHaveBeenCalled()
		})

		it('leaves another open series alone and can skip the toast', async () => {
			store.currentSeries = { id: 9 }
			api.setSeriesStopped.mockResolvedValue({ data: { series: { id: 5 } } })

			await store.setStopped(5, false, { toast: false })

			expect(store.currentSeries).toEqual({ id: 9 })
			expect(showSuccess).not.toHaveBeenCalled()
		})

		it('returns null and shows an error on failure', async () => {
			api.setSeriesStopped.mockRejectedValue(new Error('nope'))
			vi.spyOn(console, 'error').mockImplementation(() => {})

			expect(await store.setStopped(5, true)).toBeNull()
			expect(showError).toHaveBeenCalled()
		})
	})
})
