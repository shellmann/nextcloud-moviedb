import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '@/stores/settings.js'

vi.mock('@/services/api.js', () => ({
	default: {
		getSettings: vi.fn(),
		updateSettings: vi.fn(),
	},
}))

import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import api from '@/services/api.js'

describe('Settings Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSettingsStore()
		vi.clearAllMocks()
	})

	describe('initial state', () => {
		it('should start from the settings sent with the page', () => {
			loadState.mockReturnValueOnce({
				hasApiKey: true,
				hasUserApiKey: false,
				hasInstanceApiKey: true,
				isAdmin: true,
				defaultLanguage: 'en-US',
				appLanguage: 'fr',
			})
			setActivePinia(createPinia())

			const fresh = useSettingsStore()

			expect(loadState).toHaveBeenCalledWith('moviedb', 'settings', {})
			expect(fresh.hasApiKey).toBe(true)
			expect(fresh.hasUserApiKey).toBe(false)
			expect(fresh.hasInstanceApiKey).toBe(true)
			expect(fresh.isAdmin).toBe(true)
			expect(fresh.defaultLanguage).toBe('en-US')
			expect(fresh.appLanguage).toBe('fr')
			expect(api.getSettings).not.toHaveBeenCalled()
		})

		it('should fall back to defaults without initial state', () => {
			expect(store.hasApiKey).toBe(false)
			expect(store.hasUserApiKey).toBe(false)
			expect(store.hasInstanceApiKey).toBe(false)
			expect(store.isAdmin).toBe(false)
			expect(store.defaultLanguage).toBe('de-DE')
			expect(store.appLanguage).toBe('auto')
			expect(store.showContinueWatching).toBe(true)
		})

		it('should show coming up by default and keep it off once switched off', () => {
			expect(useSettingsStore().showComingUp).toBe(true)
			loadState.mockReturnValueOnce({ showComingUp: false, showContinueWatching: true })
			setActivePinia(createPinia())
			const store = useSettingsStore()
			expect(store.showComingUp).toBe(false)
			expect(store.showContinueWatching).toBe(true)
		})

		it('should have notifications and the calendar on by default and keep them off once switched off', () => {
			expect(useSettingsStore().notifyReleases).toBe(true)
			expect(useSettingsStore().showCalendar).toBe(true)
			loadState.mockReturnValueOnce({ notifyReleases: false, showCalendar: false })
			setActivePinia(createPinia())
			const store = useSettingsStore()
			expect(store.notifyReleases).toBe(false)
			expect(store.showCalendar).toBe(false)
		})

		it('should keep continue watching off once the user switched it off', () => {
			loadState.mockReturnValueOnce({ showContinueWatching: false })
			setActivePinia(createPinia())

			expect(useSettingsStore().showContinueWatching).toBe(false)
		})
	})

	describe('update ordering', () => {
		it('sends the writes one after the other, in the order they were made', async () => {
			const order = []
			let releaseFirst
			api.updateSettings.mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = () => { order.push('first done'); resolve() } }))
			api.updateSettings.mockImplementationOnce(async () => { order.push('second sent') })
			api.getSettings.mockResolvedValue({ data: {} })

			const first = store.update({ notifyReleases: false })
			const second = store.update({ notifyReleases: true })
			await new Promise((resolve) => setTimeout(resolve, 0))
			expect(api.updateSettings).toHaveBeenCalledTimes(1)
			releaseFirst()
			await Promise.all([first, second])

			expect(order).toEqual(['first done', 'second sent'])
		})

		it('a failed write does not block the next one', async () => {
			api.updateSettings.mockRejectedValueOnce(new Error('nope'))
			api.updateSettings.mockResolvedValueOnce()
			api.getSettings.mockResolvedValue({ data: {} })

			await expect(store.update({ showCalendar: false })).rejects.toThrow('nope')
			await store.update({ showCalendar: true })

			expect(api.updateSettings).toHaveBeenCalledTimes(2)
		})
	})

	describe('fetch', () => {
		it('should load settings', async () => {
			api.getSettings.mockResolvedValue({ data: { hasApiKey: true, defaultLanguage: 'en-US', appLanguage: 'auto' } })

			await store.fetch()

			expect(store.hasApiKey).toBe(true)
			expect(store.defaultLanguage).toBe('en-US')
		})

		it('should load which key is in use', async () => {
			api.getSettings.mockResolvedValue({ data: { hasApiKey: true, hasUserApiKey: false, hasInstanceApiKey: true, isAdmin: false } })

			await store.fetch()

			expect(store.hasUserApiKey).toBe(false)
			expect(store.hasInstanceApiKey).toBe(true)
			expect(store.isAdmin).toBe(false)
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
