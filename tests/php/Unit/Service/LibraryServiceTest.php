<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\QuizResultMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserManager;

class LibraryServiceTest extends TestCase {

    private LibraryMapper $libraryMapper;
    private LibraryMemberMapper $memberMapper;
    private MovieMapper $movieMapper;
    private SeriesMapper $seriesMapper;
    private WatchlistMapper $watchlistMapper;
    private MovieWatchMapper $watchMapper;
    private EpisodeMapper $episodeMapper;
    private QuizResultMapper $quizResultMapper;
    private IUserManager $userManager;
    private IDBConnection $db;
    private LibraryService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->libraryMapper  = $this->createMock(LibraryMapper::class);
        $this->memberMapper   = $this->createMock(LibraryMemberMapper::class);
        $this->movieMapper    = $this->createMock(MovieMapper::class);
        $this->seriesMapper   = $this->createMock(SeriesMapper::class);
        $this->watchlistMapper = $this->createMock(WatchlistMapper::class);
        $this->watchMapper    = $this->createMock(MovieWatchMapper::class);
        $this->episodeMapper  = $this->createMock(EpisodeMapper::class);
        $this->quizResultMapper = $this->createMock(QuizResultMapper::class);
        $this->userManager    = $this->createMock(IUserManager::class);
        $this->db             = $this->createMock(IDBConnection::class);

