<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Service\MovieWatchService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class MovieWatchController extends AuthenticatedController {
    private MovieService $movieService;
    private MovieWatchService $watchService;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        MovieService $movieService,
        MovieWatchService $watchService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->movieService = $movieService;
        $this->watchService = $watchService;
        $this->logger = $logger;
    }

    /**
     * List all watch entries for a movie.
     *
     * @param int $movieId Movie ID
     */
    #[NoAdminRequired]
    public function index(int $movieId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->movieService->find($movieId, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Movie not found'], Http::STATUS_NOT_FOUND);
        }

        $watches = $this->watchService->findByMovie($movieId, $this->userId);

        return new JSONResponse(['watches' => $watches]);
    }

    /**
     * Log a new watch of a movie.
     *
     * @param int $movieId Movie ID
     */
    #[NoAdminRequired]
    public function create(int $movieId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->movieService->find($movieId, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Movie not found'], Http::STATUS_NOT_FOUND);
        }

        $data = $this->request->getParams();

        try {
            $watch = $this->watchService->create($movieId, $this->userId, $data);
            return new JSONResponse(['watch' => $watch], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create watch', [
                'exception' => $e,
                'userId' => $this->userId,
                'movieId' => $movieId,
            ]);
            return new JSONResponse(['error' => 'Failed to log watch. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Update a watch entry.
     *
     * @param int $movieId  Movie ID (for ownership verification)
     * @param int $watchId  Watch entry ID
     */
    #[NoAdminRequired]
    public function update(int $movieId, int $watchId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
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
            $this->logger->error('Failed to update watch', [
                'exception' => $e,
                'userId' => $this->userId,
                'watchId' => $watchId,
            ]);
            return new JSONResponse(['error' => 'Failed to update watch. Please try again.'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Delete a watch entry.
     *
     * @param int $movieId  Movie ID (for ownership verification)
     * @param int $watchId  Watch entry ID
     */
    #[NoAdminRequired]
    public function destroy(int $movieId, int $watchId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->watchService->delete($watchId, $this->userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Watch entry not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
