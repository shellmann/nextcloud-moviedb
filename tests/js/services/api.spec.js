import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

import axios from '@nextcloud/axios'
import api from '@/services/api.js'

describe('api library export / import', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('requests the export as a blob for the given library', () => {
		api.exportLibrary(7)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/export',
			{ params: { libraryId: 7 }, responseType: 'blob' },
		)
	})

	it('omits libraryId when none is given', () => {
		api.exportLibrary()
		expect(axios.get.mock.calls[0][1].params).toEqual({})
	})

	it('uploads the file as multipart field "file" with the library id', () => {
		const file = new File(['{}'], 'x.json', { type: 'application/json' })
		api.importLibrary(file, 7)

		const [url, body, options] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/moviedb/api/import')
		expect(body).toBeInstanceOf(FormData)
		expect(body.get('file')).toBeInstanceOf(File)
		expect(body.get('file').name).toBe('x.json')
		expect(options).toEqual({ params: { libraryId: 7 } })
	})
})

describe('api admin settings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('sends the instance-wide key with PUT', () => {
		api.updateAdminSettings({ tmdbApiKey: 'new-key' })
		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/moviedb/api/admin/settings',
			{ tmdbApiKey: 'new-key' },
		)
	})
})
