<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IGroupManager;

/**
 * The settings a user sees: their preferences and which TMDB key is in use.
 *
 * Used by GET /api/settings and for the initial state of the app page, so
 * both always carry the same fields.
 */
class SettingsService {
    public function __construct(
        private IUserConfig $userConfig,
        private TmdbService $tmdbService,
        private IGroupManager $groupManager,
    ) {
    }

    public function getUserSettings(string $userId): array {
        $hasUserApiKey = $this->userConfig->getValueString($userId, Application::APP_ID, 'tmdb_api_key', '') !== '';
        $hasInstanceApiKey = $this->tmdbService->hasInstanceApiKey();

        return [
            'tmdbApiKey' => $hasUserApiKey ? '••••••••' : '', // Don't expose the actual key
            // A personal key overrides the instance-wide one (see TmdbService::getApiKey)
            'hasApiKey' => $hasUserApiKey || $hasInstanceApiKey,
            'hasUserApiKey' => $hasUserApiKey,
            'hasInstanceApiKey' => $hasInstanceApiKey,
            'isAdmin' => $this->groupManager->isAdmin($userId),
            'defaultLanguage' => $this->userConfig->getValueString($userId, Application::APP_ID, 'default_language', 'de-DE'),
            'appLanguage' => $this->userConfig->getValueString($userId, Application::APP_ID, 'app_language', 'auto'),
        ];
    }
}
