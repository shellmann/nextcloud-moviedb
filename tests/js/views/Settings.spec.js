import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'

vi.mock('@nextcloud/vue', () => ({
	NcTextField: { template: '<input />' },
	NcSelect: { template: '<div />' },
	NcButton: { template: '<button><slot /></button>' },
	NcDialog: {
		props: ['open', 'name'],
		template: '<div v-if="open" class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>',
	},
}))

// Settings.vue also needs imagePath, which the global router mock lacks.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
	imagePath: (app, file) => `/img/${file}`,
}))

vi.mock('@/services/api.js', () => ({
	default: {
		exportLibrary: vi.fn(),
		importLibrary: vi.fn(),
	},
}))

import api from '@/services/api.js'
import Settings from '@/views/Settings.vue'
import { useLibrariesStore } from '@/stores/libraries.js'
import { useMoviesStore } from '@/stores/movies.js'
import { usePlatformsStore } from '@/stores/platforms.js'
import { useSeriesStore } from '@/stores/series.js'
import { useSettingsStore } from '@/stores/settings.js'
import { useWatchlistStore } from '@/stores/watchlist.js'

const IconStub = { template: '<span />' }

const exportFile = (extra = {}) => ({
	app: 'moviedb',
	formatVersion: 1,
	movies: [{}, {}],
	series: [{ episodes: [{}, {}, {}] }],
	watchlist: [{}],
	...extra,
})

const fileOf = (data) => ({ text: async () => (typeof data === 'string' ? data : JSON.stringify(data)) })

