<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\WatchlistService;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Service\MovieWatchService;
use OCA\MovieDB\Service\SeriesService;
use OCA\MovieDB\Service\TmdbService;
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
    private MovieWatchService $watchService;
    private SeriesService $seriesService;
    private TmdbService $tmdbService;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        WatchlistService $service,
        MovieService $movieService,
        MovieWatchService $watchService,
        SeriesService $seriesService,
        TmdbService $tmdbService,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->movieService = $movieService;
        $this->watchService = $watchService;
        $this->seriesService = $seriesService;
        $this->tmdbService = $tmdbService;
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

        $mediaType = $data['mediaType'] ?? 'movie';

        // Check if already in watchlist (a movie and show may share a TMDB id → key on type too)
        if (!empty($data['tmdbId']) && $this->service->existsByTmdbId($this->userId, (int)$data['tmdbId'], $mediaType)) {
            return new JSONResponse(['error' => 'Already in watchlist'], Http::STATUS_CONFLICT);
        }

        // Check if already watched — allow adding but flag it so the UI can warn the user
        // (movies only; series have no equivalent single "watched" flag)
        $alreadyWatched = $mediaType === 'movie'
            && !empty($data['tmdbId'])
            && $this->movieService->findByTmdbId($this->userId, (int)$data['tmdbId']) !== null;

        try {
            $item = $this->service->create($this->userId, $data);
            return new JSONResponse(['item' => $item, 'alreadyWatched' => $alreadyWatched], Http::STATUS_CREATED);
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

            // Series items import the whole show (all seasons/episodes) rather than
            // logging a single watch. No episodes are auto-marked watched — the user
            // tracks progress per-episode/season afterward on the /tv detail page.
            if ($item->getMediaType() === 'series') {
                return $this->moveSeriesToWatched($id, $item);
            }

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

            // Fetch full TMDB details if tmdbId is available
            if ($item->getTmdbId()) {
                try {
                    $tmdbDetails = $this->tmdbService->getMovieDetails($item->getTmdbId(), $this->userId);
                    $movieData['runtime'] = $tmdbDetails['runtime'] ?? null;
                    $movieData['director'] = $tmdbDetails['director'] ?? null;
                    $movieData['castData'] = !empty($tmdbDetails['cast']) ? json_encode($tmdbDetails['cast']) : null;
                    $movieData['backdropPath'] = $tmdbDetails['backdrop_path'] ?? null;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to fetch TMDB details for movie ' . $item->getTmdbId(), [
                        'exception' => $e,
                    ]);
                    // Continue without TMDB details - graceful fallback
                }
            }

            // Use a transaction to ensure atomicity
            $this->db->beginTransaction();
            try {
                $existingMovie = $item->getTmdbId()
                    ? $this->movieService->findByTmdbId($this->userId, $item->getTmdbId())
                    : null;

                if ($existingMovie !== null) {
                    // Movie already tracked — add a new watch entry (rewatch)
                    $newWatch = [
                        'watchedAt' => $watchData['dateWatched'] ?? date('Y-m-d'),
                        'rating' => $watchData['rating'] ?? null,
                        'review' => $watchData['review'] ?? null,
                        'platformId' => $watchData['platformId'] ?? null,
                        'languageWatched' => $watchData['languageWatched'] ?? null,
                    ];
                    $this->watchService->create($existingMovie->getId(), $this->userId, $newWatch);
                    $movie = $existingMovie;
                } else {
                    $movie = $this->movieService->create($this->userId, $movieData);
                }
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

    /**
     * Import a series watchlist item as a tracked show and drop the watchlist row.
     *
     * Fetches full TMDB details (seasons drive per-season episode import) and calls
     * SeriesService::createFromTmdb. Deletes the watchlist row in the same transaction.
     * Does NOT mark any episodes watched — the show starts at 0% progress.
     */
    private function moveSeriesToWatched(int $id, \OCA\MovieDB\Db\WatchlistItem $item): JSONResponse {
        $seriesData = [
            'tmdbId' => $item->getTmdbId(),
            'title' => $item->getTitle(),
            'posterPath' => $item->getPosterPath(),
            'overview' => $item->getOverview(),
            'genreIds' => $item->getGenreIds(),
            'firstAirDate' => $item->getReleaseDate(),
        ];

        $language = $this->request->getParam('language', 'en-US');

        // Enrich from TMDB so createFromTmdb can fan out episodes across seasons.
        if ($item->getTmdbId()) {
            try {
                $details = $this->tmdbService->getSeriesDetails($item->getTmdbId(), $this->userId, $language);
                $seriesData['title'] = $details['name'] ?? $seriesData['title'];
                $seriesData['originalTitle'] = $details['original_name'] ?? null;
                $seriesData['posterPath'] = $details['poster_path'] ?? $seriesData['posterPath'];
                $seriesData['backdropPath'] = $details['backdrop_path'] ?? null;
                $seriesData['overview'] = $details['overview'] ?? $seriesData['overview'];
                $seriesData['genreIds'] = !empty($details['genres'])
                    ? array_map(static fn ($g) => $g['id'], $details['genres'])
                    : $seriesData['genreIds'];
                $seriesData['firstAirDate'] = $details['first_air_date'] ?? $seriesData['firstAirDate'];
                $seriesData['numberOfSeasons'] = $details['number_of_seasons'] ?? null;
                $seriesData['numberOfEpisodes'] = $details['number_of_episodes'] ?? null;
                $seriesData['status'] = $details['status'] ?? null;
                $seriesData['castData'] = $details['cast'] ?? null;
                $seriesData['director'] = $details['director'] ?? null;
                $seriesData['seasons'] = $details['seasons'] ?? [];
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch TMDB details for series ' . $item->getTmdbId(), [
                    'exception' => $e,
                ]);
                // Continue without full details — series is still created (no episodes).
            }
        }

        $this->db->beginTransaction();
        try {
            $series = $this->seriesService->createFromTmdb($this->userId, $seriesData, $language);
            $this->service->delete($id, $this->userId);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return new JSONResponse(['series' => $series]);
    }
}
