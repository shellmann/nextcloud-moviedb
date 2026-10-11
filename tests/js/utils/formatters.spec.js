import { describe, expect, it } from 'vitest'
import { formatDate, formatRuntime, todayLocal } from '@/utils/formatters.js'

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
