<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Episode>
 */
class EpisodeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_episodes', Episode::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Episode {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return Episode[]
     */
    public function findBySeries(int $seriesId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->orderBy('season_number', 'ASC')
            ->addOrderBy('episode_number', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return Episode[]
     */
    public function findBySeriesAndSeason(int $seriesId, int $seasonNumber): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('season_number', $qb->createNamedParameter($seasonNumber, IQueryBuilder::PARAM_INT)))
            ->orderBy('episode_number', 'ASC');

        return $this->findEntities($qb);
    }

    public function findByTmdbIdAndSeries(int $tmdbId, int $seriesId): ?Episode {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Bulk-delete all episodes of a series. Ownership is verified by the caller
     * (SeriesService) before invoking this.
     */
    public function deleteBySeries(int $seriesId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Flip the watched flag on a set of episodes in one statement. Ownership is
     * verified by the caller before invoking this. Returns the number of rows
     * changed. A no-op (empty id list) returns 0 without touching the DB.
     *
     * @param int[] $episodeIds
     */
    public function setWatchedForEpisodes(array $episodeIds, bool $watched): int {
        if (empty($episodeIds)) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('watched', $qb->createNamedParameter($watched, IQueryBuilder::PARAM_BOOL))
            // Bulk marks count as activity for "continue watching", like single toggles.
            ->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
            ->where($qb->expr()->in('id', $qb->createNamedParameter($episodeIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }

    /**
     * Per-series episode counts for a whole library, in two grouped queries:
     * aired regular episodes, and how many of those are watched (plus the latest
     * change to a watched one, as "last activity"). Same rule as
     * SeriesService::findWithProgress: aired = air_date <= $today, season 0
     * (specials) excluded. Series without aired episodes are absent.
     *
     * Pass $seriesIds to count only those series (one page of a list); without
     * it the whole library is counted (the dashboard row ranks all shows).
     *
     * @param int[]|null $seriesIds
     * @return array<int, array{watched: int, aired: int, lastWatchedAt: ?string}>
     */
    public function progressByLibrary(int $libraryId, string $today, ?array $seriesIds = null): array {
        if ($seriesIds === []) {
            return [];
        }
        $progress = [];

        $aired = $this->progressQuery($libraryId, $today, false, $seriesIds);
        $result = $aired->executeQuery();
        while ($row = $result->fetch()) {
            $progress[(int)$row['series_id']] = ['watched' => 0, 'aired' => (int)$row['count'], 'lastWatchedAt' => null];
        }
        $result->closeCursor();

        $watched = $this->progressQuery($libraryId, $today, true, $seriesIds);
        $result = $watched->executeQuery();
        while ($row = $result->fetch()) {
            $id = (int)$row['series_id'];
            if (isset($progress[$id])) {
                $progress[$id]['watched'] = (int)$row['count'];
                $progress[$id]['lastWatchedAt'] = $row['last_watched_at'] ?? null;
            }
        }
        $result->closeCursor();

        return $progress;
    }

    /**
     * @param int[]|null $seriesIds
     */
    private function progressQuery(int $libraryId, string $today, bool $watchedOnly, ?array $seriesIds): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();

        $qb->select('e.series_id')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('e.air_date'))
            ->andWhere($qb->expr()->lte('e.air_date', $qb->createNamedParameter($today)))
            ->andWhere($qb->expr()->gt('e.season_number', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->groupBy('e.series_id');

        if ($seriesIds !== null) {
            $qb->andWhere($qb->expr()->in('e.series_id', $qb->createNamedParameter(array_values(array_map('intval', $seriesIds)), IQueryBuilder::PARAM_INT_ARRAY)));
        }
        if ($watchedOnly) {
            $qb->selectAlias($qb->func()->max('e.updated_at'), 'last_watched_at')
                ->andWhere($qb->expr()->eq('e.watched', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        return $qb;
    }

    /**
     * The episode to watch next: the first aired, unwatched, regular episode in
     * season/episode order (same rule as SeriesService::findWithProgress).
     */
    public function findNextUnwatched(int $seriesId, string $today): ?Episode {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('watched', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('air_date'))
            ->andWhere($qb->expr()->lte('air_date', $qb->createNamedParameter($today)))
            ->andWhere($qb->expr()->gt('season_number', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('season_number', 'ASC')
            ->addOrderBy('episode_number', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Regular episodes (season 0 excluded) airing after $today up to and
     * including $until, for all shows of a library, soonest first. The
     * dashboard "Coming up" row keeps the first one per show.
     *
     * @return Episode[]
     */
    public function upcomingByLibrary(int $libraryId, string $today, string $until): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('e.*')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('e.air_date'))
            ->andWhere($qb->expr()->gt('e.air_date', $qb->createNamedParameter($today)))
            ->andWhere($qb->expr()->lte('e.air_date', $qb->createNamedParameter($until)))
            ->andWhere($qb->expr()->gt('e.season_number', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('e.air_date', 'ASC')
            ->addOrderBy('e.series_id', 'ASC')
            ->addOrderBy('e.season_number', 'ASC')
            ->addOrderBy('e.episode_number', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Total watched episodes across all of a library's series. Episodes have no
     * library_id, so join through moviedb_series.
     */
    public function countWatchedForUser(int $libraryId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('e.watched', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Total runtime of all watched episodes across a library's series.
     */
    public function getWatchedRuntimeForUser(int $libraryId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('e.runtime'), 'total')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('e.watched', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('e.runtime'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }

    /**
     * All episodes of a library's series, unpaginated (used by export).
     *
     * @return Episode[]
     */
    public function findAllByLibrary(int $libraryId): array {
        $qb = $this->db->getQueryBuilder();

        $sub = $this->db->getQueryBuilder();
        $sub->select('id')
            ->from('moviedb_series')
            ->where('library_id = :ep_lib_id');

        $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT, ':ep_lib_id');

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('series_id', $qb->createFunction('(' . $sub->getSQL() . ')')))
            ->orderBy('series_id', 'ASC')
            ->addOrderBy('season_number', 'ASC')
            ->addOrderBy('episode_number', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Delete all episodes belonging to a library (via their parent series).
     * Used on library cascade delete — deletes episodes whose series_id is in
     * the set of series owned by that library.
     */
    public function deleteByLibrary(int $libraryId): void {
        $qb = $this->db->getQueryBuilder();

        // Subquery: SELECT id FROM moviedb_series WHERE library_id = :lib_id
        $sub = $this->db->getQueryBuilder();
        $sub->select('id')
            ->from('moviedb_series')
            ->where('library_id = :ep_lib_id');

        $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT, ':ep_lib_id');

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->in('series_id', $qb->createFunction('(' . $sub->getSQL() . ')'))
            );

        $qb->executeStatement();
    }
}
