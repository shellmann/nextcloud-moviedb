import { translate as t } from '@nextcloud/l10n'

/**
 * Message for a TMDB key that could not be saved because the server's check
 * with TMDB failed (see VerifiesTmdbApiKey.php), or null for any other error.
 *
 * @param {Error} error - Error thrown by axios
 * @return {string|null} Translated message, or null if the key check was not the cause
 */
export function tmdbApiKeyErrorMessage(error) {
	switch (error?.response?.status) {
		case 422:
			return t('moviedb', 'TMDB did not accept this API key. Make sure you use the API Read Access Token.')
		case 502:
			return t('moviedb', 'Could not reach TMDB to check the API key. Please try again.')
		default:
			return null
	}
}
