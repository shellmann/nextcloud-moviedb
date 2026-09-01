<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Series>
 */
class SeriesMapper extends QBMapper {

    /**
     * Explicit column allow-list (same reasoning as MovieMapper): the latest-watch
     * aggregate adds aliased columns (last_watched_at/last_rating) that back entity
     * properties but are not real table columns, so we never SELECT *.
     */
    private const COLUMNS = [
        'id', 'user_id', 'library_id', 'tmdb_id', 'title', 'original_title', 'poster_path',
        'backdrop_path', 'overview', 'genre_ids', 'first_air_date', 'first_air_year',
        'number_of_seasons', 'number_of_episodes', 'status', 'cast_data', 'director',
        'is_favorite', 'created_at', 'updated_at',
    ];

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_series', Series::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, ?int $libraryId = null): Series {
        $qb = $this->db->getQueryBuilder();

        $qb->select(...self::COLUMNS)
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($libraryId !== null) {
            $qb->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntity($qb);
    }

    /**
     * @return Series[]
     */
    public function findAll(int $libraryId, array $filters = [], int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        // Bind library_id once on the OUTER builder; the placeholder is reused both in
        // the aggregate subquery text and in the outer WHERE. Mirrors MovieMapper.
        $libraryParam = $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT, ':lw_series_library_id');

        // Latest-watch-per-series aggregate over episode watches. Episode rows carry
        // a denormalized series_id, so no join to episodes is needed here.
        $sub = $this->db->getQueryBuilder();
        $sub->select('w.series_id')
            ->selectAlias($sub->func()->max('w.watched_at'), 'watched_at')
            ->selectAlias($sub->func()->max('w.rating'), 'rating')
            ->from('moviedb_movie_watches', 'w')
            ->where('w.library_id = :lw_series_library_id')
            ->andWhere($sub->expr()->isNotNull('w.series_id'))
            ->groupBy('w.series_id');

        $qb->select(array_map(static fn (string $c): string => 'm.' . $c, self::COLUMNS))
            ->addSelect('lw.watched_at AS last_watched_at')
            ->addSelect('lw.rating AS last_rating')
            ->from($this->getTableName(), 'm')
            ->leftJoin(
                'm',
                $qb->createFunction('(' . $sub->getSQL() . ')'),
                'lw',
                $qb->expr()->eq('m.id', 'lw.series_id')
            )
            ->where($qb->expr()->eq('m.library_id', $libraryParam));

        $this->applyFilters($qb, $filters);

        $sortField = $filters['sort'] ?? 'date_watched';
        $sortDir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $allowedSortFields = ['date_watched', 'title', 'rating', 'first_air_year', 'created_at'];

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

    public function countAll(int $libraryId, array $filters = []): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName(), 'm')
            ->where($qb->expr()->eq('m.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $this->applyFilters($qb, $filters);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    private function applyFilters(IQueryBuilder $qb, array $filters): void {
        if (!empty($filters['genre'])) {
            $genreId = (int)$filters['genre'];
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
            $qb->andWhere($qb->expr()->eq('m.first_air_year',
                $qb->createNamedParameter((int)$filters['year'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['search'])) {
            $qb->andWhere($qb->expr()->iLike('m.title',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filters['search']) . '%')));
        }
        if (isset($filters['favorite']) && $filters['favorite']) {
            $qb->andWhere($qb->expr()->eq('m.is_favorite', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
    }

    public function findByTmdbId(int $libraryId, int $tmdbId): ?Series {
        $qb = $this->db->getQueryBuilder();

        $qb->select(...self::COLUMNS)
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Delete all series rows belonging to a library (used on library cascade delete).
     */
    public function deleteByLibrary(int $libraryId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
