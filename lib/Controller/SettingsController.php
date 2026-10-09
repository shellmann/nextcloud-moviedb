<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\SettingsService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Util\TmdbApiKey;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends AuthenticatedController {
    use VerifiesTmdbApiKey;

    private IUserConfig $userConfig;
    private SettingsService $settingsService;
    private TmdbService $tmdbService;

    public function __construct(
        IRequest $request,
        IUserConfig $userConfig,
        IUserSession $userSession,
        SettingsService $settingsService,
        TmdbService $tmdbService
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->userConfig = $userConfig;
        $this->settingsService = $settingsService;
        $this->tmdbService = $tmdbService;
    }

    #[NoAdminRequired]
    public function get(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        return new JSONResponse($this->settingsService->getUserSettings($this->userId));
    }

    #[NoAdminRequired]
    public function update(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        // Validate everything before writing anything, so a rejected request
        // changes nothing.

        // TMDB API key: null leaves it unchanged, an empty string removes it
        $apiKey = null;
        if (isset($data['tmdbApiKey']) && $data['tmdbApiKey'] !== '••••••••') {
            $apiKey = (string)$data['tmdbApiKey'];
            if ($apiKey !== '' && !TmdbApiKey::isValid($apiKey)) {
                return new JSONResponse(['error' => 'Invalid API key format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $language = null;
        if (isset($data['defaultLanguage'])) {
            $language = (string)$data['defaultLanguage'];
            // Validate BCP-47 language format (e.g., en-US, de-DE)
            if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
                return new JSONResponse(['error' => 'Invalid language format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $appLanguage = null;
        if (isset($data['appLanguage'])) {
            $appLanguage = (string)$data['appLanguage'];
            if (strlen($appLanguage) > 10 || !preg_match('/^[a-z]{2,5}(-[A-Z]{2})?$|^auto$/', $appLanguage)) {
                return new JSONResponse(['error' => 'Invalid app language format'], Http::STATUS_BAD_REQUEST);
            }
        }

        // Checked last because it is the only check that calls TMDB
        if ($apiKey !== null && $apiKey !== '') {
            if ($error = $this->verifyTmdbApiKey($this->tmdbService, $apiKey)) {
                return $error;
            }
        }

        if ($apiKey === '') {
            $this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'tmdb_api_key');
        } elseif ($apiKey !== null) {
            // Stored as sensitive
            $this->userConfig->setValueString(
                $this->userId,
                Application::APP_ID,
                'tmdb_api_key',
                $apiKey,
                false,
                IUserConfig::FLAG_SENSITIVE
            );
        }

        if ($language !== null) {
            $this->userConfig->setValueString($this->userId, Application::APP_ID, 'default_language', $language);
        }

        if ($appLanguage !== null) {
            $this->userConfig->setValueString($this->userId, Application::APP_ID, 'app_language', $appLanguage);
        }

        return new JSONResponse(['success' => true]);
    }
}
