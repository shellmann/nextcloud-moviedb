import { describe, expect, it } from 'vitest'
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
	it('has two to four highlights per version and valid versions', () => {
		for (const release of releaseNotes()) {
			expect(release.version).toMatch(/^\d+\.\d+\.\d+$/)
			expect(release.items.length).toBeGreaterThanOrEqual(2)
			expect(release.items.length).toBeLessThanOrEqual(4)
		}
	})

	it('has notes for 1.9.0, the first version with the dialog', () => {
		expect(releaseNotes().some((r) => r.version === '1.9.0')).toBe(true)
	})
})
