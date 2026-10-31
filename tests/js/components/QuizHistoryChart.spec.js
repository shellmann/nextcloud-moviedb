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

	// Server timestamps are UTC; build them from LOCAL times so the days are the same in every time zone.
	const server = (y, m, d, h) => new Date(y, m, d, h).toISOString().slice(0, 19).replace('T', ' ')
	const local = (y, m, d, h) => new Date(y, m, d, h).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })

	it('shows only the first and the last date, below the chart', () => {
		const rounds = [1, 2, 3, 4, 5].map((id) => ({ id, percent: 50, playedAt: server(2026, 9, 10 + id * 3, 12) }))
		const wrapper = mountChart({ rounds, best: 50 })

		expect(wrapper.find('.date-range').text()).toBe(`${local(2026, 9, 13, 12)} – ${local(2026, 9, 25, 12)}`)
		// no date text inside the SVG, so nothing can overlap or be skipped
		expect(wrapper.find('svg').findAll('text').map((t) => t.text()).filter((t) => /[A-Za-z]/.test(t))).toEqual([])
	})

	it('shows a single date when all rounds were played on one day', () => {
		const rounds = [1, 2, 3].map((id) => ({ id, percent: 50, playedAt: server(2026, 9, 11, 8 + id) }))
		const wrapper = mountChart({ rounds, best: 50 })

		expect(wrapper.find('.date-range').text()).toBe(local(2026, 9, 11, 9))
	})

	it('shows a single date for one round', () => {
		const wrapper = mountChart({ rounds: [{ id: 1, percent: 50, playedAt: server(2026, 9, 11, 12) }], best: 50 })

		expect(wrapper.find('.date-range').text()).toBe(local(2026, 9, 11, 12))
	})

	it('shows no date range without rounds or with unreadable timestamps', () => {
		expect(mountChart({ rounds: [], best: null }).find('.date-range').exists()).toBe(false)
		expect(mountChart({ rounds: [{ id: 1, percent: 50, playedAt: 'not a date' }], best: 50 }).find('.date-range').exists()).toBe(false)
	})

	it('treats stored timestamps as UTC and shows them in the viewer time zone', () => {
		const wrapper = mountChart({ rounds: [{ id: 1, percent: 50, playedAt: '2026-10-11 12:00:00' }], best: 50 })

		// the server stores UTC; the same instant parsed explicitly as UTC must match
		const expected = new Date('2026-10-11T12:00:00Z').toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		expect(wrapper.find('rect.bar title').text()).toContain(expected)
	})

	it('gives every bar a tooltip with round number, time and score', () => {
		const wrapper = mountChart({ rounds: [round(1, 40)], best: 40 })

		expect(wrapper.find('rect.bar title').text()).toMatch(/^Round 1, .+: 40%$/)
	})

	it('has an accessible description including the scores', () => {
		const wrapper = mountChart({ rounds: [round(1, 40), round(2, 80)], best: 80 })
		const svg = wrapper.find('svg')

		expect(svg.attributes('role')).toBe('img')
		expect(svg.attributes('aria-label')).toBe('Score per quiz round in percent: 1: 40%, 2: 80%')
	})

	it('thins out the round-number labels for long histories', () => {
		const rounds = Array.from({ length: 30 }, (_, i) => round((i % 28) + 1, 50))
		const wrapper = mountChart({ rounds, best: 50 })

		expect(wrapper.findAll('.bar-label').length).toBeLessThanOrEqual(10)
	})

	it('numbers rounds from firstNumber when the history is cut to the latest rounds', () => {
		const wrapper = mountChart({ rounds: [round(1, 40), round(2, 80)], best: 80, firstNumber: 29 })

		expect(wrapper.findAll('.bar-label').map((l) => l.text())).toEqual(['29', '30'])
		expect(wrapper.find('rect.bar title').text()).toMatch(/^Round 29,/)
		expect(wrapper.find('svg').attributes('aria-label')).toContain('29: 40%, 30: 80%')
	})
})