describe('Settings import / export', () => {
	let wrapper
	let libraries

	const mountSettings = () => mount(Settings, {
		global: {
			stubs: {
				Eye: IconStub, EyeOff: IconStub, ContentSave: IconStub, Delete: IconStub,
				Download: IconStub, Upload: IconStub, Plus: IconStub,
			},
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text) },
		},
	})

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		for (const store of [useMoviesStore(), useSeriesStore(), useWatchlistStore(), usePlatformsStore()]) {
			store.fetchAll = vi.fn().mockResolvedValue()
		}
		wrapper = mountSettings()
	})

	const chooseFile = async (data) => {
		await wrapper.vm.onImportFileChosen({ target: { files: [fileOf(data)], value: 'x' } })
		await flushPromises()
	}

	it('shows no dialog on load', () => {
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('opens a confirmation with the counts from the file', async () => {
		await chooseFile(exportFile())

		const dialog = wrapper.find('.dialog')
		expect(dialog.exists()).toBe(true)
		expect(dialog.text()).toContain('Import into Personal?')
		expect(dialog.text()).toContain('This file contains:')
		expect(dialog.text()).toContain('Movies: 2')
		expect(dialog.text()).toContain('TV shows: 1, episodes: 3')
		expect(dialog.text()).toContain('Watchlist items: 1')
		expect(dialog.text()).not.toContain('may be shared')
	})

	it('names the source library and export time when the file has them', async () => {
		await chooseFile(exportFile({ library: { name: 'Family' }, exportedAt: '2026-09-29T19:36:12+02:00' }))
		expect(wrapper.find('.import-source').text()).toMatch(/^Exported from Family on .+2026/)

		await chooseFile(exportFile({ library: { name: 'Family' } }))
		expect(wrapper.find('.import-source').text()).toBe('Exported from Family')
	})

	it('omits the source line for files without a library name', async () => {
		await chooseFile(exportFile())
		expect(wrapper.find('.import-source').exists()).toBe(false)
	})

	it('warns that other members will see items in a shared library', async () => {
		libraries.libraries = [{ id: 2, name: 'Family', isPersonal: false, role: 'editor', permissionEdit: true }]
		libraries.activeLibraryId = 2
		await chooseFile(exportFile())

		expect(wrapper.find('.dialog').text()).toContain('other members will see the imported items')
	})

	it('refuses an oversized file without reading it', async () => {
		const text = vi.fn()
		await wrapper.vm.onImportFileChosen({ target: { files: [{ size: 25 * 1024 * 1024 + 1, text }], value: 'x' } })
		await flushPromises()

		expect(text).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalledWith('The file is too large.')
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('accepts a file exactly at the size limit', async () => {
		const file = { size: 25 * 1024 * 1024, text: async () => JSON.stringify(exportFile()) }
		await wrapper.vm.onImportFileChosen({ target: { files: [file], value: 'x' } })
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(true)
	})

	it('rejects a file that is not a MovieDB export without opening the dialog', async () => {
		await chooseFile({ foo: 'bar' })
		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(showError).toHaveBeenCalledWith('This is not a MovieDB export file.')

		await chooseFile('not json at all')
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('tells the user when the file comes from a newer version', async () => {
		await chooseFile(exportFile({ formatVersion: 2 }))
		expect(showError).toHaveBeenCalledWith(expect.stringContaining('newer version'))
	})

	it('disables import when the user cannot edit the library', async () => {
		libraries.libraries = [{ id: 3, name: 'Shared', isPersonal: false, role: 'viewer', permissionEdit: false }]
		libraries.activeLibraryId = 3
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canEdit).toBe(false)
		expect(wrapper.text()).toContain('You need edit permission for this library to import.')
	})

	it('imports into the active library, shows the result and refreshes the stores', async () => {
		api.importLibrary.mockResolvedValue({
			data: {
				imported: { movies: 2, series: 1, episodes: 3, watchlist: 1, watches: 4, platforms: 0 },
				skippedDuplicates: { movies: 1, series: 0, watchlist: 2 },
				invalid: { movies: 1, watches: 0 },
			},
		})
		await chooseFile(exportFile())
		await wrapper.vm.confirmImport()
		await flushPromises()

		expect(api.importLibrary).toHaveBeenCalledWith(expect.anything(), 1)
		expect(useMoviesStore().fetchAll).toHaveBeenCalled()
		expect(useSeriesStore().fetchAll).toHaveBeenCalled()
		expect(useWatchlistStore().fetchAll).toHaveBeenCalled()
		expect(usePlatformsStore().fetchAll).toHaveBeenCalled()

		const text = wrapper.find('.dialog').text()
		expect(text).toContain('Import finished')
		expect(text).toContain('Imported:')
		expect(text).toContain('Movies: 2')
		expect(text).toContain('TV shows: 1, episodes: 3')
		expect(text).toContain('Watches: 4')
		expect(text).toContain('Skipped (already in the library): 3')
		expect(text).toContain('Ignored invalid entries: 1')
	})

	it.each([
		[413, 'The file is too large.'],
		[429, 'Too many imports. Please try again later.'],
		[400, 'The file is not a valid MovieDB export.'],
		[500, 'The import failed. Nothing was changed.'],
	])('maps a %i response to a readable error', async (status, message) => {
		api.importLibrary.mockRejectedValue({ response: { status } })
		await chooseFile(exportFile())
		await wrapper.vm.confirmImport()
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(message)
		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(useMoviesStore().fetchAll).not.toHaveBeenCalled()
	})

	it('cancelling drops the chosen file without calling the API', async () => {
		await chooseFile(exportFile())
		wrapper.vm.cancelImport()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		await wrapper.vm.confirmImport()
		expect(api.importLibrary).not.toHaveBeenCalled()
	})

	it('exports the active library as a download', async () => {
		const createObjectURL = vi.fn(() => 'blob:x')
		globalThis.URL.createObjectURL = createObjectURL
		globalThis.URL.revokeObjectURL = vi.fn()
		api.exportLibrary.mockResolvedValue({
			data: new Blob(['{}']),
			headers: { 'content-disposition': 'attachment; filename="moviedb-Personal-2026-09-29.json"' },
		})

		await wrapper.vm.exportLibrary()

		expect(api.exportLibrary).toHaveBeenCalledWith(1)
		expect(createObjectURL).toHaveBeenCalled()
		expect(showSuccess).toHaveBeenCalledWith('Library exported.')
	})

	it('shows an error and re-enables export when the export fails', async () => {
		api.exportLibrary.mockRejectedValue(new Error('network'))

		await wrapper.vm.exportLibrary()

		expect(showError).toHaveBeenCalledWith('Failed to export the library. Please try again.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.exporting).toBe(false)
	})
})

describe('Settings TMDB API key', () => {
	let wrapper
	let settings

	const mountSettings = () => mount(Settings, {
		global: {
			stubs: {
				Eye: IconStub, EyeOff: IconStub, ContentSave: IconStub, Delete: IconStub,
				Download: IconStub, Upload: IconStub, Plus: IconStub,
			},
			mocks: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text) },
		},
	})

	const givenKeys = async ({ user = false, instance = false }) => {
		settings.hasUserApiKey = user
		settings.hasInstanceApiKey = instance
		settings.hasApiKey = user || instance
		await wrapper.vm.$nextTick()
	}

	const removeButton = () => wrapper.findAll('button').find((b) => b.text() === 'Remove API Key')

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		const libraries = useLibrariesStore()
		libraries.libraries = [{ id: 1, name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }]
		libraries.activeLibraryId = 1
		settings = useSettingsStore()
		settings.update = vi.fn().mockResolvedValue()
		wrapper = mountSettings()
	})

	it('shows the own key with a remove button', async () => {
		await givenKeys({ user: true, instance: true })

		expect(wrapper.find('.api-key-status').text()).toBe('API key configured')
		expect(removeButton()).toBeTruthy()
	})

	it('shows the instance-wide key without a remove button', async () => {
		await givenKeys({ instance: true })

		expect(wrapper.find('.api-key-status').text()).toBe('Using the instance-wide API key')
		expect(wrapper.find('.section-description').text()).toContain('Your administrator has set up a TMDB API key for everyone.')
		expect(wrapper.find('.section-description').text()).toContain('a different one. Get your API key here')
		expect(removeButton()).toBeUndefined()
	})

	it('shows that no key is set', async () => {
		await givenKeys({})

		expect(wrapper.find('.api-key-status').text()).toBe('No API key')
		expect(wrapper.find('.section-description').text()).toContain('you need a free TMDB API key. Get your API key here')
		expect(removeButton()).toBeUndefined()
	})

	it('saves a new key and clears the field', async () => {
		wrapper.vm.tmdbApiKey = 'new-key'

		await wrapper.vm.saveSettings()

		expect(settings.update).toHaveBeenCalledWith(expect.objectContaining({ tmdbApiKey: 'new-key' }))
		expect(showSuccess).toHaveBeenCalledWith('Settings saved successfully.')
		expect(wrapper.vm.tmdbApiKey).toBe('')
	})

	it('explains a key that TMDB did not accept', async () => {
		settings.update = vi.fn().mockRejectedValue({ response: { status: 422 } })
		wrapper.vm.tmdbApiKey = 'wrong-key'

		await wrapper.vm.saveSettings()

		expect(showError).toHaveBeenCalledWith('TMDB did not accept this API key. Make sure you use the API Read Access Token.')
		expect(wrapper.vm.tmdbApiKey).toBe('wrong-key')
	})

	it('explains when TMDB could not be reached', async () => {
		settings.update = vi.fn().mockRejectedValue({ response: { status: 502 } })
		wrapper.vm.tmdbApiKey = 'new-key'

		await wrapper.vm.saveSettings()

		expect(showError).toHaveBeenCalledWith('Could not reach TMDB to check the API key. Please try again.')
	})

	it('falls back to the generic message for other errors', async () => {
		settings.update = vi.fn().mockRejectedValue({ response: { status: 400 } })

		await wrapper.vm.saveSettings()

		expect(showError).toHaveBeenCalledWith('Failed to save settings. Please try again.')
	})
})
