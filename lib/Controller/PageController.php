<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private SettingsService $settingsService,
        private IInitialState $initialState,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'nextcloud-moviedb-main');
        Util::addStyle(Application::APP_ID, 'style');

        // Send the settings with the page, so the app knows right away whether
        // a TMDB key is set instead of briefly showing "key required"
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId !== null) {
            $this->initialState->provideInitialState('settings', $this->settingsService->getUserSettings($userId));
        }

        $response = new TemplateResponse(Application::APP_ID, 'index', [
            'faviconPath' => $this->urlGenerator->imagePath(Application::APP_ID, 'favicon.svg'),
        ]);

        // Add CSP policy to allow TMDB images
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedImageDomain('https://image.tmdb.org');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }
}
