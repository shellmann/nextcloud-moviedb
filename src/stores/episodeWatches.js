import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

/**
 * Episode watches store - rewatch log for a single episode.
 * Mirrors the movie watches store; the owning series is resolved server-side.
 */
export const useEpisodeWatchesStore = defineStore('episodeWatches', {
	state: () => ({
		/** @type {Array<object>} Watch history for the current episode */
		watches: [],
		/** @type {number|null} Episode ID the watches belong to */
		episodeId: null,
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		async fetchForEpisode(episodeId) {
			this.loading = true
			this.episodeId = episodeId
			try {
				const response = await api.getEpisodeWatches(episodeId)
				this.watches = response.data.watches
			} catch (error) {
				console.error('Failed to fetch episode watch history:', error)
				showError(t('moviedb', 'Failed to load watch history.'))
			} finally {
				this.loading = false
			}
		},

		async create(episodeId, data) {
			try {
				const response = await api.createEpisodeWatch(episodeId, data)
				this.watches.unshift(response.data.watch)
				showSuccess(t('moviedb', 'Watch logged successfully.'))
				return response.data.watch
			} catch (error) {
				console.error('Failed to log episode watch:', error)
				showError(t('moviedb', 'Failed to log watch. Please try again.'))
				return null
			}
		},

		async update(episodeId, watchId, data) {
			try {
				const response = await api.updateEpisodeWatch(episodeId, watchId, data)
				const updated = response.data.watch
				const index = this.watches.findIndex(w => w.id === watchId)
				if (index !== -1) {
					this.watches.splice(index, 1, updated)
				}
				showSuccess(t('moviedb', 'Watch updated successfully.'))
				return updated
			} catch (error) {
				console.error('Failed to update episode watch:', error)
				showError(t('moviedb', 'Failed to update watch. Please try again.'))
				return null
			}
		},

		async delete(episodeId, watchId) {
			try {
				await api.deleteEpisodeWatch(episodeId, watchId)
				this.watches = this.watches.filter(w => w.id !== watchId)
				showSuccess(t('moviedb', 'Watch entry deleted.'))
				return true
			} catch (error) {
				console.error('Failed to delete episode watch:', error)
				showError(t('moviedb', 'Failed to delete watch entry. Please try again.'))
				return false
			}
		},
	},
})
