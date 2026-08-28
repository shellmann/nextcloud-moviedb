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

    public function findByTmdbId(int $tmdbId): ?Episode {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('tmdb_id', $qb->createNamedParameter($tmdbId, IQueryBuilder::PARAM_INT)))
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
            ->where($qb->expr()->in('id', $qb->createNamedParameter($episodeIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }

    /**
     * Total watched episodes across all of a user's series. Episodes have no
     * user_id, so join through moviedb_series.
     */
    public function countWatchedForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('e.watched', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Total runtime of all watched episodes across a user's series.
     */
    public function getWatchedRuntimeForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('e.runtime'), 'total')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'moviedb_series', 's', $qb->expr()->eq('e.series_id', 's.id'))
            ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('e.watched', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('e.runtime'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }
}
