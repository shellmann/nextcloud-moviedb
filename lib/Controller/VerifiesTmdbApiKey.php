<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\Service\TmdbService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Asks TMDB whether it accepts a new key before it is saved.
 *
 * Shared by the personal (SettingsController) and the instance-wide
 * (AdminSettingsController) key settings, so both answer with the same
 * status codes: 422 if TMDB rejects the key, 502 if TMDB can't be reached.
 */
trait VerifiesTmdbApiKey {
    /**
     * @return JSONResponse|null error response, or null if the key can be saved
     */
    private function verifyTmdbApiKey(TmdbService $tmdbService, string $apiKey): ?JSONResponse {
        try {
            if (!$tmdbService->verifyApiKey($apiKey)) {
                return new JSONResponse(['error' => 'TMDB rejected the API key'], Http::STATUS_UNPROCESSABLE_ENTITY);
            }
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => 'Could not reach TMDB to check the API key'], Http::STATUS_BAD_GATEWAY);
        }
        return null;
    }
}
