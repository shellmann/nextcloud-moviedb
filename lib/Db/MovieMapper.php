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
    public function find(int $id, ?string $userId = null): Movie {
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

        // JOIN the latest watch per movie so we can sort by watched_at / watch rating
        $prefix = $this->db->getDatabasePlatform()->getTableQuoteCharacter();
        $watchTable = $this->db->getTablePrefix() . 'moviedb_movie_watches';

        $qb->select('m.*')
            ->addSelect('lw.watched_at AS last_watched_at')
            ->addSelect('lw.rating AS last_rating')
            ->from($this->getTableName(), 'm')
            ->leftJoin(
                'm',
                $qb->createFunction(
                    "(SELECT movie_id, MAX(watched_at) AS watched_at, MAX(rating) AS rating
                      FROM `{$watchTable}`
                      WHERE user_id = " . $qb->createNamedParameter($userId) . "
                      GROUP BY movie_id)"
                ),
                'lw',
                $qb->expr()->eq('m.id', 'lw.movie_id')
            )
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)));

        $this->applyFilters($qb, $filters);

        $sortField = $filters['sort'] ?? 'date_watched';
        $sortDir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $allowedSortFields = ['date_watched', 'title', 'rating', 'release_year', 'created_at'];

        if ($sortField === 'date_watched') {
            $qb->orderBy('lw.watched_at', $sortDir);
        } elseif ($sortField === 'rating') {
            $qb->orderBy('lw.rating', $sortDir);
        } elseif (in_array($sortField, $allowedSortFields)) {
            $qb->orderBy('m.' . $sortField, $sortDir);
        } else {
            $qb->orderBy('lw.watched_at', 'DESC');
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

        $this->applyFilters($qb, $filters);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Apply shared filter logic to a query builder.
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void {
        if (!empty($filters['genre'])) {
            $genreId = (int)$filters['genre'];
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('m.genre_ids', $qb->createNamedParameter('[' . $genreId . ']')),
                    $qb->expr()->like('m.genre_ids', $qb->createNamedParameter('[' . $genreId . ',%')),
                    $qb->expr()->like('m.genre_ids', $qb->createNamedParameter('%,' . $genreId . ',%')),
                    $qb->expr()->like('m.genre_ids', $qb->createNamedParameter('%,' . $genreId . ']'))
                )
            );
        }
        if (!empty($filters['year'])) {
            $qb->andWhere($qb->expr()->eq('m.release_year',
                $qb->createNamedParameter((int)$filters['year'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['platform'])) {
            // Filter by platform across any watch of this movie
            $watchTable = $this->db->getTablePrefix() . 'moviedb_movie_watches';
            $userId = null; // already filtered by m.user_id
            $qb->andWhere(
                $qb->expr()->in(
                    'm.id',
                    $qb->createFunction(
                        "SELECT DISTINCT movie_id FROM `{$watchTable}` WHERE platform_id = " .
                        $qb->createNamedParameter((int)$filters['platform'], IQueryBuilder::PARAM_INT)
                    )
                )
            );
        }
        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('m.title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
        }
        if (isset($filters['favorite']) && $filters['favorite']) {
            $qb->andWhere($qb->expr()->eq('m.is_favorite', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
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
}

