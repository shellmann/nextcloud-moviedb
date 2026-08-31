<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WatchlistItem>
 */
class WatchlistMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_watchlist', WatchlistItem::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, ?int $libraryId = null): WatchlistItem {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($libraryId !== null) {
            $qb->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntity($qb);
    }

    /**
     * @return WatchlistItem[]
     */
    public function findAll(int $libraryId, array $filters = []): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
        }

        if (!empty($filters['mediaType'])) {
            $qb->andWhere($qb->expr()->eq('media_type',
                $qb->createNamedParameter($filters['mediaType'])));
        }

        // Sorting
        $sortField = $filters['sort'] ?? 'priority';
        $sortDir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $allowedSortFields = ['priority', 'title', 'added_at', 'release_date'];
        if (in_array($sortField, $allowedSortFields)) {
            $qb->orderBy($sortField, $sortDir);
        } else {
            $qb->orderBy('priority', 'DESC');
        }

        return $this->findEntities($qb);
    }

    public function countAll(int $libraryId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    public function findByTmdbId(int $libraryId, int $tmdbId, ?string $mediaType = null): ?WatchlistItem {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)));

        // A movie and a TV show can share a TMDB id; disambiguate when a type is given.
        if ($mediaType !== null) {
            $qb->andWhere($qb->expr()->eq('media_type', $qb->createNamedParameter($mediaType)));
        }

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Delete all watchlist rows belonging to a library (used on library cascade delete).
     */
    public function deleteByLibrary(int $libraryId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
