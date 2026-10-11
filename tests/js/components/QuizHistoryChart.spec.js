import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import QuizHistoryChart from '@/components/quiz/QuizHistoryChart.vue'

function mountChart(props) {
	return mount(QuizHistoryChart, {
		props,
		global: { mocks: { t: (app, text) => text } },
	})
}

const round = (id, percent) => ({ id, percent, playedAt: `2026-10-${String(id).padStart(2, '0')} 12:00:00` })

describe('QuizHistoryChart', () => {
	it('shows the empty state without rounds', () => {
		const wrapper = mountChart({ rounds: [], best: null })

		expect(wrapper.find('.empty-message').text()).toBe('No quiz played yet.')
		expect(wrapper.find('svg').exists()).toBe(false)
	})

	it('draws one bar per round, in the given order', () => {
		const wrapper = mountChart({ rounds: [round(1, 40), round(2, 80), round(3, 100)], best: 100 })

		expect(wrapper.findAll('rect.bar')).toHaveLength(3)
		expect(wrapper.findAll('.bar-count').map((t) => t.text())).toEqual(['40', '80', '100'])
	})

	it('scales bar heights with the percentage', () => {
		const wrapper = mountChart({ rounds: [round(1, 50), round(2, 100)], best: 100 })

		const [half, full] = wrapper.vm.bars
		expect(full.barHeight).toBeCloseTo(half.barHeight * 2)
		expect(full.barHeight).toBe(wrapper.vm.plotHeight)
	})

	it('clamps out-of-range percentages', () => {
		const wrapper = mountChart({ rounds: [round(1, 250), round(2, -5)], best: null })

		expect(wrapper.vm.bars[0].barHeight).toBe(wrapper.vm.plotHeight)
		expect(wrapper.vm.bars[1].barHeight).toBe(0)
	})

	it('draws the personal-best line only when a best exists', () => {
		expect(mountChart({ rounds: [round(1, 50)], best: 50 }).find('.best-line').exists()).toBe(true)
		expect(mountChart({ rounds: [round(1, 50)], best: null }).find('.best-line').exists()).toBe(false)
	})

	it('places the best line at the height of a bar with that percentage', () => {
		const wrapper = mountChart({ rounds: [round(1, 60)], best: 60 })

		expect(wrapper.vm.bestY).toBeCloseTo(wrapper.vm.bars[0].y)
	})

	it('has an accessible description including the scores', () => {
		const wrapper = mountChart({ rounds: [round(1, 40), round(2, 80)], best: 80 })
		const svg = wrapper.find('svg')

		expect(svg.attributes('role')).toBe('img')
		expect(svg.attributes('aria-label')).toBe('Score per quiz round in percent: 40%, 80%')
	})

	it('thins out date labels for long histories', () => {
		const rounds = Array.from({ length: 30 }, (_, i) => round((i % 28) + 1, 50))
		const wrapper = mountChart({ rounds, best: 50 })

		expect(wrapper.findAll('.bar-label').length).toBeLessThanOrEqual(10)
	})
})
