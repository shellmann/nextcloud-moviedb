<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\PlatformService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PlatformController extends Controller {
    private PlatformService $service;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        PlatformService $service,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userSession->getUser()?->getUID();
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $platforms = $this->service->findAllForUser($this->userId);

        return new JSONResponse([
            'platforms' => $platforms,
        ]);
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = $this->request->getParams();

        if (empty($data['name'])) {
            return new JSONResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $platform = $this->service->create($this->userId, $data);
            return new JSONResponse(['platform' => $platform], Http::STATUS_CREATED);
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
            $platform = $this->service->update($id, $this->userId, $data);
            return new JSONResponse(['platform' => $platform]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Platform not found'], Http::STATUS_NOT_FOUND);
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
            return new JSONResponse(['error' => 'Platform not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
