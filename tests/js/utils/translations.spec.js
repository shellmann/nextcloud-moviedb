import { describe, expect, it, vi } from 'vitest'

const { translate, translatePlural } = vi.hoisted(() => ({
	translate: vi.fn((appName, text, vars) => vars
		? text.replace(/\{([^{}]+)\}/g, (_, key) => vars[key])
		: text),
	translatePlural: vi.fn((appName, singular, plural, count, vars) =>
		(count === 1 ? singular : plural).replace(/\{([^{}]+)\}/g, (_, key) => vars[key])),
}))

vi.mock('@nextcloud/l10n', () => ({ translate, translatePlural }))

import { translateText, translateTextPlural } from '@/utils/translations.js'

describe('translation wrappers', () => {
	it('forwards placeholder variables to translate', () => {
		expect(translateText('moviedb', 'Page {page} of {total}', { page: 2, total: 4 }))
			.toBe('Page 2 of 4')
		expect(translate).toHaveBeenCalledWith('moviedb', 'Page {page} of {total}', { page: 2, total: 4 }, undefined, undefined)
	})

	it('forwards placeholder variables to translatePlural', () => {
		expect(translateTextPlural('moviedb', '{n} movie', '{n} movies', 2, { n: 2 }))
			.toBe('2 movies')
		expect(translatePlural).toHaveBeenCalledWith('moviedb', '{n} movie', '{n} movies', 2, { n: 2 }, undefined)
	})
})
