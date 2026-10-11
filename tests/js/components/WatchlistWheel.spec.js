import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import WatchlistWheel from '@/components/WatchlistWheel.vue'

const items = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, title: `Title number ${i + 1} is long` }))
const mountWheel = (n = 4) => mount(WatchlistWheel, { props: { items: items(n) } })

afterEach(() => {
	delete window.matchMedia
	vi.useRealTimers()
	vi.restoreAllMocks()
})

/** Segment that ends up under the pointer (top) for a rotation. */
function winnerAt(rotation, n) {
	const step = 360 / n
	const angleAtTop = ((360 - (rotation % 360)) % 360)
	return Math.floor(angleAtTop / step)
}

describe('WatchlistWheel', () => {
	it('draws one coloured slice and one label per title', () => {
		const wrapper = mountWheel(5)

		expect(wrapper.findAll('.wheel-segment')).toHaveLength(5)
		expect(wrapper.findAll('.wheel-label')).toHaveLength(5)
		const colours = new Set(wrapper.findAll('.wheel-segment').map((s) => s.attributes('fill')))
		expect(colours.size).toBe(5)
	})

	it('shortens long titles on the wheel', () => {
		const labels = mountWheel(2).findAll('.wheel-label').map((l) => l.text())

		expect(labels.every((l) => l.length <= 13)).toBe(true)
		expect(labels[0].endsWith('…')).toBe(true)
	})

	it('draws a single title as a full disc', () => {
		const wrapper = mountWheel(1)

		expect(wrapper.findAll('.wheel-segment')).toHaveLength(0)
		expect(wrapper.find('.wheel-disc circle').exists()).toBe(true)
	})

	it.each([2, 3, 5, 8])('lands the chosen segment under the pointer with %i segments', async (n) => {
		const wrapper = mountWheel(n)
		for (let index = 0; index < n; index++) {
			await wrapper.vm.spinTo(index) // no matchMedia -> jumps immediately
			expect(winnerAt(wrapper.vm.rotation, n)).toBe(index)
		}
	})

	it('always turns forward and at least a few full turns when animating', async () => {
		vi.useFakeTimers()
		window.matchMedia = vi.fn().mockReturnValue({ matches: true })
		const wrapper = mountWheel(6)

		const spin = wrapper.vm.spinTo(2)
		await vi.advanceTimersByTimeAsync(5000)
		await spin

		expect(wrapper.vm.rotation).toBeGreaterThanOrEqual(5 * 360)
		expect(winnerAt(wrapper.vm.rotation, 6)).toBe(2)
	})

	it('animates with a transition and stops when the transition ends', async () => {
		vi.useFakeTimers()
		window.matchMedia = vi.fn().mockReturnValue({ matches: true })
		const wrapper = mountWheel(4)
		let done = false

		const spin = wrapper.vm.spinTo(1).then(() => { done = true })
		await vi.advanceTimersByTimeAsync(10)
		expect(wrapper.find('.wheel-disc').attributes('style')).toContain('transition: transform')
		expect(done).toBe(false)

		await wrapper.find('.wheel-disc').trigger('transitionend')
		await spin

		expect(done).toBe(true)
	})

	it('does not hang when the transition event never arrives', async () => {
		vi.useFakeTimers()
		window.matchMedia = vi.fn().mockReturnValue({ matches: true })
		const wrapper = mountWheel(4)
		let done = false

		wrapper.vm.spinTo(0).then(() => { done = true })
		await vi.advanceTimersByTimeAsync(4600)

		expect(done).toBe(true)
	})

	it('does not animate when reduced motion is preferred', async () => {
		window.matchMedia = vi.fn().mockReturnValue({ matches: false })
		const wrapper = mountWheel(4)

		await wrapper.vm.spinTo(3)

		expect(wrapper.find('.wheel-disc').attributes('style')).toContain('transition: none')
	})

	it('shows the starting position without a transition before it starts turning (second spin)', async () => {
		vi.useFakeTimers()
		window.matchMedia = vi.fn().mockReturnValue({ matches: true })
		const wrapper = mountWheel(4)
		const first = wrapper.vm.spinTo(1)
		await vi.advanceTimersByTimeAsync(5000)
		await first

		// a new selection resets the wheel, then the next spin starts
		await wrapper.setProps({ items: items(4).map((i) => ({ ...i, id: i.id + 10 })) })
		const styles = []
		const disc = wrapper.find('.wheel-disc').element
		const observer = new MutationObserver(() => styles.push(disc.getAttribute('style')))
		observer.observe(disc, { attributes: true, attributeFilter: ['style'] })

		const second = wrapper.vm.spinTo(2)
		await vi.advanceTimersByTimeAsync(10)
		await vi.advanceTimersByTimeAsync(5000)
		await second
		observer.disconnect()

		// the rotation to the new target must never be set in the same style update
		// that switches the transition on
		const turning = styles.findIndex((s) => s.includes('transition: transform'))
		expect(turning).toBeGreaterThan(-1)
		const before = styles.slice(0, turning)
		expect(before.every((s) => s.includes('transition: none'))).toBe(true)
		expect(styles[turning]).not.toMatch(/rotate\(0deg\)/)
		expect(winnerAt(wrapper.vm.rotation, 4)).toBe(2)
	})

	it('starts from a clean wheel when the titles change', async () => {
		const wrapper = mountWheel(4)
		await wrapper.vm.spinTo(2)
		expect(wrapper.vm.rotation).toBeGreaterThan(0)

		await wrapper.setProps({ items: items(3) })

		expect(wrapper.vm.rotation).toBe(0)
	})

	it('emits wheelClick when clicked', async () => {
		const wrapper = mountWheel(3)

		await wrapper.find('.wheel').trigger('click')

		expect(wrapper.emitted('wheelClick')).toHaveLength(1)
	})
})
