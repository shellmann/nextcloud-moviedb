<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\StatsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class StatsController extends AuthenticatedController {
    private StatsService $service;
    private LibraryService $libraryService;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        StatsService $service,
        LibraryService $libraryService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->libraryService = $libraryService;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    public function overview(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        try {
            $stats = $this->service->getOverview($this->userId, $libraryId);
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            // Log the full error with stack trace for debugging
            $this->logger->error('Failed to get stats overview', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);

            // Return generic error message to client (never expose stack traces)
            return new JSONResponse(
                ['error' => 'Failed to load statistics. Please try again.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    public function byYear(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $stats = $this->service->getStatsByYear($this->userId, $libraryId);

        return new JSONResponse(['years' => $stats]);
    }

    #[NoAdminRequired]
    public function byPlatform(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $stats = $this->service->getStatsByPlatform($this->userId, $libraryId);

        return new JSONResponse(['platforms' => $stats]);
    }

    #[NoAdminRequired]
    public function byGenre(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $stats = $this->service->getStatsByGenre($this->userId, $libraryId);

        return new JSONResponse(['genres' => $stats]);
    }

    #[NoAdminRequired]
    public function recent(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $limit = min((int)$this->request->getParam('limit', 5), 20);
        $movies = $this->service->getRecentMovies($this->userId, $libraryId, $limit);
        $series = $this->service->getRecentSeries($this->userId, $libraryId, $limit);

        return new JSONResponse([
            'movies' => $movies,
            'series' => $series,
        ]);
    }

    #[NoAdminRequired]
    public function topRated(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $limit = min((int)$this->request->getParam('limit', 5), 20);
        $movies = $this->service->getTopRated($this->userId, $libraryId, $limit);
        $series = $this->service->getTopRatedSeries($this->userId, $libraryId, $limit);

        return new JSONResponse([
            'movies' => $movies,
            'series' => $series,
        ]);
    }
}
