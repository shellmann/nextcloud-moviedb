<?php

declare(strict_types=1);

namespace OCA\MovieDB\Settings;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\TmdbService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin form for the instance-wide TMDB API key (rendered by src/admin.js).
 */
class Admin implements ISettings {
    public function __construct(
        private TmdbService $tmdbService,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        // Only whether a key is set; the key itself never reaches the browser
        $this->initialState->provideInitialState('admin-settings', [
            'hasInstanceApiKey' => $this->tmdbService->hasInstanceApiKey(),
        ]);
        Util::addScript(Application::APP_ID, 'nextcloud-moviedb-admin');

        return new TemplateResponse(Application::APP_ID, 'admin', [], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
