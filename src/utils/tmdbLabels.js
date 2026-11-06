import { translate as t } from '@nextcloud/l10n'

/**
 * TMDB returns status and type as English words whatever the request language.
 * Known values are translated; unknown ones are shown as they are.
 *
 * @param status
 */
export function statusLabel(status) {
	switch (status) {
		case 'Returning Series': return t('moviedb', 'Returning Series')
		case 'Planned': return t('moviedb', 'Planned')
		case 'In Production': return t('moviedb', 'In Production')
		case 'Ended': return t('moviedb', 'Ended')
		case 'Canceled': return t('moviedb', 'Canceled')
		case 'Pilot': return t('moviedb', 'Pilot')
		default: return status
	}
}

/**
 *
 * @param type
 */
export function typeLabel(type) {
	switch (type) {
		case 'Scripted': return t('moviedb', 'Scripted')
		case 'Miniseries': return t('moviedb', 'Miniseries')
		case 'Documentary': return t('moviedb', 'Documentary')
		case 'Reality': return t('moviedb', 'Reality')
		case 'News': return t('moviedb', 'News')
		case 'Talk Show': return t('moviedb', 'Talk Show')
		case 'Video': return t('moviedb', 'Video')
		default: return type
	}
}
