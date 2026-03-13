<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Movie>
 */
class MovieMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_movies', Movie::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Movie {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return Movie[]
     */
    public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        // Apply filters
        if (!empty($filters['genre'])) {
            $qb->andWhere($qb->expr()->like('genre_ids',
                $qb->createNamedParameter('%' . $filters['genre'] . '%')));
        }
        if (!empty($filters['year'])) {
            $qb->andWhere($qb->expr()->eq('release_year',
                $qb->createNamedParameter((int)$filters['year'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['platform'])) {
            $qb->andWhere($qb->expr()->eq('platform_id',
                $qb->createNamedParameter((int)$filters['platform'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
        }
        if (isset($filters['favorite']) && $filters['favorite']) {
            $qb->andWhere($qb->expr()->eq('is_favorite', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        // Sorting
        $sortField = $filters['sort'] ?? 'date_watched';
        $sortDir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $allowedSortFields = ['date_watched', 'title', 'rating', 'release_year', 'created_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $qb->orderBy($sortField, $sortDir);
        } else {
            $qb->orderBy('date_watched', 'DESC');
        }

        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    public function countAll(string $userId, array $filters = []): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        // Apply same filters as findAll
        if (!empty($filters['genre'])) {
            $qb->andWhere($qb->expr()->like('genre_ids',
                $qb->createNamedParameter('%' . $filters['genre'] . '%')));
        }
        if (!empty($filters['year'])) {
            $qb->andWhere($qb->expr()->eq('release_year',
                $qb->createNamedParameter((int)$filters['year'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['platform'])) {
            $qb->andWhere($qb->expr()->eq('platform_id',
                $qb->createNamedParameter((int)$filters['platform'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    public function findByTmdbId(string $userId, int $tmdbId): ?Movie {
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

    public function getTotalRuntime(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->createFunction('SUM(`runtime`)'), 'total')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }

    public function getAverageRating(string $userId): float {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->createFunction('AVG(`rating`)'), 'average')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('rating'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return round((float)($row['average'] ?? 0), 1);
    }

    /**
     * @return array<string, int>
     */
    public function getCountByYear(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('release_year')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('release_year'))
            ->groupBy('release_year')
            ->orderBy('release_year', 'DESC');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(string)$row['release_year']] = (int)$row['count'];
        }
        $result->closeCursor();

        return $data;
    }

    /**
     * @return array<int, int>
     */
    public function getCountByPlatform(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('platform_id')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('platform_id'))
            ->groupBy('platform_id');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(int)$row['platform_id']] = (int)$row['count'];
        }
        $result->closeCursor();

        return $data;
    }
}
