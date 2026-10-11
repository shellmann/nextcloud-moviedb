<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\QuizResultMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Removes the MovieDB data of a deleted Nextcloud user.
 *
 * - Personal libraries are purged (they cannot have members).
 * - A shared library owned by the user goes to the oldest member with edit
 *   permission, else to the oldest member; without members it is purged.
 * - Memberships, custom platforms and quiz results of the user are deleted.
 *   Watches that referenced a deleted custom platform lose the platform.
 *
 * Rows the user created inside libraries that survive (movies, watches, …)
 * stay: they belong to the library, not to their creator.
 *
 * Everything runs in ONE transaction: either all of it happens or none.
 */
class UserDataCleanupService {

    public function __construct(
        private LibraryMapper $libraryMapper,
        private LibraryMemberMapper $memberMapper,
        private PlatformMapper $platformMapper,
        private MovieWatchMapper $watchMapper,
        private QuizResultMapper $quizResultMapper,
        private LibraryService $libraryService,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function deleteUserData(string $userId): void {
        $purged = 0;
        $transferred = 0;

        $this->db->beginTransaction();
        try {
            foreach ($this->libraryMapper->findByOwner($userId) as $library) {
                $heir = $library->getIsPersonal() ? null : $this->pickHeir($library->getId());

                if ($heir === null) {
                    $this->libraryService->purgeLibrary($library->getId());
                    $purged++;
                    continue;
                }

                $library->setOwner($heir->getUserId());
                $this->libraryMapper->update($library);
                // The owner has no member row.
                $this->memberMapper->delete($heir);
                $transferred++;
            }

            $this->memberMapper->deleteByUser($userId);

            $platformIds = $this->platformMapper->findCustomIdsByUser($userId);
            $this->watchMapper->clearPlatforms($platformIds);
            $this->platformMapper->deleteCustomByUser($userId);

            $this->quizResultMapper->deleteByUser($userId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('MovieDB: cleaned up data of deleted user', [
            'userId' => $userId,
            'librariesPurged' => $purged,
            'librariesTransferred' => $transferred,
            'platformsDeleted' => count($platformIds),
        ]);
    }

    /**
     * Editors first, then oldest membership (created_at, then id).
     */
    private function pickHeir(int $libraryId): ?LibraryMember {
        $members = $this->memberMapper->findByLibrary($libraryId);
        if ($members === []) {
            return null;
        }

        usort($members, static function (LibraryMember $a, LibraryMember $b): int {
            return [!$a->getPermissionEdit(), $a->getCreatedAt(), $a->getId()]
                <=> [!$b->getPermissionEdit(), $b->getCreatedAt(), $b->getId()];
        });

        return $members[0];
    }
}
