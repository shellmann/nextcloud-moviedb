import axe from 'axe-core'

/**
 * Runs axe-core on a mounted wrapper and fails on any violation.
 * color-contrast is off because jsdom has no layout; contrast is checked in the browser audit.
 * A wrapper that is not attached to the document is attached temporarily (axe needs that).
 */
export async function expectNoAxeViolations(wrapper) {
	const el = wrapper.element
	const attachedHere = !el.isConnected
	if (attachedHere) {
		document.body.appendChild(el)
	}
	try {
		const results = await axe.run(el, {
			rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
		})
		const summary = results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)
		if (summary.length) {
			throw new Error('axe violations:\n' + summary.join('\n'))
		}
	} finally {
		if (attachedHere) {
			el.remove()
		}
	}
}
