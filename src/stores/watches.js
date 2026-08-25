import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

export const useWatchesStore = defineStore('watches', {
	state: () => ({
		/** @type {Array<object>} Watch history for the current movie */
		watches: [],
		/** @type {number|null} Movie ID the watches belong to */
		movieId: null,
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		async fetchForMovie(movieId) {
			this.loading = true
			this.movieId = movieId
			try {
				const response = await api.getWatches(movieId)
				this.watches = response.data.watches
			} catch (error) {
				console.error('Failed to fetch watch history:', error)
				showError(t('moviedb', 'Failed to load watch history.'))
			} finally {
				this.loading = false
			}
		},

		async create(movieId, data) {
			try {
				const response = await api.createWatch(movieId, data)
				this.watches.unshift(response.data.watch)
				showSuccess(t('moviedb', 'Watch logged successfully.'))
				return response.data.watch
			} catch (error) {
				console.error('Failed to log watch:', error)
				showError(t('moviedb', 'Failed to log watch. Please try again.'))
				return null
			}
		},

		async update(movieId, watchId, data) {
			try {
				const response = await api.updateWatch(movieId, watchId, data)
				const updated = response.data.watch
				const index = this.watches.findIndex(w => w.id === watchId)
				if (index !== -1) {
					this.watches.splice(index, 1, updated)
				}
				showSuccess(t('moviedb', 'Watch updated successfully.'))
				return updated
			} catch (error) {
				console.error('Failed to update watch:', error)
				showError(t('moviedb', 'Failed to update watch. Please try again.'))
				return null
			}
		},

		async delete(movieId, watchId) {
			try {
				await api.deleteWatch(movieId, watchId)
				this.watches = this.watches.filter(w => w.id !== watchId)
				showSuccess(t('moviedb', 'Watch entry deleted.'))
				return true
			} catch (error) {
				console.error('Failed to delete watch:', error)
				showError(t('moviedb', 'Failed to delete watch entry. Please try again.'))
				return false
			}
		},
	},
})
