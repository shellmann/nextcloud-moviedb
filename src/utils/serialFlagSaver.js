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
 * - A round of saves belongs to the scope (library) of its first click: every save of the
 *   round goes to that library, even if the user switches libraries meanwhile.
 * - A list loaded while saves run (or answered after a save that it started before) may
 *   carry the old value; `reconcile` puts the saved or wanted value back on those items,
 *   and every successful save shows the newest wanted value again.
 *
 * @param {object} options - Callbacks
 * @param {(id: number, value: boolean, scope: number|null) => Promise<unknown>} options.save - Sends one value
 * @param {(id: number, value: boolean, scope: number|null) => void} options.apply - Shows a value
 * @param {(error: unknown) => void} options.onError - Reports a failed save
 * @return {{
 *   set: (id: number, wanted: boolean, current: boolean, scope: number|null) => Promise<boolean>,
 *   version: () => number,
 *   reconcile: (items: Array<{id: number}>, since: number, scope: number|null, field: string) => Array<object>,
 * }} The saver
 */
export function createSerialFlagSaver({ save, apply, onError }) {
	/** @type {Map<string, { id: number, scope: number|null, confirmed: boolean, wanted: boolean, running: Promise<boolean> | null }>} */
	const rounds = new Map()
	/** @type {Map<string, { value: boolean, seq: number }>} Last value the user wanted or the server confirmed, with when */
	const recent = new Map()
	let seq = 0

	const keyOf = (scope, id) => `${scope ?? ''}:${id}`
	const remember = (key, value) => recent.set(key, { value, seq: ++seq })

	/**
	 * Sets the flag. Resolves to whether the item ended on the value the user wanted last.
	 *
	 * @param {number} id - Item ID
	 * @param {boolean} wanted - The value clicked
	 * @param {boolean} current - The value shown before this click
	 * @param {number|null} scope - Library the item belongs to
	 * @return {Promise<boolean>}
	 */
	function set(id, wanted, current, scope) {
		const key = keyOf(scope, id)
		let round = rounds.get(key)
		if (!round) {
			// Nothing in flight for this item: what it shows is what the server has.
			round = { id, scope, confirmed: current, wanted, running: null }
			rounds.set(key, round)
		}
		round.wanted = wanted
		remember(key, wanted)
		apply(id, wanted, scope)
		if (round.running) {
			// The running save picks up the newest wanted value when it ends.
			return round.running
		}

		round.running = (async () => {
			try {
				while (round.wanted !== round.confirmed) {
					const value = round.wanted
					try {
						await save(id, value, scope)
						round.confirmed = value
						// A list loaded meanwhile may have put the old value back.
						apply(id, round.wanted, scope)
					} catch (error) {
						apply(id, round.confirmed, scope)
						onError(error)
						return false
					}
				}
				return true
			} finally {
				remember(key, round.confirmed)
				rounds.delete(key)
			}
		})()
		return round.running
	}

	/**
	 * A marker to take before loading a list; pass it to reconcile() with the result.
	 *
	 * @return {number}
	 */
	function version() {
		return seq
	}

	/**
	 * Puts the newest value back on items of a freshly loaded list whose flag changed after
	 * the load started (or is still being saved): the server may have answered with the
	 * value from before the save.
	 *
	 * @param {Array<{id: number}>} items - The loaded items
	 * @param {number} since - version() taken before the load started
	 * @param {number|null} scope - Library the list belongs to
	 * @param {string} field - The flag's property on the items
	 * @return {Array<object>} The items, with the flag corrected where needed
	 */
	function reconcile(items, since, scope, field) {
		return items.map((item) => {
			const key = keyOf(scope, item.id)
			const round = rounds.get(key)
			const latest = recent.get(key)
			let value
			if (round) {
				value = round.wanted
			} else if (latest && latest.seq > since) {
				value = latest.value
			} else {
				return item
			}
			return Boolean(item[field]) === value ? item : { ...item, [field]: value }
		})
	}

	return { set, version, reconcile }
}
