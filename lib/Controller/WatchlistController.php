<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\WatchlistService;
use OCA\MovieDB\Service\MovieService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WatchlistController extends Controller {
    private WatchlistService $service;
    private MovieService $movieService;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        WatchlistService $service,
        MovieService $movieService,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->movieService = $movieService;
        $this->userId = $userSession->getUser()?->getUID();
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = $this->request->getParams();

        try {
            $item = $this->service->update($id, $this->userId, $data);
            return new JSONResponse(['item' => $item]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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

            $movie = $this->movieService->create($this->userId, $movieData);

            // Delete from watchlist
            $this->service->delete($id, $this->userId);

            return new JSONResponse(['movie' => $movie]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Item not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
