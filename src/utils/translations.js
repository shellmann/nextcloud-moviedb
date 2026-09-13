import { translate, translatePlural } from '@nextcloud/l10n'

export const translateText = (appName, text, vars, count, options) =>
	translate(appName, text, vars, count, options)

export const translateTextPlural = (appName, singular, plural, count, vars, options) =>
	translatePlural(appName, singular, plural, count, vars, options)
