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

	it('shows the date only where the day changes, so same-day rounds stay readable', () => {
		const same = (id) => ({ id, percent: 50, playedAt: `2026-10-11 1${id}:00:00` })
		const wrapper = mountChart({ rounds: [same(1), same(2), same(3), { id: 4, percent: 60, playedAt: '2026-10-12 09:00:00' }], best: 60 })

		expect(wrapper.findAll('.bar-date')).toHaveLength(2)

		// the first date is centred under bars 1-3, the second under bar 4
		const { bars, barWidth } = wrapper.vm
		const firstCentre = (bars[0].x + bars[2].x + barWidth) / 2
		const [first, second] = wrapper.findAll('.bar-date')
		expect(Number(first.attributes('x'))).toBeCloseTo(firstCentre)
		expect(first.attributes('text-anchor')).toBe('middle')
		expect(Number(second.attributes('x'))).toBeCloseTo(bars[3].x + barWidth / 2)
		expect(wrapper.findAll('.bar-label:not(.bar-date)').map((l) => l.text())).toEqual(['1', '2', '3', '4'])
	})

	it('treats stored timestamps as UTC and shows them in the viewer time zone', () => {
		const wrapper = mountChart({ rounds: [{ id: 1, percent: 50, playedAt: '2026-10-11 12:00:00' }], best: 50 })

		// the server stores UTC; the same instant parsed explicitly as UTC must match
		const expected = new Date('2026-10-11T12:00:00Z').toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		expect(wrapper.find('rect.bar title').text()).toContain(expected)
	})

	it('groups rounds by the local day, not the UTC day', () => {
		// 23:30 UTC and 00:30 UTC next day: the same local day in a zone like UTC-1 .. but never assume the zone,
		// derive the expectation the same way the viewer's browser does
		const rounds = [
			{ id: 1, percent: 10, playedAt: '2026-10-11 23:30:00' },
			{ id: 2, percent: 20, playedAt: '2026-10-12 00:30:00' },
		]
		const wrapper = mountChart({ rounds, best: 20 })
		const day = (s) => new Date(s.replace(' ', 'T') + 'Z').toDateString()

		expect(wrapper.findAll('.bar-date')).toHaveLength(day(rounds[0].playedAt) === day(rounds[1].playedAt) ? 1 : 2)
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

		expect(wrapper.findAll('.bar-label:not(.bar-date)').length).toBeLessThanOrEqual(10)
	})

	it('numbers rounds from firstNumber when the history is cut to the latest rounds', () => {
		const wrapper = mountChart({ rounds: [round(1, 40), round(2, 80)], best: 80, firstNumber: 29 })

		expect(wrapper.findAll('.bar-label:not(.bar-date)').map((l) => l.text())).toEqual(['29', '30'])
		expect(wrapper.find('rect.bar title').text()).toMatch(/^Round 29,/)
		expect(wrapper.find('svg').attributes('aria-label')).toContain('29: 40%, 30: 80%')
	})

	it('skips date labels that would overlap, but keeps the first', () => {
		const rounds = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, percent: 50, playedAt: `2026-09-${String(10 + i).padStart(2, '0')} 12:00:00` }))
		const wrapper = mountChart({ rounds, best: 50 })
		const xs = wrapper.findAll('.bar-date').map((d) => Number(d.attributes('x')))

		expect(xs.length).toBeGreaterThan(0)
		expect(xs.length).toBeLessThan(12)
		expect(xs[0]).toBe(wrapper.vm.dayGroups[0].x)
		for (let i = 1; i < xs.length; i++) {
			expect(xs[i] - xs[i - 1]).toBeGreaterThanOrEqual(52)
		}
	})
})
