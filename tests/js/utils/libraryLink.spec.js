import { showInfo } from '@nextcloud/dialogs'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLibrariesStore } from '@/stores/libraries.js'
import { resolveLibraryQuery } from '@/utils/libraryLink.js'

vi.mock('@nextcloud/dialogs', () => ({ showInfo: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) }))

const route = (query, path = '/tv/5') => ({ path, query, hash: '' })

describe('resolveLibraryQuery', () => {
	let libraries

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal' }, { id: 2, name: 'Family' }]
		libraries.activeLibraryId = 1
		libraries.whenReady = vi.fn().mockResolvedValue()
		libraries.setActive = vi.fn((id) => { libraries.activeLibraryId = id })
	})

	it('lets routes without the query pass untouched', async () => {
		expect(await resolveLibraryQuery(route({}))).toBe(true)
		expect(libraries.setActive).not.toHaveBeenCalled()
	})

	it('switches to an accessible library, announces it and drops the query', async () => {
		const result = await resolveLibraryQuery(route({ library: '2', other: 'x' }))

		expect(libraries.setActive).toHaveBeenCalledWith(2)
		expect(showInfo).toHaveBeenCalledWith('Switched to library "Family"')
		expect(result).toEqual({ path: '/tv/5', query: { other: 'x' }, hash: '', replace: true })
	})

	it('does not announce anything when the library is already active', async () => {
		const result = await resolveLibraryQuery(route({ library: '1' }))

		expect(libraries.setActive).not.toHaveBeenCalled()
		expect(showInfo).not.toHaveBeenCalled()
		expect(result.query).toEqual({})
	})

	it('ignores a library the user cannot access or a junk value, but still cleans the URL', async () => {
		for (const value of ['99', 'abc', '']) {
			const result = await resolveLibraryQuery(route({ library: value }))
			expect(result.query).toEqual({})
		}
		expect(libraries.setActive).not.toHaveBeenCalled()
		expect(showInfo).not.toHaveBeenCalled()
	})

	it('waits until the libraries are loaded', async () => {
		let release
		libraries.whenReady = vi.fn(() => new Promise((resolve) => { release = resolve }))

		const pending = resolveLibraryQuery(route({ library: '2' }))
		await Promise.resolve()
		expect(libraries.setActive).not.toHaveBeenCalled()
		release()
		await pending
		expect(libraries.setActive).toHaveBeenCalledWith(2)
	})
})
