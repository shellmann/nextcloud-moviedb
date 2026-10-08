<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Util\TmdbApiKey;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Instance-wide settings, set in Administration settings → MovieDB.
 *
 * Admin only: the methods deliberately have no #[NoAdminRequired], so
 * Nextcloud rejects requests from everyone else.
 */
class AdminSettingsController extends Controller {
    use VerifiesTmdbApiKey;

    public function __construct(
        IRequest $request,
        private TmdbService $tmdbService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Set the instance-wide TMDB API key (an empty string removes it).
     * Users without a personal key use it; the key itself is never returned.
     */
    public function update(): JSONResponse {
        $data = $this->request->getParams();
        if (!isset($data['tmdbApiKey'])) {
            return new JSONResponse(['error' => 'Missing tmdbApiKey'], Http::STATUS_BAD_REQUEST);
        }

        $apiKey = (string)$data['tmdbApiKey'];
        if ($apiKey === '') {
            $this->tmdbService->deleteInstanceApiKey();
        } else {
            if (!TmdbApiKey::isValid($apiKey)) {
                return new JSONResponse(['error' => 'Invalid API key format'], Http::STATUS_BAD_REQUEST);
            }
            if ($error = $this->verifyTmdbApiKey($this->tmdbService, $apiKey)) {
                return $error;
            }
            $this->tmdbService->setInstanceApiKey($apiKey);
        }

        return new JSONResponse(['hasInstanceApiKey' => $this->tmdbService->hasInstanceApiKey()]);
    }
}
