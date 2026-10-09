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

	it('lets admins manage an existing instance-wide key', () => {
		const settings = useSettingsStore()
		settings.isAdmin = true
		settings.hasInstanceApiKey = true

		const link = mountHint().find('a')

		expect(link.attributes('href')).toBe('/index.php/settings/admin/moviedb')
		expect(link.text()).toBe('Manage the key for all users')
	})

	it('shows nothing to other users once an instance-wide key exists', () => {
		useSettingsStore().hasInstanceApiKey = true

		expect(mountHint().find('.api-key-admin-hint').exists()).toBe(false)
	})

	it('tells everyone else that their administrator can set up a key', () => {
		useSettingsStore().isAdmin = false

		const wrapper = mountHint()

		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.text()).toBe('Your administrator can also set up a key for everyone.')
	})
})
