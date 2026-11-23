import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MovieCard from '@/components/MovieCard.vue'
import { expectNoAxeViolations } from '../a11y.js'

// Mock vue-material-design-icons
const IconStub = { template: '<span class="icon-stub"></span>' }

describe('MovieCard', () => {
	const defaultMovie = {
		id: 1,
		title: 'Test Movie',
		posterPath: '/test-poster.jpg',
		releaseYear: 2024,
		lastWatchedAt: '2024-03-15',
		lastRating: 8,
		isFavorite: false,
	}

	const mountComponent = (movie = defaultMovie, props = {}) => {
		return mount(MovieCard, {
			props: { movie, ...props },
			global: {
				stubs: {
					Movie: IconStub,
					Heart: IconStub,
					HeartOutline: IconStub,
					Calendar: IconStub,
				},
				mocks: {
					t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text),
				},
			},
		})
	}

	it('should render movie title', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.title').text()).toBe('Test Movie')
	})

	it('should render release year', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.year').text()).toBe('2024')
	})

	it('should show rating badge when rating exists', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.rating-badge').text()).toBe('8')
	})

	it('should not show rating badge when no rating', () => {
		const wrapper = mountComponent({ ...defaultMovie, lastRating: null })
		expect(wrapper.find('.rating-badge').exists()).toBe(false)
	})

	it('should show favorite badge when movie is favorite', () => {
		const wrapper = mountComponent({ ...defaultMovie, isFavorite: true })
		expect(wrapper.find('.favorite-badge').exists()).toBe(true)
	})

	it('should not show favorite badge when movie is not favorite', () => {
		const wrapper = mountComponent({ ...defaultMovie, isFavorite: false })
		expect(wrapper.find('.favorite-badge').exists()).toBe(false)
	})

	it('should emit click event when card is clicked', async () => {
		const wrapper = mountComponent()
		await wrapper.find('.movie-card').trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
	})

	it('opens the movie from the title button, which is the only way in for the keyboard', async () => {
		const wrapper = mountComponent()
		const link = wrapper.find('.card-link')
		expect(link.element.tagName).toBe('BUTTON')
		expect(link.text()).toBe('Test Movie')
		expect(wrapper.find('.movie-card').attributes('role')).toBeUndefined()
		expect(wrapper.find('.movie-card').attributes('tabindex')).toBeUndefined()

		await link.trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
	})

	it('toggles the favourite without opening the movie (#136)', async () => {
		const wrapper = mountComponent(defaultMovie, { canEdit: true })
		const toggle = wrapper.find('.favorite-toggle')
		expect(toggle.element.tagName).toBe('BUTTON')
		expect(toggle.attributes('aria-pressed')).toBe('false')
		expect(toggle.attributes('aria-label')).toBe('Favorite: Test Movie')

		await toggle.trigger('click')
		expect(wrapper.emitted('toggleFavorite')).toHaveLength(1)
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('shows a set favourite as pressed and keeps the badge out of the way', () => {
		const wrapper = mountComponent({ ...defaultMovie, isFavorite: true }, { canEdit: true })
		expect(wrapper.find('.favorite-toggle').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('.favorite-toggle').classes()).toContain('active')
		expect(wrapper.find('.favorite-badge').exists()).toBe(false)
	})

	it('shows no toggle without edit permission', () => {
		expect(mountComponent({ ...defaultMovie, isFavorite: true }).find('.favorite-toggle').exists()).toBe(false)
	})

	it('has no axe violations with and without the toggle', async () => {
		await expectNoAxeViolations(mountComponent({ ...defaultMovie, isFavorite: true, lastLanguage: 'fr' }, { canEdit: true }))
		await expectNoAxeViolations(mountComponent(defaultMovie, { canEdit: true }))
		await expectNoAxeViolations(mountComponent({ ...defaultMovie, isFavorite: true }))
	})

	it('should show no-poster placeholder when posterPath is missing', () => {
		const wrapper = mountComponent({ ...defaultMovie, posterPath: null })
		expect(wrapper.find('.no-poster').exists()).toBe(true)
		expect(wrapper.find('.poster img').exists()).toBe(false)
	})

	it('should render poster image when posterPath exists', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.poster img').exists()).toBe(true)
	})

	// The flag was dropped in 1.2.0 when the language moved to the watches table.
	it('shows the language of the latest watch as a flag and names it in the description', () => {
		const wrapper = mountComponent({ ...defaultMovie, lastLanguage: 'fr' })
		const flag = wrapper.find('.language')

		expect(flag.text()).toBe('🇫🇷')
		expect(flag.attributes('aria-hidden')).toBe('true')
		expect(wrapper.find('.card-link').attributes('aria-describedby')).toBe('movie-desc-1')
		expect(wrapper.find('#movie-desc-1').text()).toBe('French')
	})

	it('shows no flag and no description without a language', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.language').exists()).toBe(false)
		expect(wrapper.find('.card-link').attributes('aria-describedby')).toBeUndefined()
	})
})
