import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WatchlistPicker from '@/components/WatchlistPicker.vue'
import { useWatchlistStore } from '@/stores/watchlist.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { props: ['modelValue', 'value'], template: '<div class="radio"><slot /></div>' },
	NcDialog: { props: ['open', 'name'], template: '<div v-if="open" class="dialog"><slot /><slot name="actions" /></div>' },
	NcLoadingIcon: { template: '<span></span>' },
	NcSelect: { template: '<div class="select"></div>' },
}))

function item(id, extra = {}) {
	return {
		id,
		title: `Title ${id}`,
		mediaType: 'movie',
		releaseDate: '2021-10-22',
		genreIds: [878],
		priority: 0,
		overview: 'Overview',
		posterPath: null,
		...extra,
	}
}

function mountPicker(props = {}) {
	return mount(WatchlistPicker, {
		props: { open: true, canEdit: true, ...props },
		global: { mocks: { t: (app, text) => text } },
	})
}

describe('WatchlistPicker', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWatchlistStore()
		vi.clearAllMocks()
	})

	it('picks a title when opened and shows it', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(item(1))
		const wrapper = mountPicker()
		await flushPromises()

		expect(store.pickRandom).toHaveBeenCalledWith({})
		expect(wrapper.text()).toContain('Title 1')
		expect(wrapper.text()).toContain('2021')
		expect(wrapper.text()).toContain('Science Fiction')
	})

	it('spin again excludes the title that is shown', async () => {
		store.pickRandom = vi.fn()
			.mockResolvedValueOnce(item(1))
			.mockResolvedValueOnce(item(2))
		const wrapper = mountPicker()
		await flushPromises()

		const spin = wrapper.findAll('button').find((b) => b.text() === 'Spin again')
		await spin.trigger('click')
		await flushPromises()

		expect(store.pickRandom).toHaveBeenLastCalledWith({ exclude: 1 })
		expect(wrapper.text()).toContain('Title 2')
	})

	it('sends type and priority filters', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(item(1))
		const wrapper = mountPicker()
		await flushPromises()

		wrapper.vm.mediaType = 'series'
		wrapper.vm.highPriorityOnly = true
		await wrapper.vm.pick(false)

		expect(store.pickRandom).toHaveBeenLastCalledWith({ mediaType: 'series', minPriority: 1 })
	})

	it('drops a genre that does not exist for the newly chosen type', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(item(1))
		const wrapper = mountPicker()
		await flushPromises()

		// 28 (Action) is a movie genre only; TV uses 10759 (Action & Adventure)
		wrapper.vm.genre = { id: 28, label: 'Action' }
		wrapper.vm.onTypeChange('series')
		await flushPromises()

		expect(wrapper.vm.genre).toBeNull()
	})

	it('shows the empty state when nothing matches', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(null)
		const wrapper = mountPicker()
		await flushPromises()

		expect(wrapper.text()).toContain('No title on your watchlist matches these filters.')
	})

	it('keeps the current title when the request fails', async () => {
		store.pickRandom = vi.fn()
			.mockResolvedValueOnce(item(1))
			.mockResolvedValueOnce(false)
		const wrapper = mountPicker()
		await flushPromises()

		await wrapper.vm.pick(true)

		expect(wrapper.text()).toContain('Title 1')
		expect(wrapper.text()).not.toContain('No title on your watchlist')
	})

	it('emits mark-watched with the shown item and closes', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(item(1))
		const wrapper = mountPicker()
		await flushPromises()

		const mark = wrapper.findAll('button').find((b) => b.text() === 'Mark as Watched')
		await mark.trigger('click')

		expect(wrapper.emitted('markWatched')[0][0].id).toBe(1)
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
	})

	it('hides Mark as Watched without edit permission', async () => {
		store.pickRandom = vi.fn().mockResolvedValue(item(1))
		const wrapper = mountPicker({ canEdit: false })
		await flushPromises()

		expect(wrapper.findAll('button').some((b) => b.text() === 'Mark as Watched')).toBe(false)
	})
})
