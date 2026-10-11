<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\QuizResultService;
use OCA\MovieDB\Service\QuizService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Quiz about the active library. Anyone with read access may play and keep
 * their own results (viewers included), so there is deliberately no edit check.
 */
class QuizController extends AuthenticatedController {

    public function __construct(
        IRequest $request,
        private QuizService $quizService,
        private QuizResultService $resultService,
        private LibraryService $libraryService,
        IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
    }

    /**
     * How many questions the active library supports.
     */
    #[NoAdminRequired]
    public function meta(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        return new JSONResponse($this->quizService->meta($libraryId, $this->userId));
    }

    /**
     * Generate a round of `count` questions (clamped to what the library supports).
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 600)]
    public function generate(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);
        $count = (int)$this->request->getParam('count', 10);

        return new JSONResponse([
            'questions' => $this->quizService->generate($libraryId, $this->userId, $count),
        ]);
    }

    /**
     * The user's finished rounds (oldest first), best round and accuracy per type.
     */
    #[NoAdminRequired]
    public function results(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraryId = $this->libraryService->resolveReadLibraryId($this->requestedLibraryId(), $this->userId);

        return new JSONResponse($this->resultService->list($this->userId, $libraryId));
    }

    /**
     * Save a finished round.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 600)]
    public function saveResult(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        try {
            $result = $this->resultService->create($this->userId, $libraryId, [
                'questionCount' => $this->request->getParam('questionCount'),
                'score' => $this->request->getParam('score'),
                'maxScore' => $this->request->getParam('maxScore'),
                'breakdown' => $this->request->getParam('breakdown'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save quiz result', ['exception' => $e, 'userId' => $this->userId]);
            return new JSONResponse(['error' => 'Failed to save the quiz result.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['result' => $result], Http::STATUS_CREATED);
    }

    /**
     * Delete the user's own results in the active library.
     */
    #[NoAdminRequired]
    public function resetResults(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['deleted' => $this->resultService->reset($this->userId, $libraryId)]);
    }
}
