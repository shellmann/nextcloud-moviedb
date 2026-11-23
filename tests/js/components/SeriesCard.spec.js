import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SeriesCard from '@/components/SeriesCard.vue'
import { expectNoAxeViolations } from '../a11y.js'

const IconStub = { template: '<span class="icon-stub"></span>' }

function mountCard(series, props = {}) {
	return mount(SeriesCard, {
		props: { series: { id: 1, title: 'Silo', ...series }, ...props },
		global: {
			stubs: { Television: IconStub, Heart: IconStub, HeartOutline: IconStub, Calendar: IconStub },
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
		const card = wrapper.find('.card-link')
		const id = card.attributes('aria-describedby')

		expect(id).toBe('series-desc-1')
		expect(wrapper.find('#' + id).text()).toBe('Stopped, 3 of 26 episodes watched')
	})

	it('has no description without status or progress', () => {
		expect(mountCard({}).find('.card-link').attributes('aria-describedby')).toBeUndefined()
	})

	// Lost in 1.2.0 for movies and never shown for shows; guard both now.
	it('shows the language watched as a flag and names it in the description', () => {
		const wrapper = mountCard({ lastLanguage: 'de', watchedEpisodeCount: 3, airedEpisodeCount: 26 })
		const flag = wrapper.find('.language')

		expect(flag.text()).toBe('🇩🇪')
		expect(flag.attributes('aria-hidden')).toBe('true')
		expect(flag.attributes('title')).toBe('German')
		expect(wrapper.find('#series-desc-1').text()).toBe('3 of 26 episodes watched, German')
	})

	it('shows no flag without a language', () => {
		expect(mountCard({}).find('.language').exists()).toBe(false)
	})
})

describe('SeriesCard favourite toggle (#136)', () => {
	it('opens the show from the title button, which is the only way in for the keyboard', async () => {
		const wrapper = mountCard({})
		const link = wrapper.find('.card-link')
		expect(link.element.tagName).toBe('BUTTON')
		expect(link.text()).toBe('Silo')
		expect(wrapper.find('.series-card').attributes('role')).toBeUndefined()

		await link.trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
	})

	it('toggles the favourite without opening the show', async () => {
		const wrapper = mountCard({ isFavorite: false }, { canEdit: true })
		const toggle = wrapper.find('.favorite-toggle')
		expect(toggle.attributes('aria-pressed')).toBe('false')
		expect(toggle.attributes('aria-label')).toBe('Favorite: Silo')

		await toggle.trigger('click')
		expect(wrapper.emitted('toggleFavorite')).toHaveLength(1)
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('shows a set favourite as pressed', () => {
		expect(mountCard({ isFavorite: true }, { canEdit: true }).find('.favorite-toggle').attributes('aria-pressed')).toBe('true')
	})

	it('shows only the read-only badge without edit permission', () => {
		const wrapper = mountCard({ isFavorite: true })
		expect(wrapper.find('.favorite-toggle').exists()).toBe(false)
		expect(wrapper.find('.favorite-badge').exists()).toBe(true)
	})

	it('has no axe violations with and without the toggle', async () => {
		await expectNoAxeViolations(mountCard({ isFavorite: true, watchStatus: 'stopped', watchedEpisodeCount: 3, airedEpisodeCount: 26 }, { canEdit: true }))
		await expectNoAxeViolations(mountCard({ isFavorite: false }, { canEdit: true }))
		await expectNoAxeViolations(mountCard({ isFavorite: true }))
	})
})
