import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api from '../services/api.js'

/**
 * Settings store - Manages user application settings.
 * Handles TMDB API key configuration and language preferences.
 */
export const useSettingsStore = defineStore('settings', {
	state: () => ({
		/** @type {boolean} Whether the TMDB API key is configured */
		hasApiKey: false,
		/** @type {string} Default language for TMDB metadata (BCP 47 format) */
		defaultLanguage: 'de-DE',
		/** @type {string} Application UI language preference ('auto' or locale code) */
		appLanguage: 'auto',
		/** @type {boolean} Whether a fetch operation is in progress */
		loading: false,
	}),

	actions: {
		/**
		 * Fetches current settings from the API.
		 *
		 * @return {Promise<void>}
		 */
		async fetch() {
			this.loading = true
			try {
				const response = await api.getSettings()
				this.hasApiKey = response.data.hasApiKey
				this.defaultLanguage = response.data.defaultLanguage || 'de-DE'
				this.appLanguage = response.data.appLanguage || 'auto'
			} catch (error) {
				console.error('Failed to fetch settings:', error)
				showError(t('moviedb', 'Failed to load settings.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Updates user settings. Callers show the success/error message,
		 * so they can word it for the action (save vs. remove key).
		 *
		 * @param {object} data - Settings to update
		 * @param {string} [data.tmdbApiKey] - TMDB API key
		 * @param {string} [data.defaultLanguage] - Default TMDB language
		 * @param {string} [data.appLanguage] - App UI language
		 * @return {Promise<void>}
		 * @throws {Error} When the API request fails
		 */
		async update(data) {
			await api.updateSettings(data)
			await this.fetch()
		},
	},
})
