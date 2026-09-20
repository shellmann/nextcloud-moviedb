import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import YearChart from '@/components/YearChart.vue'

describe('YearChart', () => {
	const mountComponent = (data) => {
		return mount(YearChart, {
			props: { data },
			global: {
				mocks: {
					t: (app, text) => text,
				},
			},
		})
	}

	it('should render a bar per year', () => {
		const wrapper = mountComponent({ 2023: 5, 2024: 12 })
		expect(wrapper.findAll('.bar')).toHaveLength(2)
	})

	it('should render the empty state when data is empty', () => {
		const wrapper = mountComponent({})
		expect(wrapper.find('.empty-message').exists()).toBe(true)
		expect(wrapper.find('svg').exists()).toBe(false)
	})

	it('should label bars with year and count', () => {
		const wrapper = mountComponent({ 2024: 7 })
		expect(wrapper.find('.bar-label').text()).toBe('2024')
		expect(wrapper.find('.bar-count').text()).toBe('7')
	})
})
