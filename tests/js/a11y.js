import axe from 'axe-core'

/**
 * Runs axe-core on a mounted wrapper and fails on any violation.
 * color-contrast is off because jsdom has no layout; contrast is checked in the browser audit.
 */
export async function expectNoAxeViolations(wrapper) {
	const results = await axe.run(wrapper.element, {
		rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
	})
	const summary = results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)
	if (summary.length) {
		throw new Error('axe violations:\n' + summary.join('\n'))
	}
}
