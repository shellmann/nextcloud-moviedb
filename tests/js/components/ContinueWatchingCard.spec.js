import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ContinueWatchingCard from '@/components/ContinueWatchingCard.vue'
import api from '@/services/api.js'
import { useSeriesStore } from '@/stores/series.js'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\', $event)"><slot /></button>' },
	NcActions: { template: '<div class="nc-actions"><slot /></div>' },
	NcActionButton: { props: ['ariaLabel'], emits: ['click'], template: '<button class="nc-action" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot /></button>' },
}))

vi.mock('@/services/api.js', () => ({
	default: {
		markEpisodeWatched: vi.fn(),
		setSeriesStopped: vi.fn(),
	},
}))

const IconStub = { template: '<span />' }

const show = (extra = {}) => ({
	id: 7,
	title: 'Silo',
	progress: 40,
	watchedEpisodeCount: 4,
	airedEpisodeCount: 10,
	nextEpisode: { id: 55, seasonNumber: 1, episodeNumber: 5, name: 'Machines' },
	...extra,
})

function mountCard(props = {}) {
	return mount(ContinueWatchingCard, {
		props: { series: show(), canEdit: true, libraryId: 3, ...props },
		global: {
			stubs: {
				Check: IconStub,
				EyeOff: IconStub,
				Television: IconStub,
				RouterLink: { props: ['to'], template: '<a class="title-link" :data-to="JSON.stringify(to)"><slot /></a>' },
			},
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text) },
		},
	})
}

describe('ContinueWatchingCard', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	it('shows the next episode and the progress', () => {
		const wrapper = mountCard()

		expect(wrapper.find('.next').text()).toContain('Next:')
		expect(wrapper.find('.next').text()).toContain('S01E05')
		expect(wrapper.find('.next').text()).toContain('Machines')
		expect(wrapper.find('.count').text()).toBe('4/10')
		expect(wrapper.find('[role="progressbar"]').attributes('aria-valuetext')).toBe('4 of 10 episodes watched')
	})

	it('invites to start an unstarted show', () => {
		const wrapper = mountCard({ series: show({ watchedEpisodeCount: 0, progress: 0, nextEpisode: { id: 1, seasonNumber: 1, episodeNumber: 1 } }) })

		expect(wrapper.find('.next').text()).toContain('Start with')
		expect(wrapper.find('.next').text()).toContain('S01E01')
	})

	it('shows no actions to viewers', () => {
		const wrapper = mountCard({ canEdit: false })

		expect(wrapper.find('.actions').exists()).toBe(false)
	})

	it('marks the next episode watched and asks the row to reload', async () => {
		api.markEpisodeWatched.mockResolvedValue({ data: { series: {} } })
		const wrapper = mountCard()

		await wrapper.find('.nc-button').trigger('click')
		await flushPromises()

		expect(api.markEpisodeWatched).toHaveBeenCalledWith(7, 55, true, 3)
		expect(wrapper.emitted('changed')[0][0].id).toBe(7)
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('stops watching and tells the dashboard, which offers the undo', async () => {
		const store = useSeriesStore()
		store.setStopped = vi.fn().mockResolvedValue({ id: 7 })
		const wrapper = mountCard()

		await wrapper.find('.nc-action').trigger('click')
		await flushPromises()

		expect(store.setStopped).toHaveBeenCalledWith(7, true, { toast: false })
		expect(wrapper.emitted('stopped')[0][0].id).toBe(7)
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('emits nothing when stopping fails', async () => {
		const store = useSeriesStore()
		store.setStopped = vi.fn().mockResolvedValue(null)
		const wrapper = mountCard()

		await wrapper.find('.nc-action').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('stopped')).toBeUndefined()
	})

	it('is not a button itself, so its buttons are not nested in one', () => {
		const card = mountCard().find('.continue-card')

		expect(card.element.tagName).toBe('ARTICLE')
		expect(card.attributes('role')).toBeUndefined()
		expect(card.attributes('tabindex')).toBeUndefined()
	})

	it('links the title to the show for keyboard and screen reader users', () => {
		const link = mountCard().find('.title-link')

		expect(link.text()).toBe('Silo')
		expect(JSON.parse(link.attributes('data-to'))).toEqual({ name: 'series-detail', params: { id: '7' } })
	})

	it('names the buttons after the show, so several cards are told apart', () => {
		const wrapper = mountCard()

		expect(wrapper.find('.nc-button').attributes('aria-label')).toBe('Mark S01E05 of Silo as watched')
		expect(wrapper.find('.nc-action').attributes('aria-label')).toBe('Stop watching Silo')
	})

	it('opens the show when the poster is clicked', async () => {
		const wrapper = mountCard()

		await wrapper.find('.poster').trigger('click')

		expect(wrapper.emitted('open')).toHaveLength(1)
	})
})
