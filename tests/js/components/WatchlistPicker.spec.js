import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import WatchlistPicker from '@/components/WatchlistPicker.vue'
import { useWatchlistStore } from '@/stores/watchlist.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { props: ['modelValue', 'value'], template: '<div class="radio"><slot /></div>' },
	NcDialog: { props: ['open', 'name'], template: '<div v-if="open" class="dialog"><slot /><slot name="actions" /></div>' },
	NcLoadingIcon: { template: '<span class="loading"></span>' },
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
const set = (n) => Array.from({ length: n }, (_, i) => item(i + 1))

function mountPicker(props = {}) {
	return mount(WatchlistPicker, {
		props: { open: true, canEdit: true, ...props },
		global: { mocks: { t: (app, text) => text }, stubs: { FerrisWheel: true } },
	})
}
const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

describe('WatchlistPicker', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWatchlistStore()
		vi.clearAllMocks()
	})

	afterEach(() => {
		delete window.matchMedia
		vi.useRealTimers()
	})

	it('loads the candidates when opened and shows them on a wheel, without a result yet', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(5))
		const wrapper = mountPicker()
		await flushPromises()

		expect(store.pickRandomSet).toHaveBeenCalledWith({}, 8)
		expect(wrapper.findAll('.wheel-segment')).toHaveLength(5)
		expect(wrapper.find('.picker-result').exists()).toBe(false)
		expect(button(wrapper, 'Spin the wheel').attributes('disabled')).toBeUndefined()
	})

	it('reveals the title the wheel landed on (reduced motion: immediately)', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(5))
		vi.spyOn(Math, 'random').mockReturnValue(0.5) // index 2
		const wrapper = mountPicker()
		await flushPromises()

		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		expect(wrapper.find('.picker-result h3').text()).toBe('Title 3')
		expect(wrapper.find('.picker-year').text()).toBe('2021')
		expect(wrapper.text()).toContain('Science Fiction')
		Math.random.mockRestore()
	})

	it('also spins when the wheel itself is clicked', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()

		await wrapper.find('.wheel').trigger('click')
		await flushPromises()

		expect(wrapper.find('.picker-result').exists()).toBe(true)
	})

	it('shows the spinning state, then the result after the wheel has stopped', async () => {
		vi.useFakeTimers()
		window.matchMedia = vi.fn().mockReturnValue({ matches: true })
		store.pickRandomSet = vi.fn().mockResolvedValue(set(4))
		const wrapper = mountPicker()
		await vi.advanceTimersByTimeAsync(10)

		await button(wrapper, 'Spin the wheel').trigger('click')
		await vi.advanceTimersByTimeAsync(100)

		expect(wrapper.find('.picker-live').text()).toContain('Spinning the wheel')
		expect(wrapper.find('.picker-result').exists()).toBe(false)
		expect(button(wrapper, 'Spin the wheel').attributes('disabled')).toBeDefined()

		await vi.advanceTimersByTimeAsync(4600)

		expect(wrapper.find('.picker-result').exists()).toBe(true)
		expect(wrapper.find('.picker-live').text()).not.toContain('Spinning')
	})

	it('spins again on a fresh selection that excludes the previous winner', async () => {
		store.pickRandomSet = vi.fn()
			.mockResolvedValueOnce(set(3))
			.mockResolvedValueOnce([item(7), item(8)])
		vi.spyOn(Math, 'random').mockReturnValue(0) // index 0
		const wrapper = mountPicker()
		await flushPromises()
		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()
		expect(wrapper.find('.picker-result h3').text()).toBe('Title 1')

		await button(wrapper, 'Spin again').trigger('click')
		await flushPromises()

		expect(store.pickRandomSet).toHaveBeenLastCalledWith({ exclude: 1 }, 8)
		expect(wrapper.find('.picker-result h3').text()).toBe('Title 7')
		Math.random.mockRestore()
	})

	it('sends type and priority filters and drops an old result', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()
		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		wrapper.vm.mediaType = 'series'
		wrapper.vm.highPriorityOnly = true
		await wrapper.vm.load()
		await flushPromises()

		expect(store.pickRandomSet).toHaveBeenLastCalledWith({ mediaType: 'series', minPriority: 1 }, 8)
		expect(wrapper.find('.picker-result').exists()).toBe(false)
	})

	it('drops a genre that does not exist for the newly chosen type', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()

		// 28 (Action) is a movie genre only; TV uses 10759 (Action & Adventure)
		wrapper.vm.genre = { id: 28, label: 'Action' }
		wrapper.vm.onTypeChange('series')
		await flushPromises()

		expect(wrapper.vm.genre).toBeNull()
	})

	it('shows the empty state and disables spinning when nothing matches', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue([])
		const wrapper = mountPicker()
		await flushPromises()

		expect(wrapper.text()).toContain('No title on your watchlist matches these filters.')
		expect(wrapper.find('.wheel').exists()).toBe(false)
		expect(button(wrapper, 'Spin the wheel').attributes('disabled')).toBeDefined()
	})

	it('keeps the current wheel when a request fails', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValueOnce(set(3)).mockResolvedValueOnce(false)
		const wrapper = mountPicker()
		await flushPromises()
		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		await button(wrapper, 'Spin again').trigger('click')
		await flushPromises()

		expect(wrapper.findAll('.wheel-segment')).toHaveLength(3)
		expect(wrapper.text()).not.toContain('No title on your watchlist')
	})

	it('announces the outcome through a persistent live region', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()
		const live = wrapper.find('.picker-live')
		expect(live.attributes('aria-live')).toBe('polite')

		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		expect(wrapper.find('.picker-live').text()).toContain('Title')
	})

	it('hides the decorative wheel from assistive technology', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()

		expect(wrapper.find('.wheel-svg').attributes('aria-hidden')).toBe('true')
	})

	it('emits markWatched with the winner and closes', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker()
		await flushPromises()
		expect(button(wrapper, 'Mark as Watched')).toBeUndefined()
		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		await button(wrapper, 'Mark as Watched').trigger('click')

		expect(wrapper.emitted('markWatched')[0][0].id).toBe(wrapper.vm.item.id)
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
	})

	it('hides Mark as Watched without edit permission', async () => {
		store.pickRandomSet = vi.fn().mockResolvedValue(set(3))
		const wrapper = mountPicker({ canEdit: false })
		await flushPromises()
		await button(wrapper, 'Spin the wheel').trigger('click')
		await flushPromises()

		expect(button(wrapper, 'Mark as Watched')).toBeUndefined()
	})
})
