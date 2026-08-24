<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\MovieService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for movie CRUD operations.
 *
 * Handles HTTP requests for managing the user's watched movies collection.
 * Supports listing with pagination/filters, single movie retrieval, and CRUD.
 */
class MovieController extends AuthenticatedController {
    private MovieService $service;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        MovieService $service,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->logger = $logger;
    }

    /**
     * List movies with pagination and filtering.
     *
     * Query params:
     * - page (int): Page number, default 1
     * - limit (int): Items per page, max 100, default 24
     * - genre (int): Filter by genre ID
     * - year (int): Filter by release year
     * - platform (int): Filter by platform ID
     * - search (string): Search in title
     * - sort (string): Sort field (date_watched, title, rating, release_year)
     * - dir (string): Sort direction (ASC, DESC)
     * - favorite (bool): Filter favorites only
     *
     * @return JSONResponse Movies array with pagination metadata
     */
    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $page = (int)$this->request->getParam('page', 1);
        $limit = min((int)$this->request->getParam('limit', 24), 100);
        $offset = ($page - 1) * $limit;

        $filters = [
            'genre' => $this->request->getParam('genre'),
            'year' => $this->request->getParam('year'),
            'platform' => $this->request->getParam('platform'),
            'search' => $this->request->getParam('search'),
            'sort' => $this->request->getParam('sort', 'date_watched'),
            'dir' => $this->request->getParam('dir', 'DESC'),
            'favorite' => $this->request->getParam('favorite'),
        ];

        $movies = $this->service->findAll($this->userId, $filters, $limit, $offset);
        $total = $this->service->count($this->userId, $filters);

        return new JSONResponse([
            'movies' => $movies,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit),
        ]);
    }

    /**
     * Get a single movie by ID.
     *
     * @param int $id Movie ID
     * @return JSONResponse Movie object or error
     */
    #[NoAdminRequired]
    public function show(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $movie = $this->service->find($id, $this->userId);
            return new JSONResponse(['movie' => $movie]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Movie not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Create a new movie entry.
     *
     * Required body params:
     * - title (string): Movie title
     *
     * Optional body params:
     * - tmdbId, originalTitle, posterPath, backdropPath, overview,
     *   releaseYear, runtime, genreIds, platformId, languageWatched,
     *   dateWatched, rating, review, isFavorite
     *
     * @return JSONResponse Created movie or validation error
     */
    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        if (empty($data['title'])) {
            return new JSONResponse(['error' => 'Title is required'], Http::STATUS_BAD_REQUEST);
        }

        // Check if movie is already tracked
        if (!empty($data['tmdbId'])) {
            $existing = $this->service->findByTmdbId($this->userId, (int)$data['tmdbId']);
            if ($existing !== null) {
                return new JSONResponse([
                    'error' => 'Movie already in your list',
                    'existingId' => $existing->getId(),
                ], Http::STATUS_CONFLICT);
            }
        }

        try {
            $movie = $this->service->create($this->userId, $data);
            return new JSONResponse(['movie' => $movie], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create movie', [
                'exception' => $e,
                'userId' => $this->userId,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to create movie. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * Update an existing movie.
     *
     * @param int $id Movie ID
     * @return JSONResponse Updated movie or error
     */
    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        try {
            $movie = $this->service->update($id, $this->userId, $data);
            return new JSONResponse(['movie' => $movie]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Movie not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update movie', [
                'exception' => $e,
                'userId' => $this->userId,
                'movieId' => $id,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update movie. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * Delete a movie.
     *
     * @param int $id Movie ID
     * @return JSONResponse Success status or error
     */
    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->service->delete($id, $this->userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Movie not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
