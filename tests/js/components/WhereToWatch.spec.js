import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WhereToWatch from '@/components/WhereToWatch.vue'
import { expectNoAxeViolations } from '../a11y.js'

const watch = (extra = {}) => ({
	region: 'DE',
	link: 'https://www.themoviedb.org/movie/1/watch?locale=DE',
	stream: [{ id: 8, name: 'Netflix', logoPath: '/n.jpg' }, { id: 9, name: 'Prime Video', logoPath: '/p.jpg' }],
	free: [{ id: 20, name: 'ARD Mediathek', logoPath: null }],
	rent: [],
	buy: [],
	...extra,
})

const mountIt = (props) => mount(WhereToWatch, { props, attachTo: document.body })

describe('WhereToWatch', () => {
	it('shows nothing before the offers are known', () => {
		expect(mountIt({ watch: null }).find('.where-to-watch').exists()).toBe(false)
	})

	it('lists the groups that have offers, with the country', () => {
		const wrapper = mountIt({ watch: watch() })

		expect(wrapper.find('.region-note').text()).toBe('Offers in Germany')
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Streaming', 'Free'])
		const logos = wrapper.findAll('.provider img')
		expect(logos.map((i) => i.attributes('alt'))).toEqual(['Netflix', 'Prime Video'])
		expect(logos[0].attributes('src')).toBe('https://image.tmdb.org/t/p/w92/n.jpg')
		// A service without a logo is still named.
		expect(wrapper.findAll('.provider')[2].text()).toBe('ARD Mediathek')
	})

	it('says so when there are no offers in the country', () => {
		const wrapper = mountIt({ watch: watch({ stream: [], free: [] }) })

		expect(wrapper.find('.no-offers').text()).toBe('No streaming offers known for Germany.')
		expect(wrapper.find('h4').exists()).toBe(false)
	})

	it('credits JustWatch and links to the TMDB page in a new tab', () => {
		const links = mountIt({ watch: watch() }).findAll('.sources a')

		expect(links.map((a) => a.text())).toEqual(['All offers on TMDB', 'Streaming data from JustWatch'])
		expect(links[0].attributes('href')).toBe('https://www.themoviedb.org/movie/1/watch?locale=DE')
		expect(links[1].attributes('href')).toBe('https://www.justwatch.com/')
		for (const link of links) {
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toContain('noopener')
		}
	})

	it('omits the TMDB link when TMDB gave none, but keeps the JustWatch credit', () => {
		const links = mountIt({ watch: watch({ link: null }) }).findAll('.sources a')

		expect(links.map((a) => a.text())).toEqual(['Streaming data from JustWatch'])
	})

	it('falls back to the region code for an unknown region', () => {
		expect(mountIt({ watch: watch({ region: 'not a region' }) }).find('.region-note').text()).toContain('not a region')
	})

	it('has unique heading ids and no axe violations', async () => {
		const first = mountIt({ watch: watch() })
		const second = mountIt({ watch: watch() })

		expect(first.find('h3').attributes('id')).not.toBe(second.find('h3').attributes('id'))
		await expectNoAxeViolations(first)
		await expectNoAxeViolations(mountIt({ watch: watch({ stream: [], free: [] }) }))
	})
})
