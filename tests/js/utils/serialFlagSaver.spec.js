import { describe, expect, it, vi } from 'vitest'
import { createSerialFlagSaver } from '@/utils/serialFlagSaver.js'

/** A save whose calls resolve or reject only when the test says so. */
function controllableSave() {
	const calls = []
	const save = vi.fn((id, value) => new Promise((resolve, reject) => {
		calls.push({ id, value, resolve, reject })
	}))
	return { save, calls }
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('createSerialFlagSaver (#150 review: rapid favourite clicks)', () => {
	it('shows each click at once but sends one request at a time, ending on the last click', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const setFlag = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError: vi.fn() })

		const first = setFlag(1, true, false)
		const second = setFlag(1, false, true)
		expect(shown).toEqual([true, false])
		expect(save).toHaveBeenCalledTimes(1) // the second click waits for the first save

		calls[0].resolve()
		await flush()
		expect(save).toHaveBeenCalledTimes(2)
		expect(calls[1].value).toBe(false)

		calls[1].resolve()
		expect(await first).toBe(true)
		expect(await second).toBe(true)
	})

	it('coalesces clicks: back to the confirmed value needs no extra request', async () => {
		const { save, calls } = controllableSave()
		const setFlag = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		setFlag(1, true, false)
		setFlag(1, false, true)
		const last = setFlag(1, true, false)

		calls[0].resolve() // the server now has true, the last click wanted true
		expect(await last).toBe(true)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('a failed save shows the last saved value and drops clicks made meanwhile', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const onError = vi.fn()
		const setFlag = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError })

		const first = setFlag(1, true, false)
		setFlag(1, false, true)
		setFlag(1, true, false)
		calls[0].reject(new Error('offline'))

		expect(await first).toBe(false)
		expect(shown.at(-1)).toBe(false) // what the server still has
		expect(onError).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('an older failure can never undo a newer saved change', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const setFlag = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError: vi.fn() })

		setFlag(1, true, false)
		calls[0].resolve()
		await flush()
		// A new round: the server has true now.
		const next = setFlag(1, false, true)
		calls[1].reject(new Error('offline'))

		expect(await next).toBe(false)
		expect(shown.at(-1)).toBe(true)
	})

	it('saves different items independently', async () => {
		const { save, calls } = controllableSave()
		const setFlag = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		setFlag(1, true, false)
		setFlag(2, true, false)
		expect(save).toHaveBeenCalledTimes(2)
		calls.forEach((c) => c.resolve())
	})

	it('starts fresh after a round ends', async () => {
		const { save, calls } = controllableSave()
		const setFlag = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		const first = setFlag(1, true, false)
		calls[0].resolve()
		await first
		setFlag(1, false, true)
		expect(save).toHaveBeenCalledTimes(2)
		expect(calls[1].value).toBe(false)
		calls[1].resolve()
	})
})
