import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SeriesDetail from '@/views/SeriesDetail.vue'
import { expectNoAxeViolations } from '../a11y.js'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useSeriesStore } from '@/stores/series.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcDialog: { template: '<div />' },
	NcProgressBar: { template: '<div class="nc-progress" />' },
	NcCheckboxRadioSwitch: { props: ['ariaLabel'], template: '<label class="nc-switch" :data-aria-label="ariaLabel"><slot /></label>' },
}))

const IconStub = { template: '<span />' }

function show(extra = {}) {
	return {
		id: 3,
		title: 'Fleabag',
		seasons: [],
		watchedEpisodeCount: 0,
		airedEpisodeCount: 0,
		progress: 0,
		caughtUp: false,
		nextEpisode: null,
		watchStatus: 'in_progress',
		...extra,
	}
}

async function mountDetail(series) {
	const store = useSeriesStore()
	store.fetchOne = vi.fn(async () => { store.currentSeries = series })
	store.setStopped = vi.fn().mockResolvedValue(series)
	store.refresh = vi.fn().mockResolvedValue({ series, result: {} })
	const wrapper = mount(SeriesDetail, {
		props: { id: '3' },
		global: {
			stubs: {
				ArrowLeft: IconStub,
				Check: IconStub,
				CheckAll: IconStub,
				ChevronDown: IconStub,
				ChevronRight: IconStub,
				Delete: IconStub,
				EyeOff: IconStub,
				Pencil: IconStub,
				PlayCircleOutline: IconStub,
				Refresh: IconStub,
				Television: IconStub,
			},
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text), $router: { push: vi.fn() } },
		},
	})
	await flushPromises()
	return { wrapper, store }
}

const buttons = (wrapper) => wrapper.findAll('.progress-actions .nc-button').map((b) => b.text())

describe('SeriesDetail progress block', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('explains a show without episode data instead of showing 0 / 0 and useless buttons', async () => {
		const { wrapper } = await mountDetail(show())

		expect(wrapper.find('.no-episodes').text()).toBe('No episodes are known for this show yet.')
		expect(wrapper.find('.nc-progress').exists()).toBe(false)
		expect(wrapper.find('.progress-actions').exists()).toBe(false)
	})

	it('says when episodes are known but none has aired', async () => {
		const { wrapper } = await mountDetail(show({ seasons: [{ seasonNumber: 1, episodes: [{ id: 1 }] }] }))

		expect(wrapper.find('.no-episodes').text()).toBe('No episodes have aired yet.')
	})

	it('still offers to resume a stopped show without aired episodes', async () => {
		const { wrapper } = await mountDetail(show({ watchStatus: 'stopped' }))

		expect(buttons(wrapper)).toEqual(['Resume watching'])
	})

	it('shows progress and all actions once episodes have aired', async () => {
		const { wrapper, store } = await mountDetail(show({ watchedEpisodeCount: 2, airedEpisodeCount: 5, progress: 40 }))

		expect(wrapper.find('.no-episodes').exists()).toBe(false)
		expect(wrapper.find('.nc-progress').exists()).toBe(true)
		expect(buttons(wrapper)).toEqual(['Mark series watched', 'Mark series unwatched', 'Stop watching'])

		await wrapper.findAll('.progress-actions .nc-button')[2].trigger('click')
		expect(store.setStopped).toHaveBeenCalledWith(3, true)
	})
})

describe('SeriesDetail watch summary', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('shows the language watched with its flag', async () => {
		const { wrapper } = await mountDetail(show({ languageWatched: 'de' }))

		expect(wrapper.find('.series-watch-summary').text()).toContain('Language: 🇩🇪 Deutsch (German)')
	})
})

function withSeasons(extra = {}) {
	return show({
		tmdbId: 1399,
		createdAt: '2026-10-01 10:00:00',
		watchedEpisodeCount: 1,
		airedEpisodeCount: 2,
		progress: 50,
		seasons: [
			{ seasonNumber: 1, watchedCount: 1, airedCount: 2, progress: 50, episodes: [
				{ id: 11, seasonNumber: 1, episodeNumber: 1, name: 'Pilot', watched: true, aired: true },
				{ id: 12, seasonNumber: 1, episodeNumber: 2, name: '', watched: false, aired: true },
			] },
			{ seasonNumber: 2, watchedCount: 0, airedCount: 0, progress: 0, episodes: [
				{ id: 21, seasonNumber: 2, episodeNumber: 1, name: 'Return', watched: false, aired: false },
			] },
		],
		...extra,
	})
}

