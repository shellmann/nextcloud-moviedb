import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `T:${text}` }))

import { statusLabel, typeLabel } from '@/utils/tmdbLabels.js'

describe('tmdbLabels', () => {
	it('translates the known TMDB statuses', () => {
		for (const s of ['Returning Series', 'Planned', 'In Production', 'Ended', 'Canceled', 'Pilot']) {
			expect(statusLabel(s)).toBe(`T:${s}`)
		}
	})

	it('translates the known TMDB types', () => {
		for (const s of ['Scripted', 'Miniseries', 'Documentary', 'Reality', 'News', 'Talk Show', 'Video']) {
			expect(typeLabel(s)).toBe(`T:${s}`)
		}
	})

	it('shows unknown values as they are', () => {
		expect(statusLabel('Something New')).toBe('Something New')
		expect(typeLabel('Anthology')).toBe('Anthology')
		expect(statusLabel(undefined)).toBeUndefined()
	})
})
