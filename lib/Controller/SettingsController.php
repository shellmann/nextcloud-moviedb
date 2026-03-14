<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends AuthenticatedController {
    private IConfig $config;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->config = $config;
    }

    #[NoAdminRequired]
    public function get(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $apiKey = $this->config->getUserValue($this->userId, Application::APP_ID, 'tmdb_api_key', '');
        $defaultLanguage = $this->config->getUserValue($this->userId, Application::APP_ID, 'default_language', 'de-DE');
        $appLanguage = $this->config->getUserValue($this->userId, Application::APP_ID, 'app_language', 'auto');

        return new JSONResponse([
            'tmdbApiKey' => !empty($apiKey) ? '••••••••' : '', // Don't expose the actual key
            'hasApiKey' => !empty($apiKey),
            'defaultLanguage' => $defaultLanguage,
            'appLanguage' => $appLanguage,
        ]);
    }

    #[NoAdminRequired]
    public function update(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        // Update TMDB API key if provided (empty string removes the key)
        if (isset($data['tmdbApiKey']) && $data['tmdbApiKey'] !== '••••••••') {
            if ($data['tmdbApiKey'] === '') {
                // Remove the API key
                $this->config->deleteUserValue(
                    $this->userId,
                    Application::APP_ID,
                    'tmdb_api_key'
                );
            } else {
                // Set new API key
                $this->config->setUserValue(
                    $this->userId,
                    Application::APP_ID,
                    'tmdb_api_key',
                    $data['tmdbApiKey']
                );
            }
        }

        // Update default language
        if (isset($data['defaultLanguage'])) {
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'default_language',
                $data['defaultLanguage']
            );
        }

        // Update app language
        if (isset($data['appLanguage'])) {
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'app_language',
                $data['appLanguage']
            );
        }

        return new JSONResponse(['success' => true]);
    }
}
