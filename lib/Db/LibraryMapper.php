<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Library>
 */
class LibraryMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_libraries', Library::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Library {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return Library[]
     */
    public function findByOwner(string $owner): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return the single personal library for a user, or null if none exists.
     *
     * Orders by id ASC so that if duplicates ever exist (e.g. from a
     * pre-fix check-then-insert race) every caller deterministically
     * converges on the same lowest-id row.
     */
    public function findPersonal(string $userId): ?Library {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_personal', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Return all libraries accessible to a user: owned libraries UNION
     * libraries where the user is an explicit member.
     *
     * Deduplication by id is done in PHP because cross-DB UNION support in the
     * OCP QueryBuilder is limited.
     *
     * @return Library[]
     */
    public function findAccessible(string $userId): array {
        // Libraries owned by this user.
        $ownedLibraries = $this->findByOwner($userId);

        // Libraries where this user is a member.
        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*')
            ->from($this->getTableName(), 'l')
            ->innerJoin(
                'l',
                'moviedb_library_members',
                'm',
                $qb->expr()->eq('l.id', 'm.library_id')
            )
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('l.name', 'ASC');

        $memberLibraries = $this->findEntities($qb);

        // Dedupe by id — owned libraries take precedence.
        $seen = [];
        $result = [];

        foreach ($ownedLibraries as $lib) {
            $seen[$lib->getId()] = true;
            $result[] = $lib;
        }
        foreach ($memberLibraries as $lib) {
            if (!isset($seen[$lib->getId()])) {
                $seen[$lib->getId()] = true;
                $result[] = $lib;
            }
        }

        return $result;
    }
}
