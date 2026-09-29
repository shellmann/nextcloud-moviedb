<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\ExportService;
use OCA\MovieDB\Service\ImportService;
use OCA\MovieDB\Service\ImportValidator;
use OCA\MovieDB\Service\LibraryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Export and import of one library as a portable JSON file.
 *
 * Export needs read access to the library, import needs edit permission.
 * The uploaded file is untrusted: it is size-capped, decoded with a depth
 * limit and re-built field by field by ImportValidator before anything is
 * written.
 */
class ImportExportController extends AuthenticatedController {
    /** Upper bound for an uploaded export file (bytes). */
    public const MAX_FILE_SIZE = 25 * 1024 * 1024;

    public function __construct(
        IRequest $request,
        private ExportService $exportService,
        private ImportService $importService,
        private ImportValidator $validator,
        private LibraryService $libraryService,
        private IDateTimeZone $dateTimeZone,
        IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
    }

    /**
     * Download the active library as a JSON file.
     */
    #[NoAdminRequired]
    public function export(): JSONResponse|DataDownloadResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        // Unlike ordinary reads, do not fall back silently to the personal
        // library: a backup of a different library than requested is worse
        // than an error.
        try {
            $libraryId = $this->libraryService->resolveLibraryId($this->requestedLibraryId(), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Library not found or access denied.'], Http::STATUS_FORBIDDEN);
        }

        try {
            $data = $this->exportService->export($libraryId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Library not found.'], Http::STATUS_NOT_FOUND);
        }

        $filename = ExportService::filename(
            $data['library']['name'],
            new \DateTimeImmutable('now', $this->dateTimeZone->getTimeZone())
        );

        return new DataDownloadResponse(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $filename,
            'application/json'
        );
    }

    /**
     * Import an export file into the active library (multipart field "file").
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 600)]
    public function import(): JSONResponse {
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

        $file = $this->request->getUploadedFile('file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $tooBig = is_array($file) && in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return new JSONResponse(
                ['error' => $tooBig ? 'The file is too large.' : 'No file was uploaded.'],
                $tooBig ? Http::STATUS_REQUEST_ENTITY_TOO_LARGE : Http::STATUS_BAD_REQUEST
            );
        }
        if (($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
            return new JSONResponse(['error' => 'The file is too large.'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        $content = file_get_contents($file['tmp_name'], false, null, 0, self::MAX_FILE_SIZE + 1);
        if ($content === false || strlen($content) > self::MAX_FILE_SIZE) {
            return new JSONResponse(['error' => 'The file could not be read.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JSONResponse(['error' => 'The file is not valid JSON.'], Http::STATUS_BAD_REQUEST);
        }
        unset($content);
        if (!is_array($decoded)) {
            return new JSONResponse(['error' => 'This is not a MovieDB export file.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $validated = $this->validator->validate($decoded);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        unset($decoded);

        try {
            $result = $this->importService->import($libraryId, $this->userId, $validated);
        } catch (\Throwable $e) {
            $this->logger->error('Library import failed', ['exception' => $e, 'userId' => $this->userId]);
            return new JSONResponse(
                ['error' => 'The import failed and nothing was changed. Please try again.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($result);
    }
}
