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
    public function find(int $id, ?string $userId = null): WatchlistItem {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return WatchlistItem[]
     */
    public function findAll(string $userId, array $filters = []): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
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

    public function countAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    public function findByTmdbId(string $userId, int $tmdbId): ?WatchlistItem {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }
}
