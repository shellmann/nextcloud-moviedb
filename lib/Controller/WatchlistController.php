<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\WatchlistService;
use OCA\MovieDB\Service\MovieService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class WatchlistController extends AuthenticatedController {
    private WatchlistService $service;
    private MovieService $movieService;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        WatchlistService $service,
        MovieService $movieService,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->movieService = $movieService;
        $this->db = $db;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $filters = [
            'search' => $this->request->getParam('search'),
            'sort' => $this->request->getParam('sort', 'priority'),
            'dir' => $this->request->getParam('dir', 'DESC'),
        ];

        $items = $this->service->findAll($this->userId, $filters);
        $total = $this->service->count($this->userId);

        return new JSONResponse([
            'items' => $items,
            'total' => $total,
        ]);
    }

    #[NoAdminRequired]
    public function show(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $item = $this->service->find($id, $this->userId);
            return new JSONResponse(['item' => $item]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        if (empty($data['title'])) {
            return new JSONResponse(['error' => 'Title is required'], Http::STATUS_BAD_REQUEST);
        }

        // Check if already in watchlist
        if (!empty($data['tmdbId']) && $this->service->existsByTmdbId($this->userId, (int)$data['tmdbId'])) {
            return new JSONResponse(['error' => 'Movie already in watchlist'], Http::STATUS_CONFLICT);
        }

        try {
            $item = $this->service->create($this->userId, $data);
            return new JSONResponse(['item' => $item], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create watchlist item', [
                'exception' => $e,
                'userId' => $this->userId,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to add to watchlist. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        try {
            $item = $this->service->update($id, $this->userId, $data);
            return new JSONResponse(['item' => $item]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update watchlist item', [
                'exception' => $e,
                'userId' => $this->userId,
                'itemId' => $id,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update watchlist item. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->service->delete($id, $this->userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Move a watchlist item to watched movies
     */
    #[NoAdminRequired]
    public function moveToWatched(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            // Get the watchlist item
            $item = $this->service->find($id, $this->userId);

            // Get additional watch data from request
            $watchData = $this->request->getParams();

            // Create movie from watchlist item
            $movieData = [
                'tmdbId' => $item->getTmdbId(),
                'title' => $item->getTitle(),
                'posterPath' => $item->getPosterPath(),
                'overview' => $item->getOverview(),
                'genreIds' => $item->getGenreIds(),
                'releaseDate' => $item->getReleaseDate(),
                'platformId' => $watchData['platformId'] ?? null,
                'languageWatched' => $watchData['languageWatched'] ?? null,
                'dateWatched' => $watchData['dateWatched'] ?? date('Y-m-d'),
                'rating' => $watchData['rating'] ?? null,
                'review' => $watchData['review'] ?? null,
            ];

            // Use a transaction to ensure atomicity
            $this->db->beginTransaction();
            try {
                $movie = $this->movieService->create($this->userId, $movieData);
                $this->service->delete($id, $this->userId);
                $this->db->commit();
            } catch (\Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

            return new JSONResponse(['movie' => $movie]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to move watchlist item to watched', [
                'exception' => $e,
                'userId' => $this->userId,
                'itemId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to move to watched. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }
}
