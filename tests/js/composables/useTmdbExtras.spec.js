import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { useTmdbExtras } from '@/composables/useTmdbExtras.js'
import api from '@/services/api.js'

function host(title, language) {
	let result
	mount(defineComponent({
		setup() {
			result = useTmdbExtras(() => title.value, () => language.value)
			return () => null
		},
	}))
	return result.extras
}

const reply = (trailerKey) => ({ data: { trailer: { key: trailerKey }, watch: null } })

describe('useTmdbExtras', () => {
	afterEach(() => vi.restoreAllMocks())

	it('loads the extras of the title in the user language', async () => {
		vi.spyOn(api, 'getTmdbExtras').mockResolvedValue(reply('a'))
		const extras = host(ref({ type: 'movie', tmdbId: 5 }), ref('de-DE'))
		await flushPromises()

		expect(api.getTmdbExtras).toHaveBeenCalledWith('movie', '5', 'de-DE')
		expect(extras.value.trailer.key).toBe('a')
	})

	it('asks nothing without a title or TMDB id', async () => {
		vi.spyOn(api, 'getTmdbExtras')
		const extras = host(ref(null), ref('de-DE'))
		host(ref({ type: 'tv', tmdbId: null }), ref('de-DE'))
		await flushPromises()

		expect(api.getTmdbExtras).not.toHaveBeenCalled()
		expect(extras.value).toBeNull()
	})

	it('stays empty when the request fails', async () => {
		vi.spyOn(api, 'getTmdbExtras').mockRejectedValue(new Error('offline'))
		const extras = host(ref({ type: 'tv', tmdbId: 1 }), ref('de-DE'))
		await flushPromises()

		expect(extras.value).toBeNull()
	})

	it('drops the old title at once and ignores a slow answer for it', async () => {
		let answerFirst
		vi.spyOn(api, 'getTmdbExtras')
			.mockImplementationOnce(() => new Promise((resolve) => { answerFirst = resolve }))
			.mockResolvedValueOnce(reply('second'))
		const title = ref({ type: 'movie', tmdbId: 1 })
		const extras = host(title, ref('de-DE'))

		title.value = { type: 'movie', tmdbId: 2 }
		await flushPromises()
		expect(extras.value.trailer.key).toBe('second')

		answerFirst(reply('first'))
		await flushPromises()
		expect(extras.value.trailer.key).toBe('second')
	})

	it('reloads when the language changes', async () => {
		vi.spyOn(api, 'getTmdbExtras').mockResolvedValue(reply('x'))
		const language = ref('de-DE')
		host(ref({ type: 'movie', tmdbId: 1 }), language)
		await flushPromises()

		language.value = 'en-US'
		await flushPromises()

		expect(api.getTmdbExtras).toHaveBeenLastCalledWith('movie', '1', 'en-US')
	})
})
