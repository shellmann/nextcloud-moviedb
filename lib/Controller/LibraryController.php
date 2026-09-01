<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\LibraryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * REST controller for shared-library management.
 *
 * All actions require authentication and use the standard
 * requireAuth() guard inherited from AuthenticatedController.
 */
class LibraryController extends AuthenticatedController {

    private LibraryService $service;
    private ISearch $collaboratorSearch;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        LibraryService $service,
        ISearch $collaboratorSearch,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service             = $service;
        $this->collaboratorSearch  = $collaboratorSearch;
        $this->logger              = $logger;
    }

    /**
     * GET /api/libraries — list all accessible libraries with role annotation.
     */
    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $libraries = $this->service->listAccessible($this->userId);
        return new JSONResponse(['libraries' => $libraries]);
    }

    /**
     * POST /api/libraries — create a new named (non-personal) library.
     */
    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $name = trim((string)$this->request->getParam('name', ''));
        if ($name === '') {
            return new JSONResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $library = $this->service->create($this->userId, $name);
            return new JSONResponse(
                ['library' => $this->service->annotate($library, $this->userId)],
                Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to create library', [
                'exception' => $e,
                'userId'    => $this->userId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to create library. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * PUT /api/libraries/{id} — rename a library (owner only, non-personal).
     */
    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $name = trim((string)$this->request->getParam('name', ''));
        if ($name === '') {
            return new JSONResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $library = $this->service->rename($id, $this->userId, $name);
            return new JSONResponse(['library' => $this->service->annotate($library, $this->userId)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Failed to rename library', [
                'exception' => $e,
                'userId'    => $this->userId,
                'libraryId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update library. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * DELETE /api/libraries/{id} — delete a named library (owner only).
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
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete library', [
                'exception' => $e,
                'userId'    => $this->userId,
                'libraryId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to delete library. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * GET /api/libraries/{id}/members — list members of a library.
     */
    #[NoAdminRequired]
    public function members(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $members = $this->service->listMembers($id, $this->userId);
            return new JSONResponse(['members' => $members]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * POST /api/libraries/{id}/members — add or update a member.
     *
     * Body params:
     *   userId  (string) — Nextcloud user ID to add
     *   canEdit (bool)   — whether to grant edit permission (default false)
     */
    #[NoAdminRequired]
    public function addMember(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $memberUserId = trim((string)$this->request->getParam('userId', ''));
        if ($memberUserId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }

        $canEdit = filter_var(
            $this->request->getParam('canEdit', false),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            $member = $this->service->addMember($id, $this->userId, $memberUserId, $canEdit);
            return new JSONResponse(
                ['member' => $this->service->annotateMember($member)],
                Http::STATUS_CREATED
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add library member', [
                'exception'    => $e,
                'userId'       => $this->userId,
                'libraryId'    => $id,
                'memberUserId' => $memberUserId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to add member. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * DELETE /api/libraries/{id}/members/{userId} — remove a member.
     */
    #[NoAdminRequired]
    public function removeMember(int $id, string $userId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->service->removeMember($id, $this->userId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove library member', [
                'exception'    => $e,
                'userId'       => $this->userId,
                'libraryId'    => $id,
                'memberUserId' => $userId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to remove member. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * GET /api/libraries/sharees?search=... — search Nextcloud users to invite
     * as library members.
     *
     * Returns up to 10 results as [{id, label}], excluding the current user.
     * Uses OCP\Collaboration\Collaborators\ISearch with IShare::TYPE_USER so
     * that Nextcloud admin settings (shareapi_allow_share_dialog_user_enumeration,
     * group-restriction settings) are respected automatically.
     */
    #[NoAdminRequired]
    public function sharees(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $search = trim((string)$this->request->getParam('search', ''));

        // Require at least 1 character to prevent zero-query full enumeration,
        // which would bypass Nextcloud's shareapi_restrict_user_enumeration setting.
        if (strlen($search) < 1) {
            return new JSONResponse(['sharees' => []]);
        }

        [$raw] = $this->collaboratorSearch->search(
            $search,
            [IShare::TYPE_USER],
            false,
            11,  // fetch 11; drop self below, cap at 10
            0
        );

        $wide    = $raw['users']  ?? [];
        $exact   = $raw['exact']['users'] ?? [];
        $results = [];
        $seen    = [];

        // Exact matches first, then wide matches; dedup by UID.
        foreach (array_merge($exact, $wide) as $entry) {
            $uid = $entry['value']['shareWith'] ?? null;
            if ($uid === null || $uid === $this->userId || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $results[]  = [
                'id'    => $uid,
                'label' => $entry['label'],
            ];
            if (count($results) >= 10) {
                break;
            }
        }

        return new JSONResponse(['sharees' => $results]);
    }

    /**
     * DELETE /api/libraries/{id}/leave — leave a library (member removes themselves).
     */
    #[NoAdminRequired]
    public function leave(int $id): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        try {
            $this->service->removeMember($id, $this->userId, $this->userId);
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Failed to leave library', [
                'exception' => $e,
                'userId'    => $this->userId,
                'libraryId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to leave library. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }
}
