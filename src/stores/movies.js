import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

/**
 * Movies store - Manages movie collection state and CRUD operations.
 * Handles pagination, filtering, and sorting of watched movies.
 */
export const useMoviesStore = defineStore('movies', {
	state: () => ({
		/** @type {Array<object>} List of movies for current page */
		movies: [],
		/** @type {object | null} Currently selected movie for detail view */
		currentMovie: null,
		/** @type {number} Total number of movies matching current filters */
		total: 0,
		/** @type {number} Current page number */
		page: 1,
		/** @type {number} Number of movies per page */
		limit: 24,
		/** @type {number} Total number of pages */
		totalPages: 0,
		/** @type {boolean} Whether a fetch operation is in progress */
		loading: false,
		/** @type {object} Active filter criteria */
		filters: {
			genre: null,
			year: null,
			platform: null,
			search: '',
			sort: 'date_watched',
			dir: 'DESC',
			favorite: false,
		},
	}),

	getters: {
		hasMovies: (state) => state.movies.length > 0,
	},

	actions: {
		/**
		 * Fetches movies from the API with current filters and pagination.
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				// Clean up filters - only include non-null/non-empty values
				const params = {
					page: this.page,
					limit: this.limit,
				}
				if (this.filters.genre) params.genre = this.filters.genre
				if (this.filters.year) params.year = this.filters.year
				if (this.filters.platform) params.platform = this.filters.platform
				if (this.filters.search) params.search = this.filters.search
				if (this.filters.sort) params.sort = this.filters.sort
				if (this.filters.dir) params.dir = this.filters.dir
				if (this.filters.favorite) params.favorite = 1

				const response = await api.getMovies(params)
				this.movies = response.data.movies
				this.total = response.data.total
				this.page = response.data.page
				this.totalPages = response.data.totalPages
			} catch (error) {
				console.error('Failed to fetch movies:', error)
				showError(t('moviedb', 'Failed to load movies. Please try again.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetches a single movie by ID.
		 * @param {number} id - Movie ID
		 * @return {Promise<object | null>} The movie object or null on error
		 */
		async fetchOne(id) {
			this.loading = true
			try {
				const response = await api.getMovie(id)
				this.currentMovie = response.data.movie
				return response.data.movie
			} catch (error) {
				console.error('Failed to fetch movie:', error)
				showError(t('moviedb', 'Failed to load movie details.'))
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Creates a new movie entry.
		 * @param {object} movieData - Movie data to create
		 * @return {Promise<object | null>} The created movie or null on error
		 */
		async create(movieData) {
			try {
				const response = await api.createMovie(movieData)
				this.movies.unshift(response.data.movie)
				this.total++
				showSuccess(t('moviedb', 'Movie added successfully.'))
				return response.data.movie
			} catch (error) {
				console.error('Failed to create movie:', error)
				showError(t('moviedb', 'Failed to add movie. Please try again.'))
				return null
			}
		},

		/**
		 * Updates an existing movie.
		 * @param {number} id - Movie ID
		 * @param {object} data - Updated movie data
		 * @return {Promise<object | null>} The updated movie or null on error
		 */
		async update(id, data) {
			try {
				const response = await api.updateMovie(id, data)
				const updatedMovie = response.data.movie
				const index = this.movies.findIndex(m => m.id === updatedMovie.id)
				if (index !== -1) {
					this.movies.splice(index, 1, updatedMovie)
				}
				if (this.currentMovie?.id === updatedMovie.id) {
					this.currentMovie = updatedMovie
				}
				showSuccess(t('moviedb', 'Movie updated successfully.'))
				return updatedMovie
			} catch (error) {
				console.error('Failed to update movie:', error)
				showError(t('moviedb', 'Failed to update movie. Please try again.'))
				return null
			}
		},

		/**
		 * Deletes a movie by ID.
		 * @param {number} id - Movie ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				await api.deleteMovie(id)
				this.movies = this.movies.filter(m => m.id !== id)
				this.total--
				showSuccess(t('moviedb', 'Movie deleted successfully.'))
				return true
			} catch (error) {
				console.error('Failed to delete movie:', error)
				showError(t('moviedb', 'Failed to delete movie. Please try again.'))
				return false
			}
		},

		/**
		 * Resets all filter criteria to their defaults.
		 */
		resetFilters() {
			this.filters = {
				genre: null,
				year: null,
				platform: null,
				search: '',
				sort: 'date_watched',
				dir: 'DESC',
				favorite: false,
			}
			this.page = 1
		},

		/**
		 * Updates filter criteria and refreshes the movie list.
		 * @param {object} filters - New filter values to merge
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			this.page = 1
			this.fetchAll()
		},

		/**
		 * Changes the current page and refreshes the movie list.
		 * @param {number} page - Page number to navigate to
		 */
		setPage(page) {
			this.page = page
			this.fetchAll()
		},
	},
})
