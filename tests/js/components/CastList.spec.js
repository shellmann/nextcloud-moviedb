import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { expectNoAxeViolations } from '../a11y.js'
import CastList from '@/components/CastList.vue'

const actors = (n) => Array.from({ length: n }, (_, i) => ({ name: `Actor ${i + 1}`, character: `Role ${i + 1}` }))

describe('CastList', () => {
	it('renders a labelled section with a list of the first six actors', () => {
		const wrapper = mount(CastList, { props: { cast: actors(9) } })

		const section = wrapper.find('section')
		const heading = wrapper.find('h3')
		expect(section.attributes('aria-labelledby')).toBe(heading.attributes('id'))
		expect(wrapper.findAll('ul > li')).toHaveLength(6)
		expect(wrapper.find('li').text()).toContain('Actor 1')
		expect(wrapper.find('li').text()).toContain('Role 1')
	})

	it('renders nothing for an empty cast or entries without a name', () => {
		expect(mount(CastList, { props: { cast: [] } }).find('section').exists()).toBe(false)
		expect(mount(CastList, { props: { cast: [{ character: 'X' }, null] } }).find('section').exists()).toBe(false)
	})

	it('omits the role when there is none', () => {
		const wrapper = mount(CastList, { props: { cast: [{ name: 'Solo' }] } })
		expect(wrapper.find('.cast-character').exists()).toBe(false)
	})

	it('gives every instance its own heading id', () => {
		const a = mount(CastList, { props: { cast: actors(1) } })
		const b = mount(CastList, { props: { cast: actors(1) } })
		expect(a.find('h3').attributes('id')).not.toBe(b.find('h3').attributes('id'))
	})

	it('has no axe violations', async () => {
		const wrapper = mount(CastList, { props: { cast: actors(3) }, attachTo: document.body })
		await expectNoAxeViolations(wrapper)
		wrapper.unmount()
	})
})
