import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSettingsStore } from '@/stores/settings.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getSettings: vi.fn(),
		updateSettings: vi.fn(),
	},
}))

import api from '@/services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

describe('Settings Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSettingsStore()
		vi.clearAllMocks()
	})

	describe('fetch', () => {
		it('should load settings', async () => {
			api.getSettings.mockResolvedValue({ data: { hasApiKey: true, defaultLanguage: 'en-US', appLanguage: 'auto' } })

			await store.fetch()

			expect(store.hasApiKey).toBe(true)
			expect(store.defaultLanguage).toBe('en-US')
		})

		it('should show an error when loading fails', async () => {
			api.getSettings.mockRejectedValue(new Error('Network error'))

			await store.fetch()

			expect(showError).toHaveBeenCalledOnce()
		})
	})

	describe('update', () => {
		it('should save, refetch, and leave the success message to the caller', async () => {
			api.updateSettings.mockResolvedValue({ data: {} })
			api.getSettings.mockResolvedValue({ data: { hasApiKey: true, defaultLanguage: 'de-DE' } })

			await store.update({ tmdbApiKey: 'new-key' })

			expect(api.updateSettings).toHaveBeenCalledWith({ tmdbApiKey: 'new-key' })
			expect(store.hasApiKey).toBe(true)
			expect(showSuccess).not.toHaveBeenCalled()
		})

		it('should reject on failure so the caller does not report success', async () => {
			api.updateSettings.mockRejectedValue(new Error('Server error'))

			await expect(store.update({ tmdbApiKey: 'new-key' })).rejects.toThrow('Server error')

			expect(api.getSettings).not.toHaveBeenCalled()
			expect(showSuccess).not.toHaveBeenCalled()
			expect(showError).not.toHaveBeenCalled()
		})
	})
})