describe('SeriesDetail update from TMDB', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('offers the button to editors of a show that has a TMDB id', async () => {
		const { wrapper } = await mountDetail(withSeasons())

		expect(wrapper.find('.refresh-button').text()).toBe('Update from TMDB')
	})

	it('has no button for a show without TMDB id', async () => {
		const { wrapper } = await mountDetail(withSeasons({ tmdbId: null }))

		expect(wrapper.find('.refresh-button').exists()).toBe(false)
	})

	it('has no button for viewers', async () => {
		const { wrapper } = await mountDetail(withSeasons())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Shared', isPersonal: false, role: 'viewer', permissionEdit: false }]
		await flushPromises()

		expect(wrapper.find('.refresh-button').exists()).toBe(false)
	})

	it('updates the show in the language of the user', async () => {
		const { wrapper, store } = await mountDetail(withSeasons())
		const { useSettingsStore } = await import('@/stores/settings.js')
		useSettingsStore().defaultLanguage = 'de-DE'

		await wrapper.find('.refresh-button').trigger('click')
		await flushPromises()

		expect(store.refresh).toHaveBeenCalledWith(3, 'de-DE')
	})

	it('shows it is busy without taking the button away from keyboard users', async () => {
		const { wrapper, store } = await mountDetail(withSeasons())
		let finish
		store.refresh = vi.fn(() => new Promise((resolve) => { finish = resolve }))

		await wrapper.find('.refresh-button').trigger('click')
		const button = wrapper.find('.refresh-button')
		expect(button.attributes('aria-busy')).toBe('true')
		expect(button.attributes('aria-disabled')).toBe('true')
		expect(button.attributes('disabled')).toBeUndefined()

		finish({})
		await flushPromises()
		expect(wrapper.find('.refresh-button').attributes('aria-busy')).toBe('false')
		expect(wrapper.find('.refresh-button').attributes('aria-disabled')).toBeUndefined()
	})

	it('ignores a second click while an update runs', async () => {
		const { wrapper, store } = await mountDetail(withSeasons())
		store.refresh = vi.fn(() => new Promise(() => {}))

		await wrapper.find('.refresh-button').trigger('click')
		await wrapper.find('.refresh-button').trigger('click')

		expect(store.refresh).toHaveBeenCalledTimes(1)
	})

	it('says when the episodes were last updated, falling back to when the show was added', async () => {
		const { wrapper } = await mountDetail(withSeasons({ tmdbRefreshedAt: '2026-10-19 08:00:00' }))
		expect(wrapper.find('.refreshed-note').text()).toMatch(/^Episodes last updated from TMDB: .*19.*2026/)

		const { wrapper: added } = await mountDetail(withSeasons())
		expect(added.find('.refreshed-note').text()).toMatch(/^Episodes last updated from TMDB: .*1.*2026/)
	})
})

describe('SeriesDetail seasons and episodes for assistive technology', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('makes the season title a real button inside the heading, with its state', async () => {
		const { wrapper } = await mountDetail(withSeasons())
		const toggles = wrapper.findAll('h3 button.season-toggle')

		expect(toggles).toHaveLength(2)
		expect(toggles[0].attributes('type')).toBe('button')
		// The first season starts expanded, the others collapsed.
		expect(toggles[0].attributes('aria-expanded')).toBe('true')
		expect(toggles[1].attributes('aria-expanded')).toBe('false')
		expect(toggles[0].attributes('aria-controls')).toBe('season-episodes-1')
		expect(wrapper.find('#season-episodes-1').exists()).toBe(true)
	})

	it('expands and collapses a season with the button', async () => {
		const { wrapper } = await mountDetail(withSeasons())
		const second = wrapper.findAll('.season-toggle')[1]

		await second.trigger('click')
		expect(second.attributes('aria-expanded')).toBe('true')
		await second.trigger('click')
		expect(second.attributes('aria-expanded')).toBe('false')
	})

	it('does not nest the season button in another button', async () => {
		const { wrapper } = await mountDetail(withSeasons())

		expect(wrapper.findAll('.season-toggle button')).toHaveLength(0)
		expect(wrapper.find('.season-header').text()).toContain('Mark season watched')
	})

	it('names the episode in the label of its checkbox', async () => {
		const { wrapper } = await mountDetail(withSeasons())
		const labels = wrapper.findAll('.episode-check .nc-switch').map((l) => l.attributes('data-aria-label'))

		expect(labels).toEqual(['S01E01 Pilot watched', 'S01E02 Episode 2 watched', 'S02E01 Return watched'])
	})
})

describe('SeriesDetail TMDB details', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
	})

	it('shows type, network and score with readable labels, vote count included as text', async () => {
		const { wrapper } = await mountDetail(show({
			firstAirYear: 2019,
			status: 'Ended',
			seriesType: 'Miniseries',
			networks: [{ name: 'HBO', logoPath: null }, { name: 'Sky', logoPath: null }],
			tmdbRating: 84,
			tmdbVoteCount: 1234,
			director: 'Jane Doe',
		}))

		const meta = wrapper.find('dl.series-meta')
		expect(meta.exists()).toBe(true)
		expect(meta.text()).toContain('Miniseries')
		expect(meta.text()).toContain('HBO · Sky')
		expect(meta.text()).toContain('TMDB user score')
		expect(meta.text()).toContain('84 % (1234 votes)')
		expect(meta.text()).toContain('Jane Doe')
	})

	it('hides the score when nobody voted and omits unknown fields', async () => {
		const { wrapper } = await mountDetail(show({ tmdbRating: null, tmdbVoteCount: 0, seriesType: '' }))

		expect(wrapper.find('.series-meta').text()).not.toContain('TMDB user score')
		expect(wrapper.find('.series-meta').text()).not.toContain('Type')
	})

	it('shows the cast between overview and seasons', async () => {
		const { wrapper } = await mountDetail(show({
			overview: 'Plot',
			castData: [{ name: 'Phoebe', character: 'Fleabag' }],
		}))

		expect(wrapper.find('.series-cast li').text()).toContain('Phoebe')
	})

	it('has no axe violations', async () => {
		const { wrapper } = await mountDetail(show({
			firstAirYear: 2019,
			status: 'Ended',
			seriesType: 'Scripted',
			networks: [{ name: 'BBC', logoPath: null }],
			tmdbRating: 90,
			tmdbVoteCount: 10,
			castData: [{ name: 'A', character: 'B' }],
		}))
		await expectNoAxeViolations(wrapper)
	})
})
