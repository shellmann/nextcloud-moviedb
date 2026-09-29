<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\ImportExportController;
use OCA\MovieDB\Service\ExportService;
use OCA\MovieDB\Service\ImportService;
use OCA\MovieDB\Service\ImportValidator;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ImportExportControllerTest extends TestCase {
    private IRequest $request;
    private ExportService $exportService;
    private ImportService $importService;
    private LibraryService $libraryService;
    private LoggerInterface $logger;
    private ImportExportController $controller;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->exportService = $this->createMock(ExportService::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $zone = $this->createMock(IDateTimeZone::class);
        $zone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));

        $this->controller = new ImportExportController(
            $this->request,
            $this->exportService,
            $this->importService,
            new ImportValidator(),
            $this->libraryService,
            $zone,
            $session,
            $this->logger
        );
    }

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function upload(string $content, int $error = UPLOAD_ERR_OK, ?int $size = null): void {
        $path = tempnam(sys_get_temp_dir(), 'moviedb-test');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        $this->request->method('getUploadedFile')->with('file')->willReturn([
            'error' => $error, 'tmp_name' => $path, 'size' => $size ?? strlen($content),
        ]);
    }

    private function validFile(): string {
        return json_encode(['app' => 'moviedb', 'formatVersion' => 1, 'movies' => [['title' => 'A']]]);
    }

    // ── export ─────────────────────────────────────────────────────────────

    public function testExportRefusesLibraryTheUserCannotAccess(): void {
        $this->libraryService->method('resolveLibraryId')
            ->willThrowException(new \InvalidArgumentException('denied'));
        $this->exportService->expects($this->never())->method('export');

        $response = $this->controller->export();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testExportNeverFallsBackToTheReadResolver(): void {
        $this->libraryService->expects($this->never())->method('resolveReadLibraryId');
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->exportService->method('export')->willReturn(['library' => ['name' => 'X']]);

        $this->controller->export();
    }

    public function testExportReturnsDownloadForAccessibleLibrary(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->exportService->expects($this->once())->method('export')->with(3)
            ->willReturn(['library' => ['name' => 'Family']]);

        $this->assertInstanceOf(DataDownloadResponse::class, $this->controller->export());
    }

    // ── import ─────────────────────────────────────────────────────────────

    public function testImportRefusesUnknownLibrary(): void {
        $this->libraryService->method('resolveLibraryId')
            ->willThrowException(new \InvalidArgumentException('denied'));
        $this->importService->expects($this->never())->method('import');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->import()->getStatus());
    }

    public function testImportRefusesViewers(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(false);
        $this->request->expects($this->never())->method('getUploadedFile');
        $this->importService->expects($this->never())->method('import');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->import()->getStatus());
    }

    public function testImportWithoutFileIsABadRequest(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn(null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->import()->getStatus());
    }

    public function testImportRejectsOversizedFile(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload($this->validFile(), UPLOAD_ERR_OK, ImportExportController::MAX_FILE_SIZE + 1);
        $this->importService->expects($this->never())->method('import');

        $this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->controller->import()->getStatus());
    }

    public function testImportRejectsFileTooLargeForPhp(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload('', UPLOAD_ERR_INI_SIZE);

        $this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->controller->import()->getStatus());
    }

    public function testImportRejectsInvalidJson(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload('{not json');

        $response = $this->controller->import();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('The file is not valid JSON.', $response->getData()['error']);
    }

    public function testImportRejectsForeignFileWithValidatorMessage(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload('{"app":"other","formatVersion":1}');
        $this->importService->expects($this->never())->method('import');

        $response = $this->controller->import();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('This is not a MovieDB export file.', $response->getData()['error']);
    }

    public function testImportPassesCurrentUserAndResolvedLibraryToService(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload($this->validFile());
        $result = ['imported' => ['movies' => 1], 'skippedDuplicates' => [], 'invalid' => []];
        $this->importService->expects($this->once())->method('import')
            ->with(3, 'alice', $this->callback(fn ($d) => $d['movies'][0]['title'] === 'A'))
            ->willReturn($result);

        $response = $this->controller->import();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testImportFailureIsLoggedAndDoesNotLeakTheException(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(3);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->upload($this->validFile());
        $this->importService->method('import')->willThrowException(new \RuntimeException('SQLSTATE secret detail'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->import();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
    }
}
