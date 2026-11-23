/**
 * Saves an on/off flag per item (the favourite heart, #136), one request at a time per
 * item, so rapid clicks can't race:
 *
 * - Every call shows the wanted value right away (`apply`).
 * - While a save for the item runs, nothing else is sent. When it ends, one more save goes
 *   out only if the wanted value still differs from what the server confirmed, so the
 *   server always ends on the last click and ten quick clicks cost at most two requests.
 * - When a save fails, the item shows the last confirmed value again and `onError` runs.
 *   Saves never overlap, so an older failure can't undo a newer change.
 *
 * @param {object} options - Callbacks
 * @param {(id: number, value: boolean) => Promise<unknown>} options.save - Sends one value to the server
 * @param {(id: number, value: boolean) => void} options.apply - Shows a value in the UI
 * @param {(error: unknown) => void} options.onError - Reports a failed save
 * @return {(id: number, wanted: boolean, current: boolean) => Promise<boolean>} Sets the flag;
 *   `current` is the value shown before this call. Resolves to whether the item ended on the
 *   value the user wanted last.
 */
export function createSerialFlagSaver({ save, apply, onError }) {
	/** @type {Map<number, { confirmed: boolean, wanted: boolean, running: Promise<boolean> | null }>} */
	const entries = new Map()

	return function setFlag(id, wanted, current) {
		let entry = entries.get(id)
		if (!entry) {
			// Nothing in flight for this item: what it shows is what the server has.
			entry = { confirmed: current, wanted, running: null }
			entries.set(id, entry)
		}
		entry.wanted = wanted
		apply(id, wanted)
		if (entry.running) {
			// The running save picks up the newest wanted value when it ends.
			return entry.running
		}

		entry.running = (async () => {
			try {
				while (entry.wanted !== entry.confirmed) {
					const value = entry.wanted
					try {
						await save(id, value)
						entry.confirmed = value
					} catch (error) {
						apply(id, entry.confirmed)
						onError(error)
						return false
					}
				}
				return true
			} finally {
				entries.delete(id)
			}
		})()
		return entry.running
	}
}
