import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api from '../services/api.js'
import { useLibrariesStore } from './libraries.js'

/**
 * Watchlist store - Manages the user's movie watchlist.
 * Handles adding, updating, removing items and moving them to watched.
 */
export const useWatchlistStore = defineStore('watchlist', {
	state: () => ({
		/** @type {Array<object>} List of watchlist items */
		items: [],
		/** @type {number} Total number of items matching current filters */
		total: 0,
		/** @type {number} Total number of items in the library, ignoring filters (for the sidebar badge) */
		totalUnfiltered: 0,
		/** @type {number} Current page number */
		page: 1,
		/** @type {number} Number of items per page */
		limit: 50,
		/** @type {number} Total number of pages */
		totalPages: 0,
		/** @type {boolean} Whether a fetch operation is in progress */
		loading: false,
		/** @type {string} Current sort field */
		sort: 'priority',
		/** @type {string} Current sort direction */
		dir: 'DESC',
		/** @type {string} Type filter: 'all' | 'movie' | 'series' */
		typeFilter: 'all',
	}),

	getters: {
		hasItems: (state) => state.items.length > 0,
	},

	actions: {
		/**
		 * Fetches all watchlist items from the API.
		 *
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const params = {
					sort: this.sort,
					dir: this.dir,
					page: this.page,
					limit: this.limit,
				}
				if (this.typeFilter !== 'all') { params.mediaType = this.typeFilter }
				if (libraryId !== null) { params.libraryId = libraryId }
				const response = await api.getWatchlist(params)
				this.items = response.data.items
				this.total = response.data.total
				this.totalUnfiltered = response.data.totalUnfiltered
				this.page = response.data.page
				this.totalPages = response.data.totalPages
			} catch (error) {
				console.error('Failed to fetch watchlist:', error)
				showError(t('moviedb', 'Failed to load watchlist. Please try again.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Sets sort field and direction, then re-fetches.
		 *
		 * @param {string} sort - Sort field
		 * @param {string} dir - Sort direction (ASC or DESC)
		 * @return {Promise<void>}
		 */
		async setSort(sort, dir) {
			this.sort = sort
			this.dir = dir
			this.page = 1
			await this.fetchAll()
		},

		/**
		 * Resets sort to defaults (priority DESC).
		 */
		resetSort() {
			this.sort = 'priority'
			this.dir = 'DESC'
		},

		/**
		 * Resets sort, type filter, and pagination to defaults. Does not
		 * fetch — callers are expected to call fetchAll() afterward (mirrors
		 * the movies/series stores' resetFilters()).
		 */
		resetFilters() {
			this.sort = 'priority'
			this.dir = 'DESC'
			this.typeFilter = 'all'
			this.page = 1
		},

		/**
		 * Sets the media-type filter ('all' | 'movie' | 'series') and re-fetches.
		 *
		 * @param {string} type - The type filter to apply
		 * @return {Promise<void>}
		 */
		async setTypeFilter(type) {
			this.typeFilter = type
			this.page = 1
			await this.fetchAll()
		},

		/**
		 * Changes the current page and re-fetches.
		 *
		 * @param {number} page - Page number to navigate to
		 * @return {Promise<void>}
		 */
		async setPage(page) {
			this.page = page
			await this.fetchAll()
		},

		/**
		 * Adds a new item to the watchlist.
		 *
		 * @param {object} itemData - Watchlist item data
		 * @return {Promise<object | null>} The created item or null on error
		 */
		async create(itemData) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...itemData, libraryId } : itemData
				const response = await api.addToWatchlist(payload)
				this.items.unshift(response.data.item)
				this.total++
				this.totalUnfiltered++
				if (response.data.alreadyWatched) {
					showSuccess(t('moviedb', 'Added to watchlist. You\'ve seen this one before — this will be logged as a rewatch when you mark it watched.'))
				} else {
					showSuccess(t('moviedb', 'Added to watchlist.'))
				}
				return response.data
			} catch (error) {
				console.error('Failed to add to watchlist:', error)
				if (error.response?.status === 409) {
					showError(t('moviedb', 'This title is already in your watchlist.'))
				} else {
					showError(t('moviedb', 'Failed to add to watchlist. Please try again.'))
				}
				return null
			}
		},

		/**
		 * Updates an existing watchlist item.
		 *
		 * @param {number} id - Watchlist item ID
		 * @param {object} data - Updated item data
		 * @return {Promise<object | null>} The updated item or null on error
		 */
		async update(id, data) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...data, libraryId } : data
				const response = await api.updateWatchlistItem(id, payload)
				const updatedItem = response.data.item
				const index = this.items.findIndex((i) => i.id === updatedItem.id)
				if (index !== -1) {
					this.items.splice(index, 1, updatedItem)
				}
				showSuccess(t('moviedb', 'Watchlist item updated.'))
				return updatedItem
			} catch (error) {
				console.error('Failed to update watchlist item:', error)
				showError(t('moviedb', 'Failed to update watchlist item. Please try again.'))
				return null
			}
		},

		/**
		 * Removes an item from the watchlist.
		 *
		 * @param {number} id - Watchlist item ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				await api.removeFromWatchlist(id, libraryId !== null ? libraryId : undefined)
				this.items = this.items.filter((i) => i.id !== id)
				this.total--
				this.totalUnfiltered--
				showSuccess(t('moviedb', 'Removed from watchlist.'))
				return true
			} catch (error) {
				console.error('Failed to remove from watchlist:', error)
				showError(t('moviedb', 'Failed to remove from watchlist. Please try again.'))
				return false
			}
		},

		/**
		 * Moves a watchlist item off the watchlist: a movie is logged as watched,
		 * a series is imported as a tracked show (at 0% progress).
		 *
		 * @param {number} id - Watchlist item ID
		 * @param {object} watchData - Additional data (date watched, rating, etc.)
		 * @return {Promise<object | null>} `{ movie }` or `{ series }`, or null on error
		 */
		async moveToWatched(id, watchData) {
			try {
				const libraryId = useLibrariesStore().activeLibraryId
				const payload = libraryId !== null ? { ...watchData, libraryId } : watchData
				const response = await api.moveToWatched(id, payload)
				this.items = this.items.filter((i) => i.id !== id)
				this.total--
				this.totalUnfiltered--
				if (response.data.series) {
					showSuccess(t('moviedb', 'Added to your TV shows.'))
				} else {
					showSuccess(t('moviedb', 'Moved to watched movies.'))
				}
				return response.data
			} catch (error) {
				console.error('Failed to move to watched:', error)
				showError(t('moviedb', 'Failed to move to watched. Please try again.'))
				return null
			}
		},
	},
})
