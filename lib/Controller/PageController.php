<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'nextcloud-moviedb-main');
        Util::addStyle(Application::APP_ID, 'style');

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
