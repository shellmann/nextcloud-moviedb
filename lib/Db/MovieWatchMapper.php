<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MovieWatch>
 */
class MovieWatchMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_movie_watches', MovieWatch::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, ?string $userId = null): MovieWatch {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return MovieWatch[]
     */
    public function findByMovie(int $movieId, string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('movie_id', $qb->createNamedParameter($movieId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('watched_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Returns the most recent watch row per movie_id for the user.
     * Used by MovieMapper to sort the movie list by latest watch date.
     *
     * @return array<int, array{movie_id: int, watched_at: string|null, rating: int|null}>
     */
    public function findLatestPerMovie(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('movie_id')
            ->selectAlias($qb->createFunction('MAX(watched_at)'), 'watched_at')
            ->selectAlias($qb->createFunction('MAX(rating)'), 'rating')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('movie_id'))
            ->groupBy('movie_id');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(int)$row['movie_id']] = [
                'watched_at' => $row['watched_at'],
                'rating'     => $row['rating'] !== null ? (int)$row['rating'] : null,
            ];
        }
        $result->closeCursor();

        return $data;
    }

    /**
     * Total runtime across all watches for a user (joins to movies for runtime).
     * Counts each rewatch separately (runtime × number of watches).
     */
    public function getTotalRuntime(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('m.runtime'), 'total')
            ->from($this->getTableName(), 'w')
            ->innerJoin('w', 'moviedb_movies', 'm', $qb->expr()->eq('w.movie_id', 'm.id'))
            ->where($qb->expr()->eq('w.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('m.runtime'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }

    public function getAverageRating(string $userId): float {
        $qb = $this->db->getQueryBuilder();

        // NC's IFunctionBuilder has no avg(); a plain unquoted AVG() is portable
        // across SQLite/MySQL/Postgres (same approach as the MAX() calls above).
        $qb->selectAlias($qb->createFunction('AVG(rating)'), 'average')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('rating'))
            ->andWhere($qb->expr()->isNotNull('movie_id'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return round((float)($row['average'] ?? 0), 1);
    }

    /**
     * @return array<int, int>  platform_id => count of watches
     */
    public function getCountByPlatform(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('platform_id')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('platform_id'))
            ->andWhere($qb->expr()->isNotNull('movie_id'))
            ->groupBy('platform_id');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(int)$row['platform_id']] = (int)$row['count'];
        }
        $result->closeCursor();

        return $data;
    }

    /**
     * Watch count per release year (joins to movies for release_year).
     *
     * @return array<string, int>
     */
    public function getCountByYear(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.release_year')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName(), 'w')
            ->innerJoin('w', 'moviedb_movies', 'm', $qb->expr()->eq('w.movie_id', 'm.id'))
            ->where($qb->expr()->eq('w.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('m.release_year'))
            ->groupBy('m.release_year')
            ->orderBy('m.release_year', 'DESC');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(string)$row['release_year']] = (int)$row['count'];
        }
        $result->closeCursor();

        return $data;
    }

    // ─── Episode watches (v1.3.0) ───────────────────────────────────────────

    /**
     * @return MovieWatch[]
     */
    public function findByEpisode(int $episodeId, string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('episode_id', $qb->createNamedParameter($episodeId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('watched_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Distinct episode IDs the user has watched for a series.
     *
     * @return int[]
     */
    public function findWatchedEpisodeIds(int $seriesId, string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('episode_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('episode_id'));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['episode_id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Watch counts per episode for a series: episode_id => number of watches.
     *
     * @return array<int, int>
     */
    public function countWatchesPerEpisode(int $seriesId, string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('episode_id')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('episode_id'))
            ->groupBy('episode_id');

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[(int)$row['episode_id']] = (int)$row['count'];
        }
        $result->closeCursor();

        return $data;
    }

    /**
     * Total distinct watched episodes across all series for a user.
     */
    public function countWatchedEpisodes(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->createFunction('COUNT(DISTINCT episode_id)'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('episode_id'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Total episode runtime across all watches for a user (joins to episodes for
     * runtime). Counts each rewatch separately, mirroring getTotalRuntime.
     */
    public function getTotalEpisodeRuntime(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('e.runtime'), 'total')
            ->from($this->getTableName(), 'w')
            ->innerJoin('w', 'moviedb_episodes', 'e', $qb->expr()->eq('w.episode_id', 'e.id'))
            ->where($qb->expr()->eq('w.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('e.runtime'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }

    /**
     * Delete all watch rows for a series (used on cascade delete). Ownership is
     * verified by the caller before invoking this.
     */
    public function deleteBySeries(int $seriesId, string $userId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }
}
