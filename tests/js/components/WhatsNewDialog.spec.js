import { loadState } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WhatsNewDialog from '@/components/WhatsNewDialog.vue'
import api from '@/services/api.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div v-if="open" class="dialog" role="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /><button class="escape" @click="$emit(\'update:open\', false)" /></div>',
	},
}))

vi.mock('@/services/api.js', () => ({
	default: { updateSettings: vi.fn() },
}))

async function mountWith(state) {
	loadState.mockReturnValueOnce(state)
	const wrapper = mount(WhatsNewDialog)
	// The dialog opens in mounted()
	await flushPromises()
	return wrapper
}

describe('WhatsNewDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		api.updateSettings.mockResolvedValue({})
	})

	it('shows the highlights after an update', async () => {
		const wrapper = await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.8.0' })

		expect(wrapper.find('.dialog h2').text()).toBe('What\'s new in MovieDB')
		expect(wrapper.find('.release-version').text()).toBe('Version 1.9.0')
		expect(wrapper.findAll('li').length).toBeGreaterThanOrEqual(2)
		expect(api.updateSettings).not.toHaveBeenCalled()
	})

	it('links to the full changelog in a new tab', async () => {
		const link = (await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.8.0' })).find('.all-changes a')

		expect(link.attributes('href')).toContain('/CHANGELOG.md')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toBe('noopener noreferrer')
	})

	it('remembers the version when the user closes it', async () => {
		const wrapper = await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.8.0' })

		await wrapper.find('.nc-button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(api.updateSettings).toHaveBeenCalledWith({ lastSeenVersion: '1.9.0' })
	})

	it('also remembers it when closed with Escape or the close button', async () => {
		const wrapper = await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.8.0' })

		await wrapper.find('.escape').trigger('click')
		await flushPromises()

		expect(api.updateSettings).toHaveBeenCalledWith({ lastSeenVersion: '1.9.0' })
	})

	it('stays closed when the user has seen this version', async () => {
		const wrapper = await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.9.0' })

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(api.updateSettings).not.toHaveBeenCalled()
	})

	it('moves on quietly after an update without highlights', async () => {
		const wrapper = await mountWith({ currentVersion: '1.9.99', lastSeenVersion: '1.9.1' })
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(api.updateSettings).toHaveBeenCalledWith({ lastSeenVersion: '1.9.99' })
	})

	it('does nothing without version information', async () => {
		const wrapper = await mountWith(null)

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(api.updateSettings).not.toHaveBeenCalled()
	})

	it('does not bother the user with an error when saving fails', async () => {
		api.updateSettings.mockRejectedValue(new Error('nope'))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = await mountWith({ currentVersion: '1.9.0', lastSeenVersion: '1.8.0' })

		await wrapper.find('.nc-button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(false)
	})
})
