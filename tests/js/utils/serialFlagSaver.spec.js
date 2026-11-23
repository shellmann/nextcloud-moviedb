import { describe, expect, it, vi } from 'vitest'
import { createSerialFlagSaver } from '@/utils/serialFlagSaver.js'

/** A save whose calls resolve or reject only when the test says so. */
function controllableSave() {
	const calls = []
	const save = vi.fn((id, value, scope) => new Promise((resolve, reject) => {
		calls.push({ id, value, scope, resolve, reject })
	}))
	return { save, calls }
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const LIB = 7

describe('createSerialFlagSaver (#150 review: rapid favourite clicks)', () => {
	it('shows each click at once but sends one request at a time, ending on the last click', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const saver = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError: vi.fn() })

		const first = saver.set(1, true, false, LIB)
		const second = saver.set(1, false, true, LIB)
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
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		saver.set(1, true, false, LIB)
		saver.set(1, false, true, LIB)
		const last = saver.set(1, true, false, LIB)

		calls[0].resolve() // the server now has true, the last click wanted true
		expect(await last).toBe(true)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('a failed save shows the last saved value and drops clicks made meanwhile', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const onError = vi.fn()
		const saver = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError })

		const first = saver.set(1, true, false, LIB)
		saver.set(1, false, true, LIB)
		saver.set(1, true, false, LIB)
		calls[0].reject(new Error('offline'))

		expect(await first).toBe(false)
		expect(shown.at(-1)).toBe(false) // what the server still has
		expect(onError).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('an older failure can never undo a newer saved change', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const saver = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError: vi.fn() })

		saver.set(1, true, false, LIB)
		calls[0].resolve()
		await flush()
		const next = saver.set(1, false, true, LIB)
		calls[1].reject(new Error('offline'))

		expect(await next).toBe(false)
		expect(shown.at(-1)).toBe(true)
	})

	it('saves different items independently', () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		saver.set(1, true, false, LIB)
		saver.set(2, true, false, LIB)
		expect(save).toHaveBeenCalledTimes(2)
		calls.forEach((c) => c.resolve())
	})

	it('starts fresh after a round ends', async () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		const first = saver.set(1, true, false, LIB)
		calls[0].resolve()
		await first
		saver.set(1, false, true, LIB)
		expect(save).toHaveBeenCalledTimes(2)
		expect(calls[1].value).toBe(false)
		calls[1].resolve()
	})
})

describe('createSerialFlagSaver library scope (#150 review P1)', () => {
	it('sends every save of a round to the library of its first click', async () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		saver.set(1, true, false, LIB)
		saver.set(1, false, true, LIB) // queued; the user may switch libraries now
		calls[0].resolve()
		await flush()

		expect(calls.map((c) => c.scope)).toEqual([LIB, LIB])
		calls[1].resolve()
	})

	it('keeps the same id in two libraries apart', () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })

		saver.set(1, true, false, LIB)
		saver.set(1, true, false, 8)
		expect(calls.map((c) => c.scope)).toEqual([LIB, 8])
		calls.forEach((c) => c.resolve())
	})
})

describe('createSerialFlagSaver and list loads (#150 review P2)', () => {
	it('shows the newest value again after a save, even if a load put the old one back', async () => {
		const { save, calls } = controllableSave()
		const shown = []
		const saver = createSerialFlagSaver({ save, apply: (id, v) => shown.push(v), onError: vi.fn() })

		const done = saver.set(1, true, false, LIB)
		shown.push(false) // a list load replaced the tile with the server's old value
		calls[0].resolve()
		await done

		expect(shown.at(-1)).toBe(true)
	})

	it('reconcile keeps the wanted value of an item that is still being saved', () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })
		const since = saver.version()
		saver.set(1, true, false, LIB)

		const items = saver.reconcile([{ id: 1, isFavorite: false }, { id: 2, isFavorite: false }], since, LIB, 'isFavorite')

		expect(items).toEqual([{ id: 1, isFavorite: true }, { id: 2, isFavorite: false }])
		calls[0].resolve()
	})

	it('reconcile fixes a load that started before a save finished and answered after it', async () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })
		const done = saver.set(1, true, false, LIB)
		const since = saver.version() // load starts while the save runs
		calls[0].resolve()
		await done // the round is over before the (stale) answer arrives

		expect(saver.reconcile([{ id: 1, isFavorite: false }], since, LIB, 'isFavorite')).toEqual([{ id: 1, isFavorite: true }])
	})

	it('reconcile trusts a load that started after the save finished', async () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })
		const done = saver.set(1, true, false, LIB)
		calls[0].resolve()
		await done
		const since = saver.version()

		// Someone else changed it since: the fresh answer wins.
		expect(saver.reconcile([{ id: 1, isFavorite: false }], since, LIB, 'isFavorite')).toEqual([{ id: 1, isFavorite: false }])
	})

	it('reconcile ignores saves in another library', () => {
		const { save, calls } = controllableSave()
		const saver = createSerialFlagSaver({ save, apply: vi.fn(), onError: vi.fn() })
		const since = saver.version()
		saver.set(1, true, false, 8)

		expect(saver.reconcile([{ id: 1, isFavorite: false }], since, LIB, 'isFavorite')).toEqual([{ id: 1, isFavorite: false }])
		calls[0].resolve()
	})
})
