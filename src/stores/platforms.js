import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

/**
 * Platforms store - Manages streaming platform configuration.
 * Handles both default and custom platforms for categorizing where movies were watched.
 */
export const usePlatformsStore = defineStore('platforms', {
	state: () => ({
		/** @type {Array<object>} List of all platforms */
		platforms: [],
		/** @type {boolean} Whether a fetch operation is in progress */
		loading: false,
	}),

	getters: {
		/**
		 * @param state
		 * @return {Array<object>} Built-in default platforms
		 */
		defaultPlatforms: (state) => state.platforms.filter(p => p.isDefault),
		/**
		 * @param state
		 * @return {Array<object>} User-created custom platforms
		 */
		customPlatforms: (state) => state.platforms.filter(p => !p.isDefault),
		/**
		 * @param state
		 * @return {function(number): object | undefined} Find platform by ID
		 */
		getPlatformById: (state) => (id) => state.platforms.find(p => p.id === id),
	},

	actions: {
		/**
		 * Fetches all platforms from the API.
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const response = await api.getPlatforms()
				this.platforms = response.data.platforms
			} catch (error) {
				console.error('Failed to fetch platforms:', error)
				showError(t('moviedb', 'Failed to load platforms.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Creates a new custom platform.
		 * @param {object} data - Platform data (name required)
		 * @return {Promise<object | null>} The created platform or null on error
		 */
		async create(data) {
			try {
				const response = await api.createPlatform(data)
				this.platforms.push(response.data.platform)
				showSuccess(t('moviedb', 'Platform created successfully.'))
				return response.data.platform
			} catch (error) {
				console.error('Failed to create platform:', error)
				showError(t('moviedb', 'Failed to create platform. Please try again.'))
				return null
			}
		},

		/**
		 * Updates an existing platform.
		 * @param {number} id - Platform ID
		 * @param {object} data - Updated platform data
		 * @return {Promise<object | null>} The updated platform or null on error
		 */
		async update(id, data) {
			try {
				const response = await api.updatePlatform(id, data)
				const updatedPlatform = response.data.platform
				const index = this.platforms.findIndex(p => p.id === updatedPlatform.id)
				if (index !== -1) {
					this.platforms.splice(index, 1, updatedPlatform)
				}
				showSuccess(t('moviedb', 'Platform updated successfully.'))
				return updatedPlatform
			} catch (error) {
				console.error('Failed to update platform:', error)
				showError(t('moviedb', 'Failed to update platform. Please try again.'))
				return null
			}
		},

		/**
		 * Deletes a custom platform.
		 * @param {number} id - Platform ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async delete(id) {
			try {
				await api.deletePlatform(id)
				this.platforms = this.platforms.filter(p => p.id !== id)
				showSuccess(t('moviedb', 'Platform deleted successfully.'))
				return true
			} catch (error) {
				console.error('Failed to delete platform:', error)
				showError(t('moviedb', 'Failed to delete platform. Please try again.'))
				return false
			}
		},
	},
})
