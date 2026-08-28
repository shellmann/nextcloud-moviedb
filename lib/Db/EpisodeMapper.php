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
}
