import { readdirSync, readFileSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../../..')

function sourceFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return sourceFiles(path)
		}
		return /\.(vue|s?css|js)$/.test(entry.name) ? [path] : []
	})
}

// --color-primary is the admin's theme colour as-is. A dark one disappears on a
// dark background (#105), so styles use --color-primary-element, which Nextcloud
// adjusts for contrast with the current light or dark theme.
describe('theme colours', () => {
	it('never uses the raw --color-primary variable', () => {
		const offenders = sourceFiles(resolve(root, 'src'))
			.filter((file) => /var\(--color-primary\)/.test(readFileSync(file, 'utf8')))
			.map((file) => relative(root, file))

		expect(offenders).toEqual([])
	})
})
