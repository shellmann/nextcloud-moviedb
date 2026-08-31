<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\IUserManager;

class LibraryService {

    private LibraryMapper $libraryMapper;
    private LibraryMemberMapper $memberMapper;
    private MovieMapper $movieMapper;
    private SeriesMapper $seriesMapper;
    private WatchlistMapper $watchlistMapper;
    private MovieWatchMapper $watchMapper;
    private EpisodeMapper $episodeMapper;
    private IUserManager $userManager;
    private IDBConnection $db;

    public function __construct(
        LibraryMapper $libraryMapper,
        LibraryMemberMapper $memberMapper,
        MovieMapper $movieMapper,
        SeriesMapper $seriesMapper,
        WatchlistMapper $watchlistMapper,
        MovieWatchMapper $watchMapper,
        EpisodeMapper $episodeMapper,
        IUserManager $userManager,
        IDBConnection $db
    ) {
        $this->libraryMapper   = $libraryMapper;
        $this->memberMapper    = $memberMapper;
        $this->movieMapper     = $movieMapper;
        $this->seriesMapper    = $seriesMapper;
        $this->watchlistMapper = $watchlistMapper;
        $this->watchMapper     = $watchMapper;
        $this->episodeMapper   = $episodeMapper;
        $this->userManager     = $userManager;
        $this->db              = $db;
    }

    // ── Access helpers ────────────────────────────────────────────────────────

    /**
     * Return the IDs of all libraries accessible to the given user (owned +
     * member).
     *
     * @return int[]
     */
    public function getAccessibleLibraryIds(string $userId): array {
        return array_map(
            static fn (Library $lib): int => $lib->getId(),
            $this->libraryMapper->findAccessible($userId)
        );
    }

    /**
     * Return true if the library exists and the user can access it (owner or
     * member).
     */
    public function canAccess(int $libraryId, string $userId): bool {
        return in_array($libraryId, $this->getAccessibleLibraryIds($userId), true);
    }

    /**
     * Return true if the user may write to the library (is owner, or is a
     * member with permission_edit=true).
     */
    public function canEdit(int $libraryId, string $userId): bool {
        try {
            $library = $this->libraryMapper->find($libraryId);
        } catch (DoesNotExistException $e) {
            return false;
        }

        if ($library->getOwner() === $userId) {
            return true;
        }

        $membership = $this->memberMapper->findMembership($libraryId, $userId);
        return $membership !== null && $membership->getPermissionEdit();
    }

    /**
     * Return the personal library ID for $userId, lazily creating it if it
     * does not yet exist (handles users created after the migration ran).
     */
    public function getPersonalLibraryId(string $userId): int {
        $existing = $this->libraryMapper->findPersonal($userId);
        if ($existing !== null) {
            return $existing->getId();
        }

        // Lazy-seed: mirrors PlatformService::findAllForUser seeding defaults.
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $library = new Library();
        $library->setOwner($userId);
        $library->setName('Personal');
        $library->setIsPersonal(true);
        $library->setCreatedAt($now);

        try {
            $inserted = $this->libraryMapper->insert($library);
        } catch (Exception $e) {
            // Insert failed outright — a concurrent request may already have
            // created the row. Fall back to whatever now exists.
            $existing = $this->libraryMapper->findPersonal($userId);
            if ($existing !== null) {
                return $existing->getId();
            }
            throw $e;
        }

        // Reconcile the check-then-insert race: there is no portable unique
        // index on (owner, is_personal) — owners may have many non-personal
        // libraries sharing (owner, false), and a partial unique index is not
        // portable across SQLite/MySQL/Postgres. So concurrent first-load
        // requests can each insert a personal library. findPersonal() returns
        // the lowest id deterministically; if the row we just inserted is NOT
        // that survivor, drop it (it is brand-new and has no child rows) and
        // return the survivor. Every racer converges on the same library.
        $survivor = $this->libraryMapper->findPersonal($userId);
        if ($survivor !== null && $survivor->getId() !== $inserted->getId()) {
            try {
                $this->libraryMapper->delete($inserted);
            } catch (Exception $e) {
                // Best-effort cleanup; the periodic V7-style heal covers leftovers.
            }
            return $survivor->getId();
        }

        return $inserted->getId();
    }

