<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\SeriesService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for TV series CRUD, episode listing, and mark-watched fan-out.
 */
class SeriesController extends AuthenticatedController {
    private SeriesService $service;
    private LibraryService $libraryService;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        SeriesService $service,
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
    public function index(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        $page = (int)$this->request->getParam('page', 1);
        $limit = min((int)$this->request->getParam('limit', 24), 100);
        $offset = ($page - 1) * $limit;

        $filters = [
            'genre' => $this->request->getParam('genre'),
            'year' => $this->request->getParam('year'),
            'search' => $this->request->getParam('search'),
            'sort' => $this->request->getParam('sort', 'date_watched'),
            'dir' => $this->request->getParam('dir', 'DESC'),
            'favorite' => $this->request->getParam('favorite'),
        ];

        $series = $this->service->findAll($libraryId, $filters, $limit, $offset);
        $total = $this->service->count($libraryId, $filters);

        return new JSONResponse([
            'series' => $series,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit),
        ]);
    }

    #[NoAdminRequired]
    public function show(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        try {
            $series = $this->service->findWithProgress($id, $libraryId);
            return new JSONResponse(['series' => $series]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->libraryService->canEdit($libraryId, $this->userId)) {
            return new JSONResponse(['error' => 'You do not have edit permission for this library.'], Http::STATUS_FORBIDDEN);
        }

        $data = $this->request->getParams();

        if (empty($data['title'])) {
            return new JSONResponse(['error' => 'Title is required'], Http::STATUS_BAD_REQUEST);
        }

        // Check if series is already tracked.
        if (!empty($data['tmdbId'])) {
            $existing = $this->service->findByTmdbId($libraryId, (int)$data['tmdbId']);
            if ($existing !== null) {
                return new JSONResponse([
                    'error' => 'Series already in your list',
                    'existingId' => $existing->getId(),
                ], Http::STATUS_CONFLICT);
            }
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $series = $this->service->createFromTmdb($this->userId, $libraryId, $data, $language);
            return new JSONResponse(['series' => $series], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create series', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to create series. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->libraryService->canEdit($libraryId, $this->userId)) {
            return new JSONResponse(['error' => 'You do not have edit permission for this library.'], Http::STATUS_FORBIDDEN);
        }

        $data = $this->request->getParams();

        try {
            $series = $this->service->update($id, $libraryId, $data);
            return new JSONResponse(['series' => $series]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update series', [
                'exception' => $e,
                'userId' => $this->userId,
                'seriesId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update series. Please try again.'],
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
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->libraryService->canEdit($libraryId, $this->userId)) {
            return new JSONResponse(['error' => 'You do not have edit permission for this library.'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->service->delete($id, $libraryId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function episodes(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        try {
            $episodes = $this->service->getEpisodes($id, $libraryId);
            return new JSONResponse(['episodes' => $episodes]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function markWatched(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->libraryService->canEdit($libraryId, $this->userId)) {
            return new JSONResponse(['error' => 'You do not have edit permission for this library.'], Http::STATUS_FORBIDDEN);
        }

        // Episodes are a plain watched/unwatched toggle; default true so an
        // omitted flag still marks watched (season/series "mark all" buttons).
        $watched = $this->parseWatchedFlag($this->request->getParam('watched'));

        try {
            $episodeId = $this->request->getParam('episodeId');
            if ($episodeId !== null && $episodeId !== '') {
                // Single episode.
                $this->service->markEpisodeWatched($id, (int)$episodeId, $libraryId, $watched);
            } else {
                // Whole series.
                $this->service->markSeriesWatched($id, $libraryId, $watched);
            }
            $series = $this->service->findWithProgress($id, $libraryId);
            return new JSONResponse(['series' => $series]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series or episode not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark watched', [
                'exception' => $e,
                'userId' => $this->userId,
                'seriesId' => $id,
            ]);
            return new JSONResponse(['error' => 'Failed to mark watched. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function markSeasonWatched(int $id, int $seasonNumber): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->libraryService->canEdit($libraryId, $this->userId)) {
            return new JSONResponse(['error' => 'You do not have edit permission for this library.'], Http::STATUS_FORBIDDEN);
        }

        $watched = $this->parseWatchedFlag($this->request->getParam('watched'));

        try {
            $this->service->markSeasonWatched($id, $seasonNumber, $libraryId, $watched);
            $series = $this->service->findWithProgress($id, $libraryId);
            return new JSONResponse(['series' => $series]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Series not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark season watched', [
                'exception' => $e,
                'userId' => $this->userId,
                'seriesId' => $id,
                'seasonNumber' => $seasonNumber,
            ]);
            return new JSONResponse(['error' => 'Failed to mark season watched. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Interpret the optional `watched` request param. Absent → true (mark
     * watched); accepts booleans and the strings "false"/"0" for un-marking.
     */
    private function parseWatchedFlag(mixed $raw): bool {
        if ($raw === null) {
            return true;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        return !in_array((string)$raw, ['false', '0', ''], true);
    }
}
