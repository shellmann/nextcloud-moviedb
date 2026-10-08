import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'

// AdminSettings.vue imports each component from its own path to keep the
// admin bundle small, so each one is mocked separately.
vi.mock('@nextcloud/vue/components/NcSettingsSection', () => ({
	default: {
		props: ['name', 'description'],
		template: '<section><h2>{{ name }}</h2><slot /></section>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { template: '<div class="note"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({
	default: { props: ['modelValue', 'label', 'placeholder'], template: '<input />' },
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { template: '<button><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		props: ['open', 'name'],
		template: '<div v-if="open" class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>',
	},
}))

vi.mock('@/services/api.js', () => ({
	default: {
		updateAdminSettings: vi.fn(),
	},
}))

import api from '@/services/api.js'
import AdminSettings from '@/views/AdminSettings.vue'

describe('AdminSettings', () => {
	const mountAdmin = (state) => {
		loadState.mockReturnValueOnce(state)
		return mount(AdminSettings, {
			global: { mocks: { t: (app, text) => text } },
		})
	}

	const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('reads whether a key is set from the initial state', () => {
		const wrapper = mountAdmin({ hasInstanceApiKey: true })

		expect(loadState).toHaveBeenCalledWith('moviedb', 'admin-settings', {})
		expect(wrapper.find('.api-key-status').text()).toBe('API key configured')
		expect(button(wrapper, 'Remove API Key')).toBeTruthy()
	})

	it('shows that no key is set', () => {
		const wrapper = mountAdmin({ hasInstanceApiKey: false })

		expect(wrapper.find('.api-key-status').text()).toBe('No API key')
		expect(button(wrapper, 'Remove API Key')).toBeUndefined()
	})

	it('links to the TMDB API terms of use', () => {
		const wrapper = mountAdmin({})

		expect(wrapper.find('a[href="https://www.themoviedb.org/api-terms-of-use"]').exists()).toBe(true)
	})

	it('saves a key, clears the field and updates the status', async () => {
		api.updateAdminSettings.mockResolvedValue({ data: { hasInstanceApiKey: true } })
		const wrapper = mountAdmin({ hasInstanceApiKey: false })
		wrapper.vm.tmdbApiKey = 'new-key'

		await wrapper.vm.saveApiKey()

		expect(api.updateAdminSettings).toHaveBeenCalledWith({ tmdbApiKey: 'new-key' })
		expect(showSuccess).toHaveBeenCalledWith('Settings saved successfully.')
		expect(wrapper.vm.tmdbApiKey).toBe('')
		expect(wrapper.find('.api-key-status').text()).toBe('API key configured')
	})

	it('explains a key that TMDB did not accept and keeps the input', async () => {
		api.updateAdminSettings.mockRejectedValue({ response: { status: 422 } })
		const wrapper = mountAdmin({ hasInstanceApiKey: false })
		wrapper.vm.tmdbApiKey = 'wrong-key'

		await wrapper.vm.saveApiKey()

		expect(showError).toHaveBeenCalledWith('TMDB did not accept this API key. Make sure you use the API Read Access Token.')
		expect(wrapper.vm.tmdbApiKey).toBe('wrong-key')
		expect(wrapper.vm.hasInstanceApiKey).toBe(false)
	})

	it('explains when TMDB could not be reached', async () => {
		api.updateAdminSettings.mockRejectedValue({ response: { status: 502 } })
		const wrapper = mountAdmin({})
		wrapper.vm.tmdbApiKey = 'new-key'

		await wrapper.vm.saveApiKey()

		expect(showError).toHaveBeenCalledWith('Could not reach TMDB to check the API key. Please try again.')
	})

	it('asks before removing the key, then removes it', async () => {
		api.updateAdminSettings.mockResolvedValue({ data: { hasInstanceApiKey: false } })
		const wrapper = mountAdmin({ hasInstanceApiKey: true })

		await button(wrapper, 'Remove API Key').trigger('click')
		expect(wrapper.find('.dialog').text()).toContain('Users without their own key will no longer be able to search TMDB.')
		expect(api.updateAdminSettings).not.toHaveBeenCalled()

		await button(wrapper.find('.dialog'), 'Remove').trigger('click')
		await flushPromises()

		expect(api.updateAdminSettings).toHaveBeenCalledWith({ tmdbApiKey: '' })
		expect(showSuccess).toHaveBeenCalledWith('API key removed successfully.')
		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(wrapper.find('.api-key-status').text()).toBe('No API key')
	})

	it('reports a failed removal', async () => {
		api.updateAdminSettings.mockRejectedValue(new Error('Server error'))
		const wrapper = mountAdmin({ hasInstanceApiKey: true })

		await wrapper.vm.confirmRemoveApiKey()

		expect(showError).toHaveBeenCalledWith('Failed to remove API key. Please try again.')
		expect(wrapper.vm.hasInstanceApiKey).toBe(true)
	})
})
