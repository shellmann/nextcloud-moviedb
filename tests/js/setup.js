/**
 * Vitest setup file - Mocks for Nextcloud globals
 */
import { vi } from 'vitest'

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	t: (app, text) => text,
	n: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLocale: () => 'en-US',
}))

// Mock @nextcloud/dialogs
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
}))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

// Mock @nextcloud/initial-state
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(() => ({})),
}))

// Global t function (used in templates)
globalThis.t = (app, text) => text
globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
