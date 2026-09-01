<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Base controller for authenticated API endpoints.
 *
 * Provides a reusable authentication check to eliminate code duplication
 * across all authenticated controllers in the MovieDB app.
 */
abstract class AuthenticatedController extends Controller {
    protected ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userSession->getUser()?->getUID();
    }

    /**
     * Validates that the user is authenticated.
     *
     * Returns an error response if not authenticated, otherwise returns null
     * to indicate the request should proceed.
     *
     * Usage in controller methods:
     * ```php
     * public function index(): JSONResponse {
     *     if ($error = $this->requireAuth()) {
     *         return $error;
     *     }
     *     // ... continue with authenticated logic
     * }
     * ```
     *
     * @return JSONResponse|null Error response if not authenticated, null if authenticated
     */
    protected function requireAuth(): ?JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }
        return null;
    }

    /**
     * Parse the optional libraryId request param.
     *
     * Returns the integer value when the param is present, or null when absent
     * (callers must then fall back to the personal library via LibraryService).
     */
    protected function requestedLibraryId(): ?int {
        $p = $this->request->getParam('libraryId');
        return $p !== null ? (int)$p : null;
    }
}
