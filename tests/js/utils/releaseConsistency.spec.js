import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import pkg from '../../../package.json'

const read = (file) => readFileSync(resolve(__dirname, '../../..', file), 'utf8')
const [major, minor] = pkg.version.split('.')

// Guards the places a release bump tends to miss (see the release checklist in the wiki).
describe('release consistency', () => {
	it('has the same version in info.xml and package.json', () => {
		expect(read('appinfo/info.xml')).toContain(`<version>${pkg.version}</version>`)
	})

	it('has a CHANGELOG section for the current version', () => {
		expect(read('CHANGELOG.md')).toMatch(new RegExp(`^## \\[${pkg.version.replaceAll('.', '\\.')}\\] - \\d{4}-\\d{2}-\\d{2}$`, 'm'))
	})

	it('lists the current minor line as supported in SECURITY.md', () => {
		const security = read('SECURITY.md')
		expect(security).toMatch(new RegExp(`^\\| ${major}\\.${minor}\\.x\\s+\\| :white_check_mark: \\|$`, 'm'))
		expect(security).toMatch(new RegExp(`^\\| < ${major}\\.${minor}\\s+\\| :x:\\s+\\|$`, 'm'))
	})
})
