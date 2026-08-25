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

    /**
     * Explicit list of columns that back a Movie entity property.
     *
     * We deliberately do NOT `SELECT *` here: v1.2.0 retains the legacy watch
     * columns (date_watched/rating/review/platform_id/language_watched) in the
     * table — they could not be dropped without breaking SQLite fresh installs
     * (see Version000002 comment) — and also adds `media_type`, which has no
     * entity property yet. QBMapper hydration (Entity::fromRow) calls a setter
     * for every selected column and throws BadFunctionCallException on any
     * column lacking a property, so we select only the entity-backed columns.
     */
    private const COLUMNS = [
        'id', 'user_id', 'tmdb_id', 'title', 'original_title', 'poster_path',
        'backdrop_path', 'overview', 'genre_ids', 'release_date', 'release_year',
        'runtime', 'cast_data', 'director', 'is_favorite', 'created_at', 'updated_at',
    ];

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_movies', Movie::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, ?string $userId = null): Movie {
        $qb = $this->db->getQueryBuilder();

        $qb->select(...self::COLUMNS)
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

        // Bind user_id once on the OUTER builder; the placeholder is reused both
        // in the aggregate subquery text and in the outer WHERE. Parameters live
        // on the executing builder, and createFunction() carries none of its own,
        // so a subquery built on a throwaway builder must reference an outer param.
        $userParam = $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR, ':lw_user_id');

        // Latest-watch-per-movie aggregate. Built on a nested builder only to emit
        // correctly *PREFIX*-ed, per-platform-quoted SQL (no getTablePrefix(), no
        // hand-written backticks — both break on non-MySQL). It references the
        // outer :lw_user_id placeholder literally.
        $sub = $this->db->getQueryBuilder();
        $sub->select('w.movie_id')
            ->selectAlias($sub->func()->max('w.watched_at'), 'watched_at')
            ->selectAlias($sub->func()->max('w.rating'), 'rating')
            ->from('moviedb_movie_watches', 'w')
            ->where('w.user_id = :lw_user_id')
            ->groupBy('w.movie_id');

        $qb->select(array_map(static fn (string $c): string => 'm.' . $c, self::COLUMNS))
            ->addSelect('lw.watched_at AS last_watched_at')
            ->addSelect('lw.rating AS last_rating')
            ->from($this->getTableName(), 'm')
            ->leftJoin(
                'm',
                $qb->createFunction('(' . $sub->getSQL() . ')'),
                'lw',
                $qb->expr()->eq('m.id', 'lw.movie_id')
            )
            ->where($qb->expr()->eq('m.user_id', $userParam));

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
            ->from($this->getTableName(), 'm')
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)));

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
            // genre_ids is a JSON column; Postgres' json type has no LIKE operator,
            // so cast to text before matching. (SQLite/MySQL store JSON as text and
            // would tolerate a bare LIKE, but the cast is portable across all three.)
            $genreCol = $qb->expr()->castColumn('m.genre_ids', IQueryBuilder::PARAM_STR);
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like($genreCol, $qb->createNamedParameter('[' . $genreId . ']')),
                    $qb->expr()->like($genreCol, $qb->createNamedParameter('[' . $genreId . ',%')),
                    $qb->expr()->like($genreCol, $qb->createNamedParameter('%,' . $genreId . ',%')),
                    $qb->expr()->like($genreCol, $qb->createNamedParameter('%,' . $genreId . ']'))
                )
            );
        }
        if (!empty($filters['year'])) {
            $qb->andWhere($qb->expr()->eq('m.release_year',
                $qb->createNamedParameter((int)$filters['year'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['platform'])) {
            // Filter to movies that have at least one watch on this platform.
            // Nested builder emits *PREFIX*-ed, correctly-quoted SQL; the platform
            // id is bound on the outer builder and referenced by placeholder name.
            // (createNamedParameter is called for its binding side effect — the
            // returned ':flt_platform' placeholder is what the subquery text uses.)
            $qb->createNamedParameter((int)$filters['platform'], IQueryBuilder::PARAM_INT, ':flt_platform');
            $sub = $this->db->getQueryBuilder();
            $sub->selectDistinct('pw.movie_id')
                ->from('moviedb_movie_watches', 'pw')
                ->where('pw.platform_id = :flt_platform');
            $qb->andWhere(
                $qb->expr()->in('m.id', $qb->createFunction('(' . $sub->getSQL() . ')'))
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

        $qb->select(...self::COLUMNS)
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }
}

