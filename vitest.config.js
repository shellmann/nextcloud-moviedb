import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		globals: true,
		include: ['tests/js/**/*.{test,spec}.js'],
		coverage: {
			provider: 'v8',
			reporter: ['text', 'html'],
			include: ['src/**/*.{js,vue}'],
			exclude: ['src/main.js'],
		},
		setupFiles: ['tests/js/setup.js'],
	},
	resolve: {
		alias: {
			'@': resolve(__dirname, 'src'),
		},
	},
})
