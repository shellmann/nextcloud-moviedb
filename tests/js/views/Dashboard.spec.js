import { showUndo } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import api from '@/services/api.js'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useSeriesStore } from '@/stores/series.js'
import { useSettingsStore } from '@/stores/settings.js'
import Dashboard from '@/views/Dashboard.vue'

vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { template: '<div><slot /></div>' },
	NcSelect: { template: '<div />' },
	// Pulled in by ContinueWatchingCard's imports (the card itself is stubbed).
	NcActions: { template: '<div />' },
	NcActionButton: { template: '<div />' },
	NcButton: { template: '<button />' },
}))

vi.mock('@/services/api.js', () => ({
	default: {
		getStats: vi.fn(),
		getRecentMovies: vi.fn(),
		getTopRatedMovies: vi.fn(),
		getContinueWatching: vi.fn(),
		getStatsByYear: vi.fn(),
		getStatsByPlatform: vi.fn(),
	},
}))

const Stub = { template: '<div />' }
// Mirrors the real card's markup that the dashboard's focus handling looks for.
const CardStub = {
	props: ['series', 'canEdit', 'libraryId'],
	emits: ['open', 'changed', 'stopped'],
	template: '<article class="continue-card continue-card-stub" :data-series-id="series.id"><a class="title-link" href="#">{{ series.title }}</a><button class="watched-button">W</button></article>',
}

function mountDashboard() {
	return mount(Dashboard, {
		attachTo: document.body,
		global: {
			stubs: {
				MovieCard: Stub,
				SeriesCard: Stub,
				YearChart: Stub,
				PlatformChart: Stub,
				ApiKeyAdminHint: Stub,
				ContinueWatchingCard: CardStub,
			},
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
		},
	})
}

describe('Dashboard continue watching', () => {
	let settings

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		settings = useSettingsStore()
		settings.hasApiKey = true

		api.getStats.mockResolvedValue({ data: {} })
		api.getRecentMovies.mockResolvedValue({ data: { movies: [], series: [] } })
		api.getTopRatedMovies.mockResolvedValue({ data: { movies: [], series: [] } })
		api.getStatsByYear.mockResolvedValue({ data: { years: {} } })
		api.getStatsByPlatform.mockResolvedValue({ data: { platforms: [] } })
	})

	it('shows the row above "Recently Watched" with one card per show', async () => {
		api.getContinueWatching.mockResolvedValue({ data: { series: [{ id: 1 }, { id: 2 }] } })
		const wrapper = mountDashboard()
		await flushPromises()

		expect(api.getContinueWatching).toHaveBeenCalledWith(10, 1)
		const headings = wrapper.findAll('h3').map((h) => h.text())
		expect(headings.indexOf('Continue watching')).toBeLessThan(headings.indexOf('Recently Watched'))
		expect(wrapper.findAll('.continue-card-stub')).toHaveLength(2)
	})

	it('hides the row when nothing is left to watch', async () => {
		api.getContinueWatching.mockResolvedValue({ data: { series: [] } })
		const wrapper = mountDashboard()
		await flushPromises()

		expect(wrapper.find('.continue-section').exists()).toBe(false)
	})

	it('does not even load the row when the user switched it off', async () => {
		settings.showContinueWatching = false
		const wrapper = mountDashboard()
		await flushPromises()

		expect(api.getContinueWatching).not.toHaveBeenCalled()
		expect(wrapper.find('.continue-section').exists()).toBe(false)
		expect(wrapper.findAll('h3').map((h) => h.text())).toContain('Recently Watched')
	})

	it('keeps the rest of the dashboard when the row fails to load', async () => {
		api.getContinueWatching.mockRejectedValue(new Error('nope'))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mountDashboard()
		await flushPromises()

		expect(wrapper.find('.continue-section').exists()).toBe(false)
		expect(api.getStats).toHaveBeenCalled()
	})

	it('reloads after a card changed something', async () => {
		api.getContinueWatching.mockResolvedValue({ data: { series: [{ id: 1 }] } })
		const wrapper = mountDashboard()
		await flushPromises()

		await wrapper.findComponent(CardStub).vm.$emit('changed')
		await flushPromises()

		expect(api.getContinueWatching).toHaveBeenCalledTimes(2)
		expect(api.getRecentMovies).toHaveBeenCalledTimes(2)
	})

	it('offers an undo after a show was stopped, and the undo brings it back', async () => {
		api.getContinueWatching.mockResolvedValue({ data: { series: [{ id: 1, title: 'Silo' }] } })
		const seriesStore = useSeriesStore()
		seriesStore.setStopped = vi.fn().mockResolvedValue({ id: 1 })
		const wrapper = mountDashboard()
		await flushPromises()

		await wrapper.findComponent(CardStub).vm.$emit('stopped', { id: 1, title: 'Silo' })
		await flushPromises()

		expect(api.getContinueWatching).toHaveBeenCalledTimes(2)
		expect(showUndo).toHaveBeenCalledWith('You stopped watching Silo.', expect.any(Function))

		await showUndo.mock.calls[0][1]()
		await flushPromises()

		expect(seriesStore.setStopped).toHaveBeenCalledWith(1, false, { toast: false })
		expect(api.getContinueWatching).toHaveBeenCalledTimes(3)
	})

	it('keeps the focus on the same card after marking an episode', async () => {
		api.getContinueWatching.mockResolvedValue({ data: { series: [{ id: 1, title: 'A' }, { id: 2, title: 'B' }] } })
		const wrapper = mountDashboard()
		await flushPromises()

		await wrapper.findAllComponents(CardStub)[1].vm.$emit('changed', { id: 2 })
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.find('[data-series-id="2"] .watched-button').element)
		wrapper.unmount()
	})

	it('moves the focus to the card that took the place of a removed one', async () => {
		api.getContinueWatching.mockResolvedValueOnce({ data: { series: [{ id: 1, title: 'A' }, { id: 2, title: 'B' }] } })
			.mockResolvedValue({ data: { series: [{ id: 2, title: 'B' }] } })
		const wrapper = mountDashboard()
		await flushPromises()

		await wrapper.findAllComponents(CardStub)[0].vm.$emit('stopped', { id: 1, title: 'A' })
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.find('[data-series-id="2"] .title-link').element)
		wrapper.unmount()
	})

	it('moves the focus to the next heading when the row is gone', async () => {
		api.getContinueWatching.mockResolvedValueOnce({ data: { series: [{ id: 1, title: 'A' }] } })
			.mockResolvedValue({ data: { series: [] } })
		const wrapper = mountDashboard()
		await flushPromises()

		await wrapper.findComponent(CardStub).vm.$emit('stopped', { id: 1, title: 'A' })
		await flushPromises()

		expect(document.activeElement.textContent.trim()).toBe('Recently Watched')
		wrapper.unmount()
	})
})
