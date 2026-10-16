import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import EpisodeProgress from '@/components/EpisodeProgress.vue'

describe('EpisodeProgress', () => {
	it('exposes the progress to assistive technology', () => {
		const bar = mount(EpisodeProgress, { props: { watched: 4, aired: 10 } }).find('[role="progressbar"]')

		expect(bar.attributes('aria-valuenow')).toBe('40')
		expect(bar.attributes('aria-valuetext')).toBe('4 of 10 episodes watched')
		expect(bar.attributes('aria-label')).toBe('Episode progress')
	})

	it('shows the count as text, not only as a bar', () => {
		const wrapper = mount(EpisodeProgress, { props: { watched: 4, aired: 10 } })

		expect(wrapper.find('.count').text()).toBe('4/10')
		expect(wrapper.find('.fill').attributes('style')).toContain('width: 40%')
	})

	it('marks a finished show and never overflows', () => {
		const wrapper = mount(EpisodeProgress, { props: { watched: 12, aired: 10 } })

		expect(wrapper.classes()).toContain('complete')
		expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('100')
	})

	it('handles a show without aired episodes', () => {
		const wrapper = mount(EpisodeProgress, { props: { watched: 0, aired: 0 } })

		expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('0')
		expect(wrapper.classes()).not.toContain('complete')
	})
})
