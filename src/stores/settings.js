import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api from '../services/api.js'

/**
 * Picks the store fields from a settings payload (GET /api/settings or the
 * initial state sent with the page), with defaults for missing fields.
 *
 * @param {object} data - Settings payload from the server
 * @return {object} Store state fields
 */
function fromServer(data) {
	return {
		/** @type {boolean} Whether any TMDB key is usable (personal or instance-wide) */
		hasApiKey: Boolean(data.hasApiKey),
		/** @type {boolean} Whether the user has set a personal key */
		hasUserApiKey: Boolean(data.hasUserApiKey),
		/** @type {boolean} Whether an admin has set an instance-wide key */
		hasInstanceApiKey: Boolean(data.hasInstanceApiKey),
		/** @type {boolean} Whether the user is a Nextcloud admin */
		isAdmin: Boolean(data.isAdmin),
		/** @type {string} Default language for TMDB metadata (BCP 47 format) */
		defaultLanguage: data.defaultLanguage || 'de-DE',
		/** @type {string} Application UI language preference ('auto' or locale code) */
		appLanguage: data.appLanguage || 'auto',
	}
}

/**
 * Settings store - Manages user application settings.
 * Handles TMDB API key configuration and language preferences.
 */
export const useSettingsStore = defineStore('settings', {
	// Starts from the settings sent with the page, so views know right away
	// whether a TMDB key is set (no "key required" flash while loading).
	state: () => ({
		...fromServer(loadState('moviedb', 'settings', {})),
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
				this.$patch(fromServer(response.data))
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
