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
        'is_favorite', 'stopped_watching', 'tmdb_refreshed_at', 'created_at', 'updated_at',
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

        // Language of the show's own watch row (episode_id NULL), the same row
        // MovieWatchMapper::getSeriesWatchSummary reads, for the flag on the card.
        $languageSub = $this->db->getQueryBuilder();
        $languageSub->select('wl.language_watched')
            ->from('moviedb_movie_watches', 'wl')
            ->where('wl.series_id = m.id')
            ->andWhere('wl.library_id = :lw_series_library_id')
            ->andWhere($languageSub->expr()->isNull('wl.episode_id'))
            ->setMaxResults(1);

        $qb->select(array_map(static fn (string $c): string => 'm.' . $c, self::COLUMNS))
            ->addSelect('lw.watched_at AS last_watched_at')
            ->addSelect('lw.rating AS last_rating')
            ->selectAlias($qb->createFunction('(' . $languageSub->getSQL() . ')'), 'last_language')
            ->from($this->getTableName(), 'm')
            ->leftJoin(
                'm',
                $qb->createFunction('(' . $sub->getSQL() . ')'),
                'lw',
                $qb->expr()->eq('m.id', 'lw.series_id')
            )
            ->where($qb->expr()->eq('m.library_id', $libraryParam));

        $this->applyFilters($qb, $libraryId, $filters);

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

        $this->applyFilters($qb, $libraryId, $filters);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    private function applyFilters(IQueryBuilder $qb, int $libraryId, array $filters): void {
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
        if (!empty($filters['watchStatus'])) {
            $this->applyWatchStatus($qb, (string)$filters['watchStatus'], $libraryId, $filters['today'] ?? (new \DateTime())->format('Y-m-d'));
        }
        if (isset($filters['favorite']) && $filters['favorite']) {
            $qb->andWhere($qb->expr()->eq('m.is_favorite', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
    }

    /**
     * Filter by watch status in SQL, so pagination and counts stay right for
     * libraries of any size. Joins the aired and the watched regular-episode
     * counts per series (same rule as SeriesProgressService::watchStatus and
     * SeriesService::findWithProgress: aired = air_date <= today, season 0
     * excluded) and compares them:
     *   stopped     — stopped_watching set
     *   finished    — not stopped, aired > 0 and watched >= aired
     *   in_progress — not stopped, nothing aired yet or watched < aired
     * Unknown values match nothing; the controller rejects them before.
     */
    private function applyWatchStatus(IQueryBuilder $qb, string $status, int $libraryId, string $today): void {
        $stopped = $qb->expr()->eq('m.stopped_watching', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL));
        $notStopped = $qb->expr()->orX(
            $qb->expr()->eq('m.stopped_watching', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
            $qb->expr()->isNull('m.stopped_watching')
        );

        if ($status === 'stopped') {
            $qb->andWhere($stopped);
            return;
        }

        // Both subqueries share these placeholders, bound once on the outer builder.
        $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT, ':ws_library_id');
        $qb->createNamedParameter($today, IQueryBuilder::PARAM_STR, ':ws_today');
        $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL, ':ws_watched');
        $qb->leftJoin('m', $qb->createFunction('(' . $this->episodeCountSql(false) . ')'), 'ea', $qb->expr()->eq('m.id', 'ea.series_id'));
        $qb->leftJoin('m', $qb->createFunction('(' . $this->episodeCountSql(true) . ')'), 'ew', $qb->expr()->eq('m.id', 'ew.series_id'));
        $watched = 'COALESCE(ew.episode_count, 0)';

        if ($status === 'finished') {
            $qb->andWhere($notStopped)
                ->andWhere($qb->expr()->isNotNull('ea.episode_count'))
                ->andWhere($qb->createFunction($watched . ' >= ea.episode_count'));
        } elseif ($status === 'in_progress') {
            $qb->andWhere($notStopped)
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('ea.episode_count'),
                    $qb->createFunction($watched . ' < ea.episode_count')
                ));
        } else {
            $qb->andWhere('1 = 0');
        }
    }

    /**
     * Aired regular episodes per series of the library (only watched ones with
     * $watchedOnly). Rows only exist for series with at least one, hence the
     * LEFT JOINs. Scoped to the library like the latest-watch subquery, so it
     * never aggregates other users' episodes.
     */
    private function episodeCountSql(bool $watchedOnly): string {
        $sub = $this->db->getQueryBuilder();
        $sub->select('e.series_id')
            ->selectAlias($sub->func()->count('*'), 'episode_count')
            ->from('moviedb_episodes', 'e')
            ->innerJoin('e', 'moviedb_series', 'es', $sub->expr()->eq('e.series_id', 'es.id'))
            ->where('es.library_id = :ws_library_id')
            ->andWhere($sub->expr()->isNotNull('e.air_date'))
            ->andWhere('e.air_date <= :ws_today')
            ->andWhere('e.season_number > 0')
            ->groupBy('e.series_id');
        if ($watchedOnly) {
            $sub->andWhere('e.watched = :ws_watched');
        }
        return $sub->getSQL();
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
     * All series of a library, unpaginated (used by export and import dedup).
     *
     * @return Series[]
     */
    public function findAllByLibrary(int $libraryId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select(...self::COLUMNS)
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
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

    /**
     * Shows whose episodes are due for a TMDB refresh, across all libraries.
     * Never refreshed shows come first, then the longest waiting ones.
     * Ongoing shows are due once $ongoingBefore has passed, ended or canceled
     * ones once $endedBefore has passed (both "Y-m-d H:i:s" cut-offs).
     *
     * @return Series[]
     */
    public function findDueForRefresh(int $limit, string $ongoingBefore, string $endedBefore): array {
        $qb = $this->db->getQueryBuilder();

        $endedStatuses = $qb->createNamedParameter(['Ended', 'Canceled'], IQueryBuilder::PARAM_STR_ARRAY);

        $qb->select(...self::COLUMNS)
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('tmdb_id'))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('tmdb_refreshed_at'),
                $qb->expr()->andX(
                    $qb->expr()->orX($qb->expr()->isNull('status'), $qb->expr()->notIn('status', $endedStatuses)),
                    $qb->expr()->lt('tmdb_refreshed_at', $qb->createNamedParameter($ongoingBefore))
                ),
                $qb->expr()->andX(
                    $qb->expr()->in('status', $endedStatuses),
                    $qb->expr()->lt('tmdb_refreshed_at', $qb->createNamedParameter($endedBefore))
                )
            ))
            // NULL first on every database (Postgres sorts NULLs last by default).
            ->orderBy($qb->createFunction('CASE WHEN tmdb_refreshed_at IS NULL THEN 0 ELSE 1 END'), 'ASC')
            ->addOrderBy('tmdb_refreshed_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}