        $this->service = new LibraryService(
            $this->libraryMapper,
            $this->memberMapper,
            $this->movieMapper,
            $this->seriesMapper,
            $this->watchlistMapper,
            $this->watchMapper,
            $this->episodeMapper,
            $this->quizResultMapper,
            $this->userManager,
            $this->db
        );
    }

    // ── getOwner ──────────────────────────────────────────────────────────────

    public function testGetOwnerReturnsTheLibraryOwner(): void {
        $library = new Library();
        $library->setOwner('anna');
        $this->libraryMapper->method('find')->with(4)->willReturn($library);

        $this->assertSame('anna', $this->service->getOwner(4));
    }

    public function testGetOwnerIsNullForAnUnknownLibrary(): void {
        $this->libraryMapper->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('x'));

        $this->assertNull($this->service->getOwner(99));
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsNewLibrary(): void {
        $owner = 'alice';
        $name  = 'Family Movies';

        $this->libraryMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Library $lib) use ($owner, $name): bool {
                return $lib->getOwner() === $owner
                    && $lib->getName() === $name
                    && $lib->getIsPersonal() === false;
            }))
            ->willReturnCallback(function (Library $lib): Library {
                $lib->setId(42);
                return $lib;
            });

        $result = $this->service->create($owner, $name);

        $this->assertInstanceOf(Library::class, $result);
        $this->assertEquals(42, $result->getId());
        $this->assertEquals($name, $result->getName());
        $this->assertFalse($result->getIsPersonal());
    }

    // ── rename ────────────────────────────────────────────────────────────────

    public function testRenameUpdatesName(): void {
        $lib = $this->makeLibrary(1, 'alice', 'Old Name', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($lib);

        $this->libraryMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->rename(1, 'alice', 'New Name');

        $this->assertEquals('New Name', $result->getName());
    }

    public function testRenameThrowsWhenNotOwner(): void {
        $lib = $this->makeLibrary(1, 'alice', 'My Lib', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->rename(1, 'bob', 'Hacked Name');
    }

    public function testRenameThrowsForPersonalLibrary(): void {
        $lib = $this->makeLibrary(1, 'alice', 'Personal', true);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->rename(1, 'alice', 'Renamed');
    }

    // ── addMember ─────────────────────────────────────────────────────────────

    public function testAddMemberInsertsNewMembership(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn(null);

        $this->memberMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (LibraryMember $m): bool {
                return $m->getLibraryId() === 5
                    && $m->getUserId() === 'bob'
                    && $m->getPermissionEdit() === false;
            }))
            ->willReturnCallback(function (LibraryMember $m): LibraryMember {
                $m->setId(99);
                return $m;
            });

        $result = $this->service->addMember(5, 'alice', 'bob', false);

        $this->assertEquals(99, $result->getId());
        $this->assertEquals('bob', $result->getUserId());
    }

    public function testAddMemberUpdatesExistingPermission(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $existing = new LibraryMember();
        $existing->setId(99);
        $existing->setLibraryId(5);
        $existing->setUserId('bob');
        $existing->setPermissionEdit(false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->willReturn($existing);

        $this->memberMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (LibraryMember $m): LibraryMember {
                return $m;
            });

        $result = $this->service->addMember(5, 'alice', 'bob', true);

        $this->assertTrue($result->getPermissionEdit());
    }

    public function testAddMemberThrowsWhenNotOwner(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->addMember(5, 'bob', 'charlie', false);
    }

    public function testAddMemberThrowsForPersonalLibrary(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Personal', true);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->addMember(5, 'alice', 'bob', false);
    }

    // ── removeMember ──────────────────────────────────────────────────────────

    public function testOwnerCanRemoveMember(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $membership = $this->makeMembership(5, 'bob', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn($membership);

        $this->memberMapper->expects($this->once())
            ->method('delete')
            ->with($membership);

        $this->service->removeMember(5, 'alice', 'bob');
    }

    public function testMemberCanRemoveThemselves(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $membership = $this->makeMembership(5, 'bob', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn($membership);

        $this->memberMapper->expects($this->once())
            ->method('delete')
            ->with($membership);

        // bob removes themselves (acting === target)
        $this->service->removeMember(5, 'bob', 'bob');
    }

    public function testNonOwnerCannotRemoveOtherMember(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/owner/');
        $this->service->removeMember(5, 'bob', 'charlie');
    }

    public function testOwnerCannotBeRemoved(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/owner cannot be removed/i');
        $this->service->removeMember(5, 'alice', 'alice');
    }

    public function testRemoveMemberIsIdempotentWhenNotMember(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->willReturn(null);

        // delete should never be called — silently succeed
        $this->memberMapper->expects($this->never())
            ->method('delete');

        $this->service->removeMember(5, 'alice', 'bob');
    }

    // ── canAccess / canEdit ───────────────────────────────────────────────────

    public function testCanAccessReturnsTrueForOwner(): void {
        $personal = $this->makeLibrary(1, 'alice', 'Personal', true);

        $this->libraryMapper->expects($this->once())
            ->method('findAccessible')
            ->with('alice')
            ->willReturn([$personal]);

        $this->assertTrue($this->service->canAccess(1, 'alice'));
    }

    public function testCanAccessReturnsFalseForUnrelatedUser(): void {
        $this->libraryMapper->expects($this->once())
            ->method('findAccessible')
            ->with('charlie')
            ->willReturn([]);

        $this->assertFalse($this->service->canAccess(1, 'charlie'));
    }

    public function testCanEditReturnsTrueForOwner(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($lib);

        $this->assertTrue($this->service->canEdit(5, 'alice'));
    }

    public function testCanEditReturnsTrueForEditorMember(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);
        $membership = $this->makeMembership(5, 'bob', true);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn($membership);

        $this->assertTrue($this->service->canEdit(5, 'bob'));
    }

    public function testCanEditReturnsFalseForViewerMember(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);
        $membership = $this->makeMembership(5, 'bob', false);

        $this->libraryMapper->expects($this->once())
            ->method('find')
            ->willReturn($lib);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->willReturn($membership);

        $this->assertFalse($this->service->canEdit(5, 'bob'));
    }

    // ── annotate ─────────────────────────────────────────────────────────────

    public function testAnnotateOwnerRole(): void {
        $lib = $this->makeLibrary(1, 'alice', 'Personal', true);

        $result = $this->service->annotate($lib, 'alice');

        $this->assertEquals('owner', $result['role']);
        $this->assertTrue($result['permissionEdit']);
    }

    public function testAnnotateEditorRole(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);
        $membership = $this->makeMembership(5, 'bob', true);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn($membership);

        $result = $this->service->annotate($lib, 'bob');

        $this->assertEquals('editor', $result['role']);
        $this->assertTrue($result['permissionEdit']);
    }

    public function testAnnotateViewerRole(): void {
        $lib = $this->makeLibrary(5, 'alice', 'Shared', false);
        $membership = $this->makeMembership(5, 'bob', false);

        $this->memberMapper->expects($this->once())
            ->method('findMembership')
            ->with(5, 'bob')
            ->willReturn($membership);

        $result = $this->service->annotate($lib, 'bob');

        $this->assertEquals('viewer', $result['role']);
        $this->assertFalse($result['permissionEdit']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeLibrary(int $id, string $owner, string $name, bool $isPersonal): Library {
        $lib = new Library();
        $lib->setId($id);
        $lib->setOwner($owner);
        $lib->setName($name);
        $lib->setIsPersonal($isPersonal);
        $lib->setCreatedAt('2026-01-01 00:00:00');
        return $lib;
    }

    private function makeMembership(int $libraryId, string $userId, bool $canEdit): LibraryMember {
        $m = new LibraryMember();
        $m->setId(1);
        $m->setLibraryId($libraryId);
        $m->setUserId($userId);
        $m->setPermissionEdit($canEdit);
        $m->setCreatedAt('2026-01-01 00:00:00');
        return $m;
    }

    // ── delete / purgeLibrary ─────────────────────────────────────────────────

    public function testDeleteRejectsNonOwner(): void {
        $library = new Library();
        $library->setId(5);
        $library->setOwner('alice');
        $this->libraryMapper->method('find')->willReturn($library);

        $this->libraryMapper->expects($this->never())->method('delete');
        $this->expectException(\InvalidArgumentException::class);

        $this->service->delete(5, 'mallory');
    }

    public function testDeleteRejectsPersonalLibrary(): void {
        $library = new Library();
        $library->setId(5);
        $library->setOwner('alice');
        $library->setIsPersonal(true);
        $this->libraryMapper->method('find')->willReturn($library);

        $this->libraryMapper->expects($this->never())->method('delete');
        $this->expectException(\InvalidArgumentException::class);

        $this->service->delete(5, 'alice');
    }

    public function testDeleteRunsCascadeInTransaction(): void {
        $library = new Library();
        $library->setId(5);
        $library->setOwner('alice');
        $this->libraryMapper->method('find')->willReturn($library);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->quizResultMapper->expects($this->once())->method('deleteByLibrary')->with(5);
        $this->libraryMapper->expects($this->once())->method('delete')->with($library);
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->service->delete(5, 'alice');
    }

    public function testDeleteRollsBackOnFailure(): void {
        $library = new Library();
        $library->setId(5);
        $library->setOwner('alice');
        $this->libraryMapper->method('find')->willReturn($library);
        $this->movieMapper->method('deleteByLibrary')->willThrowException(new \RuntimeException('boom'));

        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');
        $this->expectException(\RuntimeException::class);

        $this->service->delete(5, 'alice');
    }

    public function testPurgeLibraryRemovesEverythingWithoutGuards(): void {
        $library = new Library();
        $library->setId(9);
        $library->setOwner('alice');
        $library->setIsPersonal(true);
        $this->libraryMapper->method('find')->willReturn($library);

        $this->episodeMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->watchMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->watchlistMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->seriesMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->movieMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->quizResultMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->memberMapper->expects($this->once())->method('deleteByLibrary')->with(9);
        $this->libraryMapper->expects($this->once())->method('delete')->with($library);
        // purgeLibrary leaves transaction handling to its caller
        $this->db->expects($this->never())->method('beginTransaction');

        $this->service->purgeLibrary(9);
    }
}
