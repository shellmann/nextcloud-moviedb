import { describe, expect, it } from 'vitest'
import pkg from '../../../package.json'
import { compareVersions, releaseNotes, unseenNotes } from '@/whatsNew/releaseNotes.js'

const notes = [
	{ version: '1.8.0', items: ['a'] },
	{ version: '1.9.0', items: ['b'] },
	{ version: '1.10.0', items: ['c'] },
]

describe('compareVersions', () => {
	it('compares numerically, not as text', () => {
		expect(compareVersions('1.10.0', '1.9.0')).toBeGreaterThan(0)
		expect(compareVersions('1.9.0', '1.9.0')).toBe(0)
		expect(compareVersions('1.8.9', '1.9.0')).toBeLessThan(0)
	})

	it('treats a pre-release like its release', () => {
		expect(compareVersions('1.6.0-beta.1', '1.6.0')).toBe(0)
	})
})

describe('unseenNotes', () => {
	it('shows the versions after the last seen one, newest first', () => {
		expect(unseenNotes('1.8.0', '1.10.0', notes).map((n) => n.version)).toEqual(['1.10.0', '1.9.0'])
	})

	it('shows nothing when the user is up to date', () => {
		expect(unseenNotes('1.10.0', '1.10.0', notes)).toEqual([])
	})

	it('never shows notes of versions newer than the installed one', () => {
		expect(unseenNotes('1.8.0', '1.9.0', notes).map((n) => n.version)).toEqual(['1.9.0'])
	})

	it('shows nothing without version information', () => {
		expect(unseenNotes('', '1.9.0', notes)).toEqual([])
		expect(unseenNotes('1.8.0', '', notes)).toEqual([])
	})
})

describe('releaseNotes', () => {
	it('has two to four highlights per feature release, one to four per patch release', () => {
		for (const release of releaseNotes()) {
			expect(release.version).toMatch(/^\d+\.\d+\.\d+$/)
			const isPatch = !release.version.endsWith('.0')
			expect(release.items.length).toBeGreaterThanOrEqual(isPatch ? 1 : 2)
			expect(release.items.length).toBeLessThanOrEqual(4)
		}
	})

	it('is sorted newest first', () => {
		const versions = releaseNotes().map((r) => r.version)
		expect([...versions].sort((a, b) => compareVersions(b, a))).toEqual(versions)
	})

	it('has notes for 1.9.0, the first version with the dialog', () => {
		expect(releaseNotes().some((r) => r.version === '1.9.0')).toBe(true)
	})
})

describe('releaseNotes', () => {
	it('has no note for a version newer than the app (it would never be shown)', () => {
		// Patch releases without a visible fix have no note, so the newest note may lag behind; it must never lead.
		expect(compareVersions(releaseNotes()[0].version, pkg.version)).toBeLessThanOrEqual(0)
	})
})
