import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MovieCard from '@/components/MovieCard.vue'

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

	const mountComponent = (movie = defaultMovie) => {
		return mount(MovieCard, {
			props: { movie },
			global: {
				stubs: {
					Movie: IconStub,
					Heart: IconStub,
					Calendar: IconStub,
				},
				mocks: {
					t: (app, text) => text,
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

	it('should show no-poster placeholder when posterPath is missing', () => {
		const wrapper = mountComponent({ ...defaultMovie, posterPath: null })
		expect(wrapper.find('.no-poster').exists()).toBe(true)
		expect(wrapper.find('.poster img').exists()).toBe(false)
	})

	it('should render poster image when posterPath exists', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.poster img').exists()).toBe(true)
	})
})
