import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

/**
 * Watchlist store - Manages the user's movie watchlist.
 * Handles adding, updating, removing items and moving them to watched.
 */
export const useWatchlistStore = defineStore('watchlist', {
	state: () => ({
		/** @type {Array<object>} List of watchlist items */
		items: [],
		/** @type {number} Total number of items in watchlist */
		total: 0,
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
		/**
		 * Items filtered by the active media-type filter (client-side; lists are small).
		 * @param {object} state - Store state
		 * @return {Array<object>} Filtered items
		 */
		filteredItems: (state) => {
			if (state.typeFilter === 'all') {
				return state.items
			}
			return state.items.filter(i => (i.mediaType || 'movie') === state.typeFilter)
		},
	},

	actions: {
		/**
		 * Fetches all watchlist items from the API.
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const response = await api.getWatchlist({ sort: this.sort, dir: this.dir })
				this.items = response.data.items
				this.total = response.data.total
			} catch (error) {
				console.error('Failed to fetch watchlist:', error)
				showError(t('moviedb', 'Failed to load watchlist. Please try again.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Sets sort field and direction, then re-fetches.
		 * @param {string} sort - Sort field
		 * @param {string} dir - Sort direction (ASC or DESC)
		 * @return {Promise<void>}
		 */
		async setSort(sort, dir) {
			this.sort = sort
			this.dir = dir
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
		 * Sets the media-type filter ('all' | 'movie' | 'series').
		 * @param {string} type - The type filter to apply
		 */
		setTypeFilter(type) {
			this.typeFilter = type
		},

		/**
		 * Adds a new item to the watchlist.
		 * @param {object} itemData - Watchlist item data
		 * @return {Promise<object | null>} The created item or null on error
		 */
		async create(itemData) {
			try {
				const response = await api.addToWatchlist(itemData)
				this.items.unshift(response.data.item)
				this.total++
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
		 * @param {number} id - Watchlist item ID
		 * @param {object} data - Updated item data
		 * @return {Promise<object | null>} The updated item or null on error
		 */
		async update(id, data) {
			try {
				const response = await api.updateWatchlistItem(id, data)
				const updatedItem = response.data.item
				const index = this.items.findIndex(i => i.id === updatedItem.id)
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
		 * @param {number} id - Watchlist item ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				await api.removeFromWatchlist(id)
				this.items = this.items.filter(i => i.id !== id)
				this.total--
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
		 * @param {number} id - Watchlist item ID
		 * @param {object} watchData - Additional data (date watched, rating, etc.)
		 * @return {Promise<object | null>} `{ movie }` or `{ series }`, or null on error
		 */
		async moveToWatched(id, watchData) {
			try {
				const response = await api.moveToWatched(id, watchData)
				this.items = this.items.filter(i => i.id !== id)
				this.total--
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
