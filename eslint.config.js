import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		rules: {
			'no-console': ['error', { allow: ['error'] }],
			'@stylistic/max-statements-per-line': 'off',
		},
	},
	{
		files: ['src/views/**/*.vue'],
		rules: {
			'vue/multi-word-component-names': 'off',
		},
	},
]
