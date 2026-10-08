import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ApiKeyAdminHint from '@/components/ApiKeyAdminHint.vue'
import { useSettingsStore } from '@/stores/settings.js'

describe('ApiKeyAdminHint', () => {
	const mountHint = () => mount(ApiKeyAdminHint, {
		global: { mocks: { t: (app, text) => text } },
	})

	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('links admins to the MovieDB admin settings', () => {
		useSettingsStore().isAdmin = true

		const link = mountHint().find('a')

		expect(link.attributes('href')).toBe('/index.php/settings/admin/moviedb')
		expect(link.text()).toBe('Set up a key for all users')
	})

	it('tells everyone else that their administrator can set up a key', () => {
		useSettingsStore().isAdmin = false

		const wrapper = mountHint()

		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.text()).toBe('Your administrator can also set up a key for everyone.')
	})
})
