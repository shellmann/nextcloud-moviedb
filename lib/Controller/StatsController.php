<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\StatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class StatsController extends Controller {
    private StatsService $service;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        StatsService $service,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userSession->getUser()?->getUID();
    }

    #[NoAdminRequired]
    public function overview(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $stats = $this->service->getOverview($this->userId);
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function byYear(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $stats = $this->service->getStatsByYear($this->userId);

        return new JSONResponse(['years' => $stats]);
    }

    #[NoAdminRequired]
    public function byPlatform(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $stats = $this->service->getStatsByPlatform($this->userId);

        return new JSONResponse(['platforms' => $stats]);
    }

    #[NoAdminRequired]
    public function byGenre(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $stats = $this->service->getStatsByGenre($this->userId);

        return new JSONResponse(['genres' => $stats]);
    }

    #[NoAdminRequired]
    public function recent(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $limit = min((int)$this->request->getParam('limit', 5), 20);
        $movies = $this->service->getRecentMovies($this->userId, $limit);

        return new JSONResponse(['movies' => $movies]);
    }

    #[NoAdminRequired]
    public function topRated(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $limit = min((int)$this->request->getParam('limit', 5), 20);
        $movies = $this->service->getTopRated($this->userId, $limit);

        return new JSONResponse(['movies' => $movies]);
    }
}
