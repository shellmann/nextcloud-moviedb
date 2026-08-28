<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Service\MovieWatchService;
use OCA\MovieDB\Service\SeriesService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for episode watch history (rewatch log). Reuses MovieWatchService;
 * the series that owns an episode is resolved server-side and its ownership is
 * verified before any write — the client never supplies seriesId.
 */
class EpisodeWatchController extends AuthenticatedController {
    private MovieWatchService $watchService;
    private SeriesService $seriesService;
    private EpisodeMapper $episodeMapper;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        MovieWatchService $watchService,
        SeriesService $seriesService,
        EpisodeMapper $episodeMapper,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->watchService = $watchService;
        $this->seriesService = $seriesService;
        $this->episodeMapper = $episodeMapper;
        $this->logger = $logger;
    }

    /**
     * Resolve the episode and verify the caller owns its series.
     * Returns the resolved series id, or null if not found / not owned.
     */
    private function resolveOwnedSeriesId(int $episodeId): ?int {
        try {
            $episode = $this->episodeMapper->find($episodeId);
        } catch (DoesNotExistException $e) {
            return null;
        }
        try {
            $this->seriesService->find($episode->getSeriesId(), $this->userId);
        } catch (DoesNotExistException $e) {
            return null;
        }
        return $episode->getSeriesId();
    }

    #[NoAdminRequired]
    public function index(int $episodeId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        if ($this->resolveOwnedSeriesId($episodeId) === null) {
            return new JSONResponse(['error' => 'Episode not found'], Http::STATUS_NOT_FOUND);
        }

        $watches = $this->watchService->findByEpisode($episodeId, $this->userId);
        return new JSONResponse(['watches' => $watches]);
    }

    #[NoAdminRequired]
    public function create(int $episodeId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $seriesId = $this->resolveOwnedSeriesId($episodeId);
        if ($seriesId === null) {
            return new JSONResponse(['error' => 'Episode not found'], Http::STATUS_NOT_FOUND);
        }

        $data = $this->request->getParams();

        try {
            $watch = $this->watchService->createForEpisode($episodeId, $seriesId, $this->userId, $data);
            return new JSONResponse(['watch' => $watch], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create episode watch', [
                'exception' => $e,
                'userId' => $this->userId,
                'episodeId' => $episodeId,
            ]);
            return new JSONResponse(['error' => 'Failed to log watch. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function update(int $episodeId, int $watchId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        if ($this->resolveOwnedSeriesId($episodeId) === null) {
            return new JSONResponse(['error' => 'Episode not found'], Http::STATUS_NOT_FOUND);
        }

        $data = $this->request->getParams();

        try {
            $watch = $this->watchService->update($watchId, $this->userId, $data);
            return new JSONResponse(['watch' => $watch]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Watch entry not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update episode watch', [
                'exception' => $e,
                'userId' => $this->userId,
                'watchId' => $watchId,
            ]);
            return new JSONResponse(['error' => 'Failed to update watch. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $episodeId, int $watchId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        if ($this->resolveOwnedSeriesId($episodeId) === null) {
            return new JSONResponse(['error' => 'Episode not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->watchService->delete($watchId, $this->userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Watch entry not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
