import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SeriesCard from '@/components/SeriesCard.vue'

const IconStub = { template: '<span class="icon-stub"></span>' }

function mountCard(series) {
	return mount(SeriesCard, {
		props: { series: { id: 1, title: 'Silo', ...series } },
		global: {
			stubs: { Television: IconStub, Heart: IconStub, Calendar: IconStub },
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text) },
		},
	})
}

describe('SeriesCard progress', () => {
	it('shows a progress bar with an accessible label and a visible count below the poster', () => {
		const wrapper = mountCard({ watchedEpisodeCount: 4, airedEpisodeCount: 10, progress: 40 })
		const bar = wrapper.find('[role="progressbar"]')

		expect(bar.exists()).toBe(true)
		expect(bar.attributes('aria-valuenow')).toBe('40')
		expect(bar.attributes('aria-valuetext')).toBe('4 of 10 episodes watched')
		// below the poster, not drawn on top of the image
		expect(wrapper.find('.poster [role="progressbar"]').exists()).toBe(false)
		expect(wrapper.find('.info .count').text()).toBe('4/10')
	})

	it('has no progress bar without aired episodes or progress data', () => {
		expect(mountCard({ watchedEpisodeCount: 0, airedEpisodeCount: 0, progress: 0 }).find('[role="progressbar"]').exists()).toBe(false)
		expect(mountCard({}).find('[role="progressbar"]').exists()).toBe(false)
	})

	it('marks a stopped show with a badge and a dimmed poster', () => {
		const wrapper = mountCard({ watchStatus: 'stopped' })

		expect(wrapper.find('.stopped-badge').text()).toBe('Stopped')
		expect(wrapper.find('.poster').classes()).toContain('stopped')
	})

	it('shows no badge for shows in progress or finished', () => {
		expect(mountCard({ watchStatus: 'in_progress' }).find('.stopped-badge').exists()).toBe(false)
		expect(mountCard({ watchStatus: 'finished' }).find('.stopped-badge').exists()).toBe(false)
	})

	it('puts status and progress into the description of the card button', () => {
		const wrapper = mountCard({ watchStatus: 'stopped', watchedEpisodeCount: 3, airedEpisodeCount: 26, progress: 12 })
		const card = wrapper.find('.series-card')
		const id = card.attributes('aria-describedby')

		expect(id).toBe('series-desc-1')
		expect(wrapper.find('#' + id).text()).toBe('Stopped, 3 of 26 episodes watched')
	})

	it('has no description without status or progress', () => {
		expect(mountCard({}).find('.series-card').attributes('aria-describedby')).toBeUndefined()
	})
})
