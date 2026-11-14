<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\QuizResultMapper;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\UserDataCleanupService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;

class UserDataCleanupServiceTest extends TestCase {

    private LibraryMapper&Stub $libraryMapper;
    private LibraryMemberMapper&Stub $memberMapper;
    private PlatformMapper&Stub $platformMapper;
    private MovieWatchMapper&Stub $watchMapper;
    private QuizResultMapper&Stub $quizResultMapper;
    private LibraryService&Stub $libraryService;
    private IDBConnection&Stub $db;
    private ?UserDataCleanupService $service = null;

    protected function setUp(): void {
        parent::setUp();

        $this->libraryMapper    = $this->createStub(LibraryMapper::class);
        $this->memberMapper     = $this->createStub(LibraryMemberMapper::class);
        $this->platformMapper   = $this->createStub(PlatformMapper::class);
        $this->watchMapper      = $this->createStub(MovieWatchMapper::class);
        $this->quizResultMapper = $this->createStub(QuizResultMapper::class);
        $this->libraryService   = $this->createStub(LibraryService::class);
        $this->db               = $this->createStub(IDBConnection::class);
    }

    private function service(): UserDataCleanupService {
        return $this->service ??= new UserDataCleanupService(
            $this->libraryMapper,
            $this->memberMapper,
            $this->platformMapper,
            $this->watchMapper,
            $this->quizResultMapper,
            $this->libraryService,
            $this->db,
            $this->createStub(LoggerInterface::class),
        );
    }

    private function library(int $id, bool $personal): Library {
        $library = new Library();
        $library->setId($id);
        $library->setOwner('alice');
        $library->setIsPersonal($personal);
        return $library;
    }

    private function member(int $id, string $user, bool $edit, string $created): LibraryMember {
        $m = new LibraryMember();
        $m->setId($id);
        $m->setUserId($user);
        $m->setPermissionEdit($edit);
        $m->setCreatedAt($created);
        return $m;
    }

    public function testPersonalLibraryIsPurged(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->libraryMapper->method('findByOwner')->willReturn([$this->library(1, true)]);

        $this->libraryService->expects($this->once())->method('purgeLibrary')->with(1);
        $this->libraryMapper->expects($this->never())->method('update');
        $this->db->expects($this->once())->method('commit');

        $this->service()->deleteUserData('alice');
    }

    public function testSharedLibraryGoesToEditorEvenIfViewerIsOlder(): void {
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->memberMapper = $this->createMock(LibraryMemberMapper::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $library = $this->library(2, false);
        $this->libraryMapper->method('findByOwner')->willReturn([$library]);
        $viewer = $this->member(10, 'viewer', false, '2026-01-01 00:00:00');
        $editor = $this->member(11, 'editor', true, '2026-02-01 00:00:00');
        $this->memberMapper->method('findByLibrary')->with(2)->willReturn([$viewer, $editor]);

        $this->libraryService->expects($this->never())->method('purgeLibrary');
        $this->libraryMapper->expects($this->once())->method('update')
            ->with($this->callback(fn (Library $l) => $l->getOwner() === 'editor'));
        $this->memberMapper->expects($this->once())->method('delete')->with($editor);

        $this->service()->deleteUserData('alice');
    }

    public function testOldestEditorWinsAmongEditors(): void {
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->libraryMapper->method('findByOwner')->willReturn([$this->library(2, false)]);
        $newer = $this->member(12, 'newer', true, '2026-03-01 00:00:00');
        $older = $this->member(13, 'older', true, '2026-02-01 00:00:00');
        $this->memberMapper->method('findByLibrary')->willReturn([$newer, $older]);

        $this->libraryMapper->expects($this->once())->method('update')
            ->with($this->callback(fn (Library $l) => $l->getOwner() === 'older'));

        $this->service()->deleteUserData('alice');
    }

    public function testViewerOnlyLibraryGoesToOldestViewer(): void {
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->libraryMapper->method('findByOwner')->willReturn([$this->library(2, false)]);
        $newer = $this->member(12, 'newer', false, '2026-03-01 00:00:00');
        $older = $this->member(13, 'older', false, '2026-02-01 00:00:00');
        $this->memberMapper->method('findByLibrary')->willReturn([$newer, $older]);

        $this->libraryMapper->expects($this->once())->method('update')
            ->with($this->callback(fn (Library $l) => $l->getOwner() === 'older'));

        $this->service()->deleteUserData('alice');
    }

    public function testSharedLibraryWithoutMembersIsPurged(): void {
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->libraryMapper->method('findByOwner')->willReturn([$this->library(2, false)]);
        $this->memberMapper->method('findByLibrary')->willReturn([]);

        $this->libraryService->expects($this->once())->method('purgeLibrary')->with(2);
        $this->libraryMapper->expects($this->never())->method('update');

        $this->service()->deleteUserData('alice');
    }

    public function testMembershipsPlatformsAndQuizResultsAreRemoved(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->quizResultMapper = $this->createMock(QuizResultMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->platformMapper = $this->createMock(PlatformMapper::class);
        $this->memberMapper = $this->createMock(LibraryMemberMapper::class);
        $this->libraryMapper->method('findByOwner')->willReturn([]);
        $this->platformMapper->method('findCustomIdsByUser')->with('alice')->willReturn([5, 6]);

        $this->memberMapper->expects($this->once())->method('deleteByUser')->with('alice');
        $this->watchMapper->expects($this->once())->method('clearPlatforms')->with([5, 6]);
        $this->platformMapper->expects($this->once())->method('deleteCustomByUser')->with('alice');
        $this->quizResultMapper->expects($this->once())->method('deleteByUser')->with('alice');
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->service()->deleteUserData('alice');
    }

    public function testFailureRollsBackAndRethrows(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->libraryMapper->method('findByOwner')->willReturn([]);
        $this->quizResultMapper->method('deleteByUser')->willThrowException(new \RuntimeException('boom'));

        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');
        $this->expectException(\RuntimeException::class);

        $this->service()->deleteUserData('alice');
    }
}
