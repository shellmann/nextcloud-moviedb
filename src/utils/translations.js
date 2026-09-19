import { translate, translatePlural } from '@nextcloud/l10n'

/**
 *
 * @param appName
 * @param text
 * @param vars
 * @param count
 * @param options
 */
export function translateText(appName, text, vars, count, options) {
	return translate(appName, text, vars, count, options)
}

/**
 *
 * @param appName
 * @param singular
 * @param plural
 * @param count
 * @param vars
 * @param options
 */
export function translateTextPlural(appName, singular, plural, count, vars, options) {
	return translatePlural(appName, singular, plural, count, vars, options)
}
