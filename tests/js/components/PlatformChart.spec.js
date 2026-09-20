import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PlatformChart from '@/components/PlatformChart.vue'

describe('PlatformChart', () => {
	const mountComponent = (data) => {
		return mount(PlatformChart, {
			props: { data },
			global: {
				mocks: {
					t: (app, text) => text,
				},
			},
		})
	}

	it('should render a row per platform', () => {
		const wrapper = mountComponent([
			{ id: 1, name: 'Netflix', count: 10 },
			{ id: 2, name: 'Disney+', count: 4 },
		])
		expect(wrapper.findAll('.platform-row')).toHaveLength(2)
	})

	it('should render the empty state when data is empty', () => {
		const wrapper = mountComponent([])
		expect(wrapper.find('.empty-message').exists()).toBe(true)
		expect(wrapper.find('.platform-rows').exists()).toBe(false)
	})

	it('should label rows with platform name and count', () => {
		const wrapper = mountComponent([{ id: 1, name: 'Netflix', count: 10 }])
		expect(wrapper.find('.platform-name').text()).toBe('Netflix')
		expect(wrapper.find('.platform-count').text()).toBe('10')
	})

	it('should scale bar width relative to the max count', () => {
		const wrapper = mountComponent([
			{ id: 1, name: 'Netflix', count: 10 },
			{ id: 2, name: 'Disney+', count: 5 },
		])
		const fills = wrapper.findAll('.bar-fill')
		expect(fills[0].attributes('style')).toContain('100%')
		expect(fills[1].attributes('style')).toContain('50%')
	})
})
