import { describe, it, expect } from 'vitest'
import { formatDate, formatRuntime } from '@/utils/formatters.js'

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
