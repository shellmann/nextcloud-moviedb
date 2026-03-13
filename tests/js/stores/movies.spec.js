import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useMoviesStore } from '@/stores/movies.js'

// Mock API module
vi.mock('@/services/api.js', () => ({
	default: {
		getMovies: vi.fn(),
		getMovie: vi.fn(),
		createMovie: vi.fn(),
		updateMovie: vi.fn(),
		deleteMovie: vi.fn(),
	},
}))

import api from '@/services/api.js'

describe('Movies Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useMoviesStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should have empty movies array', () => {
			expect(store.movies).toEqual([])
		})

		it('should have loading set to false', () => {
			expect(store.loading).toBe(false)
		})

		it('should have default pagination values', () => {
			expect(store.page).toBe(1)
			expect(store.limit).toBe(24)
			expect(store.total).toBe(0)
		})

		it('should have default filter values', () => {
			expect(store.filters.sort).toBe('date_watched')
			expect(store.filters.dir).toBe('DESC')
		})
	})

	describe('getters', () => {
		it('hasMovies should return false when movies array is empty', () => {
			expect(store.hasMovies).toBe(false)
		})

		it('hasMovies should return true when movies exist', () => {
			store.movies = [{ id: 1, title: 'Test Movie' }]
			expect(store.hasMovies).toBe(true)
		})
	})

	describe('fetchAll action', () => {
		it('should set loading to true while fetching', async () => {
			api.getMovies.mockResolvedValue({
				data: { movies: [], total: 0, page: 1, totalPages: 0 },
			})

			const fetchPromise = store.fetchAll()
			expect(store.loading).toBe(true)

			await fetchPromise
			expect(store.loading).toBe(false)
		})

		it('should update state with fetched movies', async () => {
			const mockMovies = [
				{ id: 1, title: 'Movie 1' },
				{ id: 2, title: 'Movie 2' },
			]
			api.getMovies.mockResolvedValue({
				data: { movies: mockMovies, total: 2, page: 1, totalPages: 1 },
			})

			await store.fetchAll()

			expect(store.movies).toEqual(mockMovies)
			expect(store.total).toBe(2)
			expect(store.totalPages).toBe(1)
		})

		it('should include filters in API call', async () => {
			api.getMovies.mockResolvedValue({
				data: { movies: [], total: 0, page: 1, totalPages: 0 },
			})

			store.filters.genre = 28
			store.filters.year = 2024
			await store.fetchAll()

			expect(api.getMovies).toHaveBeenCalledWith(
				expect.objectContaining({
					genre: 28,
					year: 2024,
				}),
			)
		})

		it('should handle API errors gracefully', async () => {
			const { showError } = await import('@nextcloud/dialogs')
			api.getMovies.mockRejectedValue(new Error('Network error'))

			await store.fetchAll()

			expect(store.loading).toBe(false)
			expect(showError).toHaveBeenCalled()
		})
	})

	describe('create action', () => {
		it('should add new movie to the beginning of the list', async () => {
			const newMovie = { id: 1, title: 'New Movie' }
			api.createMovie.mockResolvedValue({ data: { movie: newMovie } })

			const result = await store.create({ title: 'New Movie' })

			expect(result).toEqual(newMovie)
			expect(store.movies[0]).toEqual(newMovie)
			expect(store.total).toBe(1)
		})

		it('should show success message on create', async () => {
			const { showSuccess } = await import('@nextcloud/dialogs')
			api.createMovie.mockResolvedValue({ data: { movie: { id: 1 } } })

			await store.create({ title: 'Test' })

			expect(showSuccess).toHaveBeenCalled()
		})

		it('should return null on error', async () => {
			api.createMovie.mockRejectedValue(new Error('Create failed'))

			const result = await store.create({ title: 'Test' })

			expect(result).toBeNull()
		})
	})

	describe('update action', () => {
		it('should update movie in the list', async () => {
			store.movies = [{ id: 1, title: 'Old Title' }]
			const updatedMovie = { id: 1, title: 'New Title' }
			api.updateMovie.mockResolvedValue({ data: { movie: updatedMovie } })

			await store.update(1, { title: 'New Title' })

			expect(store.movies[0].title).toBe('New Title')
		})

		it('should update currentMovie if it matches', async () => {
			store.currentMovie = { id: 1, title: 'Old Title' }
			const updatedMovie = { id: 1, title: 'New Title' }
			api.updateMovie.mockResolvedValue({ data: { movie: updatedMovie } })

			await store.update(1, { title: 'New Title' })

			expect(store.currentMovie.title).toBe('New Title')
		})
	})

	describe('delete action', () => {
		it('should remove movie from the list', async () => {
			store.movies = [{ id: 1 }, { id: 2 }]
			store.total = 2
			api.deleteMovie.mockResolvedValue({})

			await store.delete(1)

			expect(store.movies).toHaveLength(1)
			expect(store.movies[0].id).toBe(2)
			expect(store.total).toBe(1)
		})

		it('should return true on success', async () => {
			api.deleteMovie.mockResolvedValue({})

			const result = await store.delete(1)

			expect(result).toBe(true)
		})

		it('should return false on error', async () => {
			api.deleteMovie.mockRejectedValue(new Error('Delete failed'))

			const result = await store.delete(1)

			expect(result).toBe(false)
		})
	})

	describe('setFilters action', () => {
		it('should merge new filters with existing', async () => {
			api.getMovies.mockResolvedValue({
				data: { movies: [], total: 0, page: 1, totalPages: 0 },
			})

			store.filters.genre = 28
			store.setFilters({ year: 2024 })

			expect(store.filters.genre).toBe(28)
			expect(store.filters.year).toBe(2024)
		})

		it('should reset page to 1', async () => {
			api.getMovies.mockResolvedValue({
				data: { movies: [], total: 0, page: 1, totalPages: 0 },
			})

			store.page = 5
			store.setFilters({ genre: 28 })

			expect(store.page).toBe(1)
		})
	})
})
