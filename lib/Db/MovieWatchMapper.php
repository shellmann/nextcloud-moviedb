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
    public function find(int $id, ?int $libraryId = null): MovieWatch {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($libraryId !== null) {
            $qb->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntity($qb);
    }

    /**
     * @return MovieWatch[]
     */
    public function findByMovie(int $movieId, int $libraryId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('movie_id', $qb->createNamedParameter($movieId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
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
     * Total runtime across all watches for a library (joins to movies for runtime).
     * Counts each rewatch separately (runtime × number of watches).
     */
    public function getTotalRuntime(int $libraryId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('m.runtime'), 'total')
            ->from($this->getTableName(), 'w')
            ->innerJoin('w', 'moviedb_movies', 'm', $qb->expr()->eq('w.movie_id', 'm.id'))
            ->where($qb->expr()->eq('w.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('m.runtime'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }

    public function getAverageRating(int $libraryId): float {
        $qb = $this->db->getQueryBuilder();

        // NC's IFunctionBuilder has no avg(); a plain unquoted AVG() is portable
        // across SQLite/MySQL/Postgres (same approach as the MAX() calls above).
        // Averages every rated watch row: movie watches AND the single series-level
        // watch row per TV show (series_id set, movie_id/episode_id NULL). Episodes
        // carry no rating (their watched-state is a boolean on moviedb_episodes), so
        // there are no per-episode rows to skew this.
        $qb->selectAlias($qb->createFunction('AVG(rating)'), 'average')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('rating'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return round((float)($row['average'] ?? 0), 1);
    }

    /**
     * @return array<int, int>  platform_id => count of watches
     */
    public function getCountByPlatform(int $libraryId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('platform_id')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
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
    public function getCountByYear(int $libraryId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.release_year')
            ->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName(), 'w')
            ->innerJoin('w', 'moviedb_movies', 'm', $qb->expr()->eq('w.movie_id', 'm.id'))
            ->where($qb->expr()->eq('w.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
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

    // ─── Series-level watch metadata (v1.3.0) ───────────────────────────────

    /**
     * Read the single series-level watch row (the TV show's own rating, platform,
     * language, and watch date). By convention a series carries at most one such
     * row: series_id set, episode_id NULL. Returns nulls when none exists.
     *
     * @return array{watchedAt: ?string, rating: ?int, platformId: ?int, languageWatched: ?string, review: ?string}
     */
    public function getSeriesWatchSummary(int $seriesId, int $libraryId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('watched_at', 'rating', 'platform_id', 'language_watched', 'review')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('episode_id'))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return [
                'watchedAt' => null,
                'rating' => null,
                'platformId' => null,
                'languageWatched' => null,
                'review' => null,
            ];
        }

        return [
            'watchedAt' => $row['watched_at'] ?? null,
            'rating' => $row['rating'] !== null ? (int)$row['rating'] : null,
            'platformId' => $row['platform_id'] !== null ? (int)$row['platform_id'] : null,
            'languageWatched' => $row['language_watched'] ?? null,
            'review' => $row['review'] ?? null,
        ];
    }

    /**
     * Find the single series-level watch row, or null if the series has none.
     */
    public function findSeriesWatch(int $seriesId, int $libraryId): ?MovieWatch {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('episode_id'))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Delete all watch rows for a series (used on cascade delete). Ownership is
     * verified by the caller before invoking this.
     */
    public function deleteBySeries(int $seriesId, int $libraryId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Delete all watch rows belonging to a library (used on library cascade delete).
     */
    public function deleteByLibrary(int $libraryId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
