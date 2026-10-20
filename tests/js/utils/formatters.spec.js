import { describe, expect, it } from 'vitest'
import { episodeCode, formatDate, formatRuntime, relativeAirDate, todayLocal } from '@/utils/formatters.js'

describe('Formatters', () => {
	describe('formatDate', () => {
		it('should format ISO date string', () => {
			const result = formatDate('2024-03-15')
			expect(result).toMatch(/15/)
			expect(result).toMatch(/2024/)
		})

		it('should return empty string for null', () => {
			expect(formatDate(null)).toBe('')
		})

		it('should return empty string for undefined', () => {
			expect(formatDate(undefined)).toBe('')
		})

		it('should return empty string for empty string', () => {
			expect(formatDate('')).toBe('')
		})
	})

	describe('calendar dates and time zones', () => {
		it('shows a plain date as that very day, whatever the time zone', () => {
			// Parsed as local midnight, never as midnight UTC (which is the previous day west of UTC).
			const shown = formatDate('2026-10-11', { day: 'numeric', month: 'numeric', year: 'numeric' })
			const expected = new Date(2026, 9, 11).toLocaleDateString('en-US', { day: 'numeric', month: 'numeric', year: 'numeric' })

			expect(shown.replace(/\D/g, '')).toBe(expected.replace(/\D/g, ''))
			expect(shown).toContain('11')
		})

		it('does not shift the day at month and year boundaries', () => {
			expect(formatDate('2026-01-01', { day: 'numeric', month: 'long', year: 'numeric' })).toContain('1')
			expect(formatDate('2026-01-01', { year: 'numeric' })).toBe('2026')
			expect(formatDate('2025-12-31', { year: 'numeric' })).toBe('2025')
		})

		it('returns an empty string for an unparseable date instead of "Invalid Date"', () => {
			expect(formatDate('not a date')).toBe('')
		})

		it('todayLocal uses the local calendar date, not the UTC date', () => {
			// 00:30 local on the 12th: the UTC date can still be the 11th east of UTC.
			expect(todayLocal(new Date(2026, 9, 12, 0, 30))).toBe('2026-10-12')
			expect(todayLocal(new Date(2026, 9, 11, 23, 59))).toBe('2026-10-11')
			expect(todayLocal(new Date(2026, 0, 5, 12))).toBe('2026-01-05')
		})

		it('todayLocal defaults to now and is a valid date string', () => {
			expect(todayLocal()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		})
	})

	describe('formatRuntime', () => {
		it('should format minutes to hours and minutes', () => {
			expect(formatRuntime(120)).toBe('2h 0m')
			expect(formatRuntime(90)).toBe('1h 30m')
			expect(formatRuntime(150)).toBe('2h 30m')
		})

		it('should handle minutes less than 60', () => {
			expect(formatRuntime(45)).toBe('45m')
		})

		it('should return empty string for null', () => {
			expect(formatRuntime(null)).toBe('')
		})

		it('should return empty string for 0', () => {
			expect(formatRuntime(0)).toBe('')
		})
	})
})

describe('episodeCode', () => {
	it('pads season and episode to two digits', () => {
		expect(episodeCode({ seasonNumber: 2, episodeNumber: 5 })).toBe('S02E05')
		expect(episodeCode({ seasonNumber: 12, episodeNumber: 104 })).toBe('S12E104')
	})
})

describe('relativeAirDate', () => {
	it('says today, tomorrow and in N days, capitalised', () => {
		expect(relativeAirDate('2026-10-20', '2026-10-20')).toBe('Today')
		expect(relativeAirDate('2026-10-21', '2026-10-20')).toBe('Tomorrow')
		expect(relativeAirDate('2026-10-23', '2026-10-20')).toBe('In 3 days')
		expect(relativeAirDate('2026-10-26', '2026-10-20')).toBe('In 6 days')
	})

	it('leaves anything later than six days to the plain date', () => {
		expect(relativeAirDate('2026-10-27', '2026-10-20')).toBeNull()
		expect(relativeAirDate('2027-03-01', '2026-10-20')).toBeNull()
	})

	it('has nothing to say about dates in the past or invalid dates', () => {
		expect(relativeAirDate('2026-10-19', '2026-10-20')).toBeNull()
		expect(relativeAirDate('', '2026-10-20')).toBeNull()
		expect(relativeAirDate(null, '2026-10-20')).toBeNull()
		expect(relativeAirDate('not a date', '2026-10-20')).toBeNull()
	})

	it('counts calendar days across month and year ends', () => {
		expect(relativeAirDate('2026-11-02', '2026-10-31')).toBe('In 2 days')
		expect(relativeAirDate('2027-01-01', '2026-12-31')).toBe('Tomorrow')
	})

	it('counts whole days over a daylight saving change', () => {
		// Europe switches clocks on 2026-10-25; a 23 or 25 hour day must still be one day.
		expect(relativeAirDate('2026-10-26', '2026-10-24')).toBe('In 2 days')
	})
})
