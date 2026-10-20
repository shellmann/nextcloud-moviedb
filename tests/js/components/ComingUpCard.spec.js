import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ComingUpCard from '@/components/ComingUpCard.vue'

const IconStub = { template: '<span />' }

const show = (episode = {}, extra = {}) => ({
	id: 9,
	title: 'The Terminal List',
	posterPath: '/p.jpg',
	sameDayCount: 1,
	upcomingEpisode: { id: 90, seasonNumber: 2, episodeNumber: 1, name: 'Return', airDate: '2026-10-21', ...episode },
	...extra,
})

function mountCard(series = show()) {
	return mount(ComingUpCard, {
		props: { series },
		global: {
			stubs: {
				Television: IconStub,
				RouterLink: { props: ['to'], template: '<a class="title-link" :data-to="JSON.stringify(to)"><slot /></a>' },
			},
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text) },
		},
	})
}

describe('ComingUpCard', () => {
	it('names the show through the title link and labels the article with it', () => {
		const wrapper = mountCard()
		const title = wrapper.find('h4')

		expect(title.text()).toBe('The Terminal List')
		expect(wrapper.find('article').attributes('aria-labelledby')).toBe(title.attributes('id'))
		expect(JSON.parse(wrapper.find('.title-link').attributes('data-to'))).toEqual({ name: 'series-detail', params: { id: '9' } })
	})

	it('puts the air date in a <time> element with the machine readable date', () => {
		const time = mountCard().find('time')

		expect(time.attributes('datetime')).toBe('2026-10-21')
		expect(time.text()).toContain('Oct')
	})

	it('shows the episode code and name to the eye and spells them out for screen readers', () => {
		const wrapper = mountCard()
		const episode = wrapper.find('.episode')

		expect(episode.find('[aria-hidden="true"]').text()).toBe('S02E01 Return')
		expect(episode.find('.hidden-visually').text()).toBe('Season 2, episode 1: Return')
	})

	it('reads several episodes on one day as a range', () => {
		const wrapper = mountCard(show({}, { sameDayCount: 8 }))
		const episode = wrapper.find('.episode')

		// No episode title: the first one would read as the title of all eight.
		expect(episode.find('[aria-hidden="true"]').text()).toBe('S02E01–E08')
		expect(episode.find('.hidden-visually').text()).toBe('Season 2, episodes 1 to 8')
	})

	it('ends the range at the last episode of that day when the numbering has gaps', () => {
		const wrapper = mountCard(show({}, { sameDayCount: 3, sameDayLastEpisode: 4 }))

		expect(wrapper.find('.episode [aria-hidden="true"]').text()).toBe('S02E01–E04')
		expect(wrapper.find('.episode .hidden-visually').text()).toBe('Season 2, episodes 1 to 4')
	})

	it('marks the first episode of a later season as a new season, in text', () => {
		expect(mountCard().find('.badge').text()).toBe('New season')
	})

	it('marks the first episode of the first season as a premiere', () => {
		expect(mountCard(show({ seasonNumber: 1 })).find('.badge').text()).toBe('Premiere')
	})

	it('has no badge for a later episode', () => {
		expect(mountCard(show({ episodeNumber: 4 })).find('.badge').exists()).toBe(false)
	})

	it('has no buttons: there is nothing to do with an unaired episode', () => {
		expect(mountCard().find('button').exists()).toBe(false)
	})

	it('opens the show from the poster, which is hidden from assistive technology', async () => {
		const wrapper = mountCard()
		const poster = wrapper.find('.poster')

		expect(poster.attributes('aria-hidden')).toBe('true')
		await poster.trigger('click')
		expect(wrapper.emitted('open')).toHaveLength(1)
	})

	it('falls back to an icon without a poster', () => {
		const wrapper = mountCard(show({}, { posterPath: null }))

		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.no-poster').exists()).toBe(true)
	})
})

describe('ComingUpCard date label', () => {
	it('says "Tomorrow" in front of the date for the next day', () => {
		vi.useFakeTimers({ now: new Date(2026, 9, 20, 12, 0, 0) })
		try {
			const text = mountCard().find('time').text()
			expect(text.startsWith('Tomorrow · ')).toBe(true)
		} finally {
			vi.useRealTimers()
		}
	})
})
