import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api from '../services/api.js'
import { createSerialFlagSaver } from '../utils/serialFlagSaver.js'
import { useLibrariesStore } from './libraries.js'

/** One favourite saver per store instance (tests create a fresh store each time). */
const favoriteSavers = new WeakMap()

/**
 * The serial favourite saver of a store (#136).
 *
 * @param {object} store - The movies store
 * @return {ReturnType<typeof createSerialFlagSaver>} The saver
 */
function favoriteSaverFor(store) {
	if (!favoriteSavers.has(store)) {
		favoriteSavers.set(store, createSerialFlagSaver({
			// The library of the first click, not the one active when a queued save goes out.
			save: (id, value, libraryId) => {
				const payload = { isFavorite: value }
				return api.updateMovie(id, libraryId !== null ? { ...payload, libraryId } : payload)
			},
			apply: (id, value, libraryId) => {
				// After a switch the list shows another library: leave it alone.
				if (useLibrariesStore().activeLibraryId !== libraryId) { return }
				const i = store.movies.findIndex((x) => x.id === id)
				if (i !== -1) {
					store.movies.splice(i, 1, { ...store.movies[i], isFavorite: value })
				}
				if (store.currentMovie?.id === id) {
					store.currentMovie = { ...store.currentMovie, isFavorite: value }
				}
			},
			onError: (error) => {
				console.error('Failed to change favorite:', error)
				showError(t('moviedb', 'Could not change the favorite. Please try again.'))
			},
		}))
	}
	return favoriteSavers.get(store)
}

/**
 * Movies store - Manages movie collection state and CRUD operations.
 * Handles pagination, filtering, and sorting of watched movies.
 */
// Only the newest fetchOne() may write the current item: a slow answer for an earlier id
// (a quick 5 -> 6 -> 5 navigation) must not overwrite it.
let latestFetchOne = 0

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
		 *
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
				if (this.filters.genre) { params.genre = this.filters.genre }
				if (this.filters.year) { params.year = this.filters.year }
				if (this.filters.platform) { params.platform = this.filters.platform }
				if (this.filters.search) { params.search = this.filters.search }
				if (this.filters.sort) { params.sort = this.filters.sort }
				if (this.filters.dir) { params.dir = this.filters.dir }
				if (this.filters.favorite) { params.favorite = 1 }
				const libraryId = useLibrariesStore().activeLibraryId
				if (libraryId !== null) { params.libraryId = libraryId }

				// Taken before the request: favourites saved meanwhile beat the older answer.
				const favoritesSince = favoriteSaverFor(this).version()
				const response = await api.getMovies(params)
				this.movies = favoriteSaverFor(this).reconcile(response.data.movies, favoritesSince, libraryId, 'isFavorite')
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
		 *
		 * @param {number} id - Movie ID
		 * @return {Promise<object | null>} The movie object or null on error
		 */
		async fetchOne(id) {
			const requestNo = ++latestFetchOne
			this.loading = true
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const response = await api.getMovie(id, libraryId !== null ? libraryId : undefined)
				if (requestNo === latestFetchOne) {
					this.currentMovie = response.data.movie
				}
				return response.data.movie
			} catch (error) {
				console.error('Failed to fetch movie:', error)
				showError(t('moviedb', 'Failed to load movie details.'))
				return null
			} finally {
				if (requestNo === latestFetchOne) {
					this.loading = false
				}
			}
		},

		/**
		 * Creates a new movie entry.
		 *
		 * @param {object} movieData - Movie data to create
		 * @return {Promise<object | null>} The created movie or null on error
		 */
		async create(movieData) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...movieData, libraryId } : movieData
				const response = await api.createMovie(payload)
				this.movies.unshift(response.data.movie)
				this.total++
				showSuccess(t('moviedb', 'Movie added successfully.'))
				return response.data.movie
			} catch (error) {
				if (error.response?.status === 409) {
					// Duplicate movie — let the caller show a contextual dialog
					// instead of a generic toast.
					return {
						duplicate: true,
						existingId: error.response.data?.existingId ?? null,
					}
				}
				console.error('Failed to create movie:', error)
				showError(t('moviedb', 'Failed to add movie. Please try again.'))
				return null
			}
		},

		/**
		 * Updates an existing movie.
		 *
		 * @param {number} id - Movie ID
		 * @param {object} data - Updated movie data
		 * @return {Promise<object | null>} The updated movie or null on error
		 */
		/**
		 * Flips the favourite flag of a movie in the list (#136). The tile updates right
		 * away; saves run one at a time per movie and end on the last click, and a failed
		 * save shows the last saved state again (serialFlagSaver). Only the flag is patched
		 * in place: the update response lacks the list fields (last watch, rating) the tile
		 * shows. With the favourites filter on, an unfavourited tile stays until the next
		 * load, so it doesn't vanish under the cursor.
		 *
		 * @param {number} id - Movie ID
		 * @return {Promise<boolean>} Whether the movie ended on the state clicked last
		 */
		toggleFavorite(id) {
			const item = this.movies.find((x) => x.id === id)
			if (!item) { return Promise.resolve(false) }
			const current = Boolean(item.isFavorite)
			return favoriteSaverFor(this).set(id, !current, current, useLibrariesStore().activeLibraryId)
		},

		async update(id, data) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...data, libraryId } : data
				const response = await api.updateMovie(id, payload)
				const updatedMovie = response.data.movie
				const index = this.movies.findIndex((m) => m.id === updatedMovie.id)
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
		 *
		 * @param {number} id - Movie ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				await api.deleteMovie(id, libraryId !== null ? libraryId : undefined)
				this.movies = this.movies.filter((m) => m.id !== id)
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
		 *
		 * @param {object} filters - New filter values to merge
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			this.page = 1
			this.fetchAll()
		},

		/**
		 * Changes the current page and refreshes the movie list.
		 *
		 * @param {number} page - Page number to navigate to
		 */
		setPage(page) {
			this.page = page
			this.fetchAll()
		},
	},
})