    /**
     * Central resolver used by read controllers: if $requested is provided and
     * the user can access it, return it; otherwise fall back to the personal
     * library. Silent fallback is safe for reads (returns whatever the user
     * is allowed to see).
     */
    public function resolveReadLibraryId(?int $requested, string $userId): int {
        if ($requested !== null && $this->canAccess($requested, $userId)) {
            return $requested;
        }
        return $this->getPersonalLibraryId($userId);
    }

    /**
     * Central resolver used by write controllers: if $requested is provided
     * but the user cannot access it, throw instead of silently falling back
     * to the personal library. Without this, a write with a denied explicit
     * libraryId would resolve to personal, pass the canEdit check, and write
     * to the wrong library.
     *
     * When $requested is null (no libraryId in the request), falls back to
     * personal — the caller had no specific intent.
     *
     * @throws \InvalidArgumentException if $requested is set and access is denied.
     */
    public function resolveLibraryId(?int $requested, string $userId): int {
        if ($requested === null) {
            return $this->getPersonalLibraryId($userId);
        }
        if (!$this->canAccess($requested, $userId)) {
            throw new \InvalidArgumentException('Library not found or access denied.');
        }
        return $requested;
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function create(string $owner, string $name): Library {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        $library = new Library();
        $library->setOwner($owner);
        $library->setName($name);
        $library->setIsPersonal(false);
        $library->setCreatedAt($now);

        return $this->libraryMapper->insert($library);
    }

    /**
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException
     */
    public function rename(int $id, string $owner, string $name): Library {
        $library = $this->libraryMapper->find($id);

        if ($library->getOwner() !== $owner) {
            throw new \InvalidArgumentException('Only the owner can rename a library.');
        }
        if ($library->getIsPersonal()) {
            throw new \InvalidArgumentException('The personal library cannot be renamed.');
        }

        $library->setName($name);
        $library->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->libraryMapper->update($library);
    }

    /**
     * Delete a named (non-personal) library, cascade-deleting all catalog rows
     * (episodes, watches, watchlist, series, movies) and member rows before
     * removing the library row itself.
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException
     */
    public function delete(int $id, string $owner): void {
        $library = $this->libraryMapper->find($id);

        if ($library->getOwner() !== $owner) {
            throw new \InvalidArgumentException('Only the owner can delete a library.');
        }
        if ($library->getIsPersonal()) {
            throw new \InvalidArgumentException('The personal library cannot be deleted.');
        }

        // Cascade: delete all catalog rows that reference this library.
        // Order: episodes (via their parent series), then watches, watchlist,
        // series, movies — then member rows, then the library row itself.
        // Wrap in a transaction so a mid-flight failure leaves no orphan rows.
        $this->db->beginTransaction();
        try {
            $this->episodeMapper->deleteByLibrary($id);
            $this->watchMapper->deleteByLibrary($id);
            $this->watchlistMapper->deleteByLibrary($id);
            $this->seriesMapper->deleteByLibrary($id);
            $this->movieMapper->deleteByLibrary($id);
            $this->memberMapper->deleteByLibrary($id);
            $this->libraryMapper->delete($library);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Annotate a single library entity with the caller's effective role and
     * edit permission, returning the JSON-serializable array shape used by
     * every library-returning endpoint. Centralizing this keeps create/rename/
     * list responses identical so the frontend never has to re-derive role.
     *
     * @return array{id: int, owner: string, name: string, isPersonal: bool,
     *               createdAt: string, updatedAt: string|null,
     *               role: string, permissionEdit: bool}
     */
    public function annotate(Library $lib, string $userId): array {
        if ($lib->getOwner() === $userId) {
            $role           = 'owner';
            $permissionEdit = true;
        } else {
            $membership     = $this->memberMapper->findMembership($lib->getId(), $userId);
            $permissionEdit = $membership !== null && $membership->getPermissionEdit();
            $role           = $permissionEdit ? 'editor' : 'viewer';
        }

        $entry                   = $lib->jsonSerialize();
        $entry['role']           = $role;
        $entry['permissionEdit'] = $permissionEdit;

        return $entry;
    }

    /**
     * List all libraries accessible to a user, each annotated with the
     * caller's effective role.
     *
     * @return array{id: int, owner: string, name: string, isPersonal: bool,
     *               createdAt: string, updatedAt: string|null,
     *               role: string, permissionEdit: bool}[]
     */
    public function listAccessible(string $userId): array {
        // Ensure the personal library exists before listing. App.vue awaits
        // this call before firing the other (parallel) data fetches, so
        // seeding here means those later calls all find the same personal
        // library instead of each racing to create one.
        $this->getPersonalLibraryId($userId);

        return array_map(
            fn (Library $lib): array => $this->annotate($lib, $userId),
            $this->libraryMapper->findAccessible($userId)
        );
    }

    // ── Member operations ─────────────────────────────────────────────────────

    /**
     * Add (or update) a member on a library. Allowed for the owner or any
     * member with edit permission. Cannot add members to a personal library.
     *
     * @param string $actingUserId The user performing the action.
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException
     */
    public function addMember(
        int $libraryId,
        string $actingUserId,
        string $memberUserId,
        bool $canEdit
    ): LibraryMember {
        $library = $this->libraryMapper->find($libraryId);

        if ($library->getOwner() !== $actingUserId) {
            throw new \InvalidArgumentException('Only the library owner can manage members.');
        }
        if ($library->getIsPersonal()) {
            throw new \InvalidArgumentException('Members cannot be added to a personal library.');
        }
        if ($memberUserId === $library->getOwner()) {
            throw new \InvalidArgumentException('The library owner is already a member.');
        }

        $now        = (new DateTime())->format('Y-m-d H:i:s');
        $membership = $this->memberMapper->findMembership($libraryId, $memberUserId);

        if ($membership !== null) {
            // Upsert: update permission on the existing row.
            $membership->setPermissionEdit($canEdit);
            return $this->memberMapper->update($membership);
        }

        $member = new LibraryMember();
        $member->setLibraryId($libraryId);
        $member->setUserId($memberUserId);
        $member->setPermissionEdit($canEdit);
        $member->setCreatedAt($now);

        return $this->memberMapper->insert($member);
    }

    /**
     * Remove a member from a library. Allowed for the owner or any member with
     * edit permission. The owner cannot be removed.
     *
     * @param string $actingUserId The user performing the action.
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException
     */
    public function removeMember(int $libraryId, string $actingUserId, string $memberUserId): void {
        $library = $this->libraryMapper->find($libraryId);

        if ($actingUserId !== $memberUserId && $library->getOwner() !== $actingUserId) {
            throw new \InvalidArgumentException('Only the library owner can remove other members.');
        }
        if ($memberUserId === $library->getOwner()) {
            throw new \InvalidArgumentException('The library owner cannot be removed.');
        }

        $membership = $this->memberMapper->findMembership($libraryId, $memberUserId);
        if ($membership === null) {
            // Not a member — silently succeed (idempotent remove).
            return;
        }

        $this->memberMapper->delete($membership);
    }

    /**
     * Annotate a member entity with its resolved displayName, role, and
     * isOwner flag — the JSON shape the frontend member list expects. Shared
     * by addMember's response and listMembers so both stay identical. A
     * LibraryMember row is never the owner (the owner has no member row).
     *
     * @return array{userId: string, permissionEdit: bool, displayName: string,
     *               role: string, isOwner: bool}
     */
    public function annotateMember(LibraryMember $member): array {
        $entry = $member->jsonSerialize();
        $user  = $this->userManager->get($member->getUserId());
        $entry['displayName'] = $user !== null ? $user->getDisplayName() : $member->getUserId();
        $entry['role']        = $member->getPermissionEdit() ? 'editor' : 'viewer';
        $entry['isOwner']     = false;

        return $entry;
    }

    /**
     * List members of a library, with the owner prepended as the first entry.
     * Accessible to any user with access to it. Each entry carries a resolved
     * displayName, a role ('owner'|'editor'|'viewer'), and an isOwner flag.
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException
     */
    public function listMembers(int $libraryId, string $userId): array {
        if (!$this->canAccess($libraryId, $userId)) {
            throw new \InvalidArgumentException('Access denied.');
        }

        $library = $this->libraryMapper->find($libraryId);
        $ownerId = $library->getOwner();

        // Owner row first, synthesized (there is no member row for the owner).
        $ownerUser = $this->userManager->get($ownerId);
        $result = [[
            'userId'         => $ownerId,
            'displayName'    => $ownerUser !== null ? $ownerUser->getDisplayName() : $ownerId,
            'permissionEdit' => true,
            'role'           => 'owner',
            'isOwner'        => true,
        ]];

        foreach ($this->memberMapper->findByLibrary($libraryId) as $m) {
            $result[] = $this->annotateMember($m);
        }

        return $result;
    }
}
