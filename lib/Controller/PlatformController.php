<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\PlatformService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PlatformController extends AuthenticatedController {
    private PlatformService $service;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        PlatformService $service,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $platforms = $this->service->findAllForUser($this->userId);

        return new JSONResponse([
            'platforms' => $platforms,
        ]);
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $data = $this->request->getParams();

        if (empty($data['name'])) {
            return new JSONResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $platform = $this->service->create($this->userId, $data);
            return new JSONResponse(['platform' => $platform], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create platform', [
                'exception' => $e,
                'userId' => $this->userId,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to create platform. Please try again.'],
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
            $platform = $this->service->update($id, $this->userId, $data);
            return new JSONResponse(['platform' => $platform]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Platform not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update platform', [
                'exception' => $e,
                'userId' => $this->userId,
                'platformId' => $id,
                'data' => $data,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update platform. Please try again.'],
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
            return new JSONResponse(['error' => 'Platform not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete platform', [
                'exception' => $e,
                'userId' => $this->userId,
                'platformId' => $id,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to delete platform. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }
}
