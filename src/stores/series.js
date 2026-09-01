import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'
import { useLibrariesStore } from './libraries.js'

/**
 * Series store - Manages TV series collection state and CRUD operations.
 * Mirrors the movies store; mark-watched actions re-fetch the detail view so
 * derived progress (season/series aggregates) stays authoritative from the server.
 */
export const useSeriesStore = defineStore('series', {
	state: () => ({
		/** @type {Array<object>} List of series for current page */
		series: [],
		/** @type {object | null} Currently selected series (with progress) */
		currentSeries: null,
		/** @type {number} Total number of series matching current filters */
		total: 0,
		/** @type {number} Current page number */
		page: 1,
		/** @type {number} Number of series per page */
		limit: 24,
		/** @type {number} Total number of pages */
		totalPages: 0,
		/** @type {boolean} Whether a fetch operation is in progress */
		loading: false,
		/** @type {object} Active filter criteria */
		filters: {
			genre: null,
			year: null,
			search: '',
			sort: 'date_watched',
			dir: 'DESC',
			favorite: false,
		},
	}),

	getters: {
		hasSeries: (state) => state.series.length > 0,
	},

	actions: {
		/**
		 * Fetches series from the API with current filters and pagination.
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const params = {
					page: this.page,
					limit: this.limit,
				}
				if (this.filters.genre) params.genre = this.filters.genre
				if (this.filters.year) params.year = this.filters.year
				if (this.filters.search) params.search = this.filters.search
				if (this.filters.sort) params.sort = this.filters.sort
				if (this.filters.dir) params.dir = this.filters.dir
				if (this.filters.favorite) params.favorite = 1
				const libraryId = useLibrariesStore().activeLibraryId
				if (libraryId !== null) params.libraryId = libraryId

				const response = await api.getSeries(params)
				this.series = response.data.series
				this.total = response.data.total
				this.page = response.data.page
				this.totalPages = response.data.totalPages
			} catch (error) {
				console.error('Failed to fetch series:', error)
				showError(t('moviedb', 'Failed to load series. Please try again.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetches a single series (with progress) by ID.
		 * @param {number} id - Series ID
		 * @return {Promise<object | null>} The series object or null on error
		 */
		async fetchOne(id) {
			this.loading = true
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const response = await api.getSeriesItem(id, libraryId !== null ? libraryId : undefined)
				this.currentSeries = response.data.series
				return response.data.series
			} catch (error) {
				console.error('Failed to fetch series:', error)
				showError(t('moviedb', 'Failed to load series details.'))
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Creates a new series entry from TMDB data (fetches episodes server-side).
		 * @param {object} seriesData - Series data to create
		 * @return {Promise<object | null>} The created series, a duplicate marker, or null
		 */
		async create(seriesData) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...seriesData, libraryId } : seriesData
				const response = await api.createSeries(payload)
				this.series.unshift(response.data.series)
				this.total++
				showSuccess(t('moviedb', 'Series added successfully.'))
				return response.data.series
			} catch (error) {
				if (error.response?.status === 409) {
					return {
						duplicate: true,
						existingId: error.response.data?.existingId ?? null,
					}
				}
				console.error('Failed to create series:', error)
				showError(t('moviedb', 'Failed to add series. Please try again.'))
				return null
			}
		},

		/**
		 * Updates an existing series.
		 * @param {number} id - Series ID
		 * @param {object} data - Updated series data
		 * @return {Promise<object | null>} The updated series or null on error
		 */
		async update(id, data) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...data, libraryId } : data
				const response = await api.updateSeries(id, payload)
				const updated = response.data.series
				const index = this.series.findIndex(s => s.id === updated.id)
				if (index !== -1) {
					this.series.splice(index, 1, updated)
				}
				if (this.currentSeries?.id === updated.id) {
					this.currentSeries = { ...this.currentSeries, ...updated }
				}
				showSuccess(t('moviedb', 'Series updated successfully.'))
				return updated
			} catch (error) {
				console.error('Failed to update series:', error)
				showError(t('moviedb', 'Failed to update series. Please try again.'))
				return null
			}
		},

		/**
		 * Deletes a series by ID (cascades episodes + watch rows server-side).
		 * @param {number} id - Series ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				await api.deleteSeries(id, libraryId !== null ? libraryId : undefined)
				this.series = this.series.filter(s => s.id !== id)
				this.total--
				showSuccess(t('moviedb', 'Series deleted successfully.'))
				return true
			} catch (error) {
				console.error('Failed to delete series:', error)
				showError(t('moviedb', 'Failed to delete series. Please try again.'))
				return false
			}
		},

		/**
		 * Toggles a single episode's watched flag; refreshes currentSeries with server progress.
		 * @param {number} id - Series ID
		 * @param {number} episodeId - Episode ID
		 * @param {boolean} watched - Watched state to set (default true)
		 * @return {Promise<object | null>} The refreshed series or null on error
		 */
		async markEpisodeWatched(id, episodeId, watched = true) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const response = await api.markEpisodeWatched(id, episodeId, watched, libraryId !== null ? libraryId : undefined)
				this.currentSeries = response.data.series
				return response.data.series
			} catch (error) {
				console.error('Failed to mark episode watched:', error)
				showError(t('moviedb', 'Failed to mark episode watched. Please try again.'))
				return null
			}
		},

		/**
		 * Marks all aired episodes of a season watched/unwatched.
		 * @param {number} id - Series ID
		 * @param {number} seasonNumber - Season number
		 * @param {boolean} watched - Watched state to set (default true)
		 * @return {Promise<object | null>} The refreshed series or null on error
		 */
		async markSeasonWatched(id, seasonNumber, watched = true) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const response = await api.markSeasonWatched(id, seasonNumber, watched, libraryId !== null ? libraryId : undefined)
				this.currentSeries = response.data.series
				showSuccess(watched
					? t('moviedb', 'Season marked as watched.')
					: t('moviedb', 'Season marked as unwatched.'))
				return response.data.series
			} catch (error) {
				console.error('Failed to mark season watched:', error)
				showError(t('moviedb', 'Failed to mark season watched. Please try again.'))
				return null
			}
		},

		/**
		 * Marks all aired episodes of the series watched/unwatched (excludes specials).
		 * @param {number} id - Series ID
		 * @param {boolean} watched - Watched state to set (default true)
		 * @return {Promise<object | null>} The refreshed series or null on error
		 */
		async markSeriesWatched(id, watched = true) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const response = await api.markSeriesWatched(id, watched, libraryId !== null ? libraryId : undefined)
				this.currentSeries = response.data.series
				showSuccess(watched
					? t('moviedb', 'Series marked as watched.')
					: t('moviedb', 'Series marked as unwatched.'))
				return response.data.series
			} catch (error) {
				console.error('Failed to mark series watched:', error)
				showError(t('moviedb', 'Failed to mark series watched. Please try again.'))
				return null
			}
		},

		/**
		 * Resets all filter criteria to their defaults.
		 */
		resetFilters() {
			this.filters = {
				genre: null,
				year: null,
				search: '',
				sort: 'date_watched',
				dir: 'DESC',
				favorite: false,
			}
			this.page = 1
		},

		/**
		 * Updates filter criteria and refreshes the series list.
		 * @param {object} filters - New filter values to merge
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			this.page = 1
			this.fetchAll()
		},

		/**
		 * Changes the current page and refreshes the series list.
		 * @param {number} page - Page number to navigate to
		 */
		setPage(page) {
			this.page = page
			this.fetchAll()
		},
	},
})
