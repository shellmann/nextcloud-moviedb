<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class SeriesService {
    private SeriesMapper $mapper;
    private EpisodeMapper $episodeMapper;
    private MovieWatchMapper $watchMapper;
    private PlatformMapper $platformMapper;
    private TmdbService $tmdbService;
    private IDBConnection $db;

    public function __construct(
        SeriesMapper $mapper,
        EpisodeMapper $episodeMapper,
        MovieWatchMapper $watchMapper,
        PlatformMapper $platformMapper,
        TmdbService $tmdbService,
        IDBConnection $db
    ) {
        $this->mapper = $mapper;
        $this->episodeMapper = $episodeMapper;
        $this->watchMapper = $watchMapper;
        $this->platformMapper = $platformMapper;
        $this->tmdbService = $tmdbService;
        $this->db = $db;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, int $libraryId): Series {
        return $this->mapper->find($id, $libraryId);
    }

    /**
     * @return Series[]
     */
    public function findAll(int $libraryId, array $filters = [], int $limit = 50, int $offset = 0): array {
        return $this->mapper->findAll($libraryId, $filters, $limit, $offset);
    }

    public function count(int $libraryId, array $filters = []): int {
        return $this->mapper->countAll($libraryId, $filters);
    }

    public function existsByTmdbId(int $libraryId, int $tmdbId): bool {
        return $this->mapper->findByTmdbId($libraryId, $tmdbId) !== null;
    }

    public function findByTmdbId(int $libraryId, int $tmdbId): ?Series {
        return $this->mapper->findByTmdbId($libraryId, $tmdbId);
    }

    /**
     * Create a series from TMDB data and eagerly fetch + persist all its episodes
     * (one TMDB call per season). Wrapped in a transaction so a failed episode
     * fetch does not leave a series with a partial episode set.
     *
     * @param array $data Series metadata (title required) + tmdbId to drive the
     *                    per-season episode fetch. $language selects TMDB locale.
     */
    public function createFromTmdb(string $userId, int $libraryId, array $data, string $language = 'en-US'): Series {
        // Pre-fetch all season payloads from TMDB before opening a transaction so
        // slow/failing HTTP calls never hold DB row locks.
        $tmdbId = isset($data['tmdbId']) ? (int)$data['tmdbId'] : null;
        $seasonPayloads = [];
        if ($tmdbId !== null) {
            $seasonNumbers = [];
            foreach (($data['seasons'] ?? []) as $s) {
                if (isset($s['season_number'])) {
                    $seasonNumbers[] = (int)$s['season_number'];
                }
            }
            if (empty($seasonNumbers)) {
                $numSeasons = (int)($data['numberOfSeasons'] ?? 0);
                for ($n = 1; $n <= $numSeasons; $n++) {
                    $seasonNumbers[] = $n;
                }
            }
            $seasonNumbers = array_values(array_unique($seasonNumbers));
            sort($seasonNumbers);

            foreach ($seasonNumbers as $seasonNumber) {
                $seasonPayloads[$seasonNumber] = $this->tmdbService->getSeasonDetails($tmdbId, $seasonNumber, $userId, $language);
            }
        }

        $this->db->beginTransaction();
        try {
            $series = new Series();
            $series->setUserId($userId);
            $series->setLibraryId($libraryId);
            $series->setTmdbId($tmdbId);
            $series->setTitle($data['title']);
            $series->setOriginalTitle($data['originalTitle'] ?? null);
            $series->setPosterPath($data['posterPath'] ?? null);
            $series->setBackdropPath($data['backdropPath'] ?? null);
            $series->setOverview($data['overview'] ?? null);
            $series->setGenreIds($data['genreIds'] ?? null);
            $series->setFirstAirDate($data['firstAirDate'] ?? null);
            $series->setFirstAirYear($data['firstAirYear'] ?? $this->extractYear($data['firstAirDate'] ?? null));
            $series->setNumberOfSeasons($data['numberOfSeasons'] ?? null);
            $series->setNumberOfEpisodes($data['numberOfEpisodes'] ?? null);
            $series->setStatus($data['status'] ?? null);
            $series->setCastData($data['castData'] ?? null);
            $series->setDirector($data['director'] ?? null);
            $series->setIsFavorite($data['isFavorite'] ?? false);
            $series->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

            $series = $this->mapper->insert($series);

            foreach ($seasonPayloads as $seasonNumber => $season) {
                $this->storeSeasonEpisodes($series->getId(), $seasonNumber, $season);
            }

            // If the Add form supplied series-level watch metadata (rating,
            // platform, language, date), persist it as the single series-level
            // watch row. The TV show owns this metadata, not individual episodes.
            if ($this->hasWatchMetadata($data)) {
                $this->upsertSeriesWatch($series->getId(), $userId, $libraryId, $data);
            }

            $this->db->commit();
            return $series;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function storeSeasonEpisodes(int $seriesId, int $seasonNumber, array $season): void {
        foreach (($season['episodes'] ?? []) as $ep) {
            $epTmdbId = isset($ep['id']) ? (int)$ep['id'] : null;
            // Skip episodes already stored for this series (idempotent re-fetch).
            // Scoped to seriesId so one user's episodes never block another user's.
            if ($epTmdbId !== null && $this->episodeMapper->findByTmdbIdAndSeries($epTmdbId, $seriesId) !== null) {
                continue;
            }
            $episode = new Episode();
            $episode->setSeriesId($seriesId);
            $episode->setTmdbId($epTmdbId);
            $episode->setSeasonNumber((int)($ep['season_number'] ?? $seasonNumber));
            $episode->setEpisodeNumber((int)($ep['episode_number'] ?? 0));
            $episode->setName((string)($ep['name'] ?? ''));
            $episode->setOverview($ep['overview'] ?? null);
            $episode->setAirDate(!empty($ep['air_date']) ? $ep['air_date'] : null);
            $episode->setRuntime(isset($ep['runtime']) ? (int)$ep['runtime'] : null);
            $episode->setStillPath($ep['still_path'] ?? null);
            $episode->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));
            $this->episodeMapper->insert($episode);
        }
    }

    /**
     * Series detail with episodes grouped by season and derived progress.
     *
     * @throws DoesNotExistException
     */
    public function findWithProgress(int $id, int $libraryId): array {
        $series = $this->mapper->find($id, $libraryId);
        $data = $series->jsonSerialize();

        $episodes = $this->episodeMapper->findBySeries($id);
        $today = (new DateTime())->format('Y-m-d');

        $seasons = [];
        $watchedCount = 0;
        $airedCount = 0;
        $nextEpisode = null;

        foreach ($episodes as $ep) {
            $epArr = $ep->jsonSerialize();
            $watched = $ep->getWatched();
            $epArr['watched'] = $watched;

            $aired = $ep->getAirDate() !== null && $ep->getAirDate() <= $today;
            $epArr['aired'] = $aired;

            // Specials (season 0) are excluded from progress denominator.
            $isSpecial = $ep->getSeasonNumber() === 0;
            if ($aired && !$isSpecial) {
                $airedCount++;
                if ($watched) {
                    $watchedCount++;
                } elseif ($nextEpisode === null) {
                    // First aired, unwatched, non-special episode (episodes are
                    // already ordered by season then episode number).
                    $nextEpisode = $epArr;
                }
            }

            $sn = $ep->getSeasonNumber();
            if (!isset($seasons[$sn])) {
                $seasons[$sn] = [
                    'seasonNumber' => $sn,
                    'episodes' => [],
                    'watchedCount' => 0,
                    'airedCount' => 0,
                ];
            }
            $seasons[$sn]['episodes'][] = $epArr;
            if ($aired && !$isSpecial) {
                $seasons[$sn]['airedCount']++;
                if ($watched) {
                    $seasons[$sn]['watchedCount']++;
                }
            }
        }

        ksort($seasons);
        foreach ($seasons as &$season) {
            $season['progress'] = $season['airedCount'] > 0
                ? (int)round(($season['watchedCount'] / $season['airedCount']) * 100)
                : 0;
        }
        unset($season);

        $data['seasons'] = array_values($seasons);
        $data['watchedEpisodeCount'] = $watchedCount;
        $data['airedEpisodeCount'] = $airedCount;
        $data['progress'] = $airedCount > 0 ? (int)round(($watchedCount / $airedCount) * 100) : 0;
        $data['nextEpisode'] = $nextEpisode;
        $data['caughtUp'] = $airedCount > 0 && $watchedCount >= $airedCount;

        // Series-level watch metadata (the TV show's own rating/platform/
        // language/date), read from the single series-level watch row.
        $summary = $this->watchMapper->getSeriesWatchSummary($id, $libraryId);
        $data['rating'] = $summary['rating'];
        $data['review'] = $summary['review'];
        $data['watchedAt'] = $summary['watchedAt'];
        $data['languageWatched'] = $summary['languageWatched'];
        $data['platformId'] = $summary['platformId'];
        $data['platformName'] = null;
        if ($summary['platformId'] !== null) {
            try {
                $data['platformName'] = $this->platformMapper
                    ->find($summary['platformId'])
                    ->getName();
            } catch (\Throwable $e) {
                // Platform was deleted; leave the name null.
            }
        }

        return $data;
    }

    /**
     * @return Episode[]
     */
    public function getEpisodes(int $seriesId, int $libraryId): array {
        // Verify access.
        $this->mapper->find($seriesId, $libraryId);
        return $this->episodeMapper->findBySeries($seriesId);
    }

    /**
     * Set a single episode's watched flag. Episodes are a plain
     * watched/unwatched toggle — no per-episode metadata.
     *
     * @throws DoesNotExistException
     */
    public function markEpisodeWatched(int $seriesId, int $episodeId, int $libraryId, bool $watched = true): void {
        $this->mapper->find($seriesId, $libraryId);
        $episode = $this->episodeMapper->find($episodeId);
        if ($episode->getSeriesId() !== $seriesId) {
            throw new DoesNotExistException('Episode does not belong to series');
        }
        if ($episode->getWatched() === $watched) {
            return; // idempotent
        }
        $episode->setWatched($watched);
        $episode->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));
        $this->episodeMapper->update($episode);
    }

    /**
     * Flip the watched flag on all aired episodes of a season.
     *
     * @throws DoesNotExistException
     */
    public function markSeasonWatched(int $seriesId, int $seasonNumber, int $libraryId, bool $watched = true): int {
        $this->mapper->find($seriesId, $libraryId);
        $episodes = $this->episodeMapper->findBySeriesAndSeason($seriesId, $seasonNumber);
        return $this->setEpisodesWatched($episodes, $watched);
    }

    /**
     * Flip the watched flag on all aired episodes of the series (excluding specials).
     *
     * @throws DoesNotExistException
     */
    public function markSeriesWatched(int $seriesId, int $libraryId, bool $watched = true): int {
        $this->mapper->find($seriesId, $libraryId);
        $episodes = array_filter(
            $this->episodeMapper->findBySeries($seriesId),
            static fn (Episode $e): bool => $e->getSeasonNumber() !== 0
        );
        return $this->setEpisodesWatched($episodes, $watched);
    }

    /**
     * Flip the watched flag on the aired episodes in $episodes via one bulk
     * UPDATE. Only episodes whose flag actually changes are counted.
     *
     * @param Episode[] $episodes
     * @return int number of episodes whose flag changed
     */
    private function setEpisodesWatched(array $episodes, bool $watched): int {
        $today = (new DateTime())->format('Y-m-d');
        $ids = [];
        foreach ($episodes as $ep) {
            $aired = $ep->getAirDate() !== null && $ep->getAirDate() <= $today;
            if (!$aired) {
                continue;
            }
            if ($ep->getWatched() === $watched) {
                continue; // idempotent
            }
            $ids[] = $ep->getId();
        }
        if (empty($ids)) {
            return 0;
        }
        return $this->episodeMapper->setWatchedForEpisodes($ids, $watched);
    }

    /**
     * Does $data carry any series-level watch metadata worth persisting?
     */
    private function hasWatchMetadata(array $data): bool {
        return isset($data['rating'])
            || isset($data['platformId'])
            || isset($data['languageWatched'])
            || isset($data['watchedAt'])
            || isset($data['review']);
    }

    /**
     * Create or update the single series-level watch row (series_id set,
     * episode_id NULL) carrying the show's rating/platform/language/date.
     */
    public function upsertSeriesWatch(int $seriesId, string $userId, int $libraryId, array $data): void {
        $watch = $this->watchMapper->findSeriesWatch($seriesId, $libraryId);
        $isNew = $watch === null;
        if ($isNew) {
            $watch = new MovieWatch();
            $watch->setSeriesId($seriesId);
            $watch->setUserId($userId);
            $watch->setLibraryId($libraryId);
            $watch->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));
        }

        $watch->setWatchedAt($data['watchedAt'] ?? $watch->getWatchedAt() ?? (new DateTime())->format('Y-m-d'));
        if (array_key_exists('rating', $data)) {
            $watch->setRating($data['rating'] !== null ? (int)$data['rating'] : null);
        }
        if (array_key_exists('review', $data)) {
            $watch->setReview($data['review']);
        }
        if (array_key_exists('platformId', $data)) {
            $watch->setPlatformId($data['platformId'] !== null ? (int)$data['platformId'] : null);
        }
        if (array_key_exists('languageWatched', $data)) {
            $watch->setLanguageWatched($data['languageWatched']);
        }

        if ($isNew) {
            $this->watchMapper->insert($watch);
        } else {
            $this->watchMapper->update($watch);
        }
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, int $libraryId, array $data): Series {
        $series = $this->mapper->find($id, $libraryId);

        if (isset($data['title'])) {
            $series->setTitle($data['title']);
        }
        if (array_key_exists('overview', $data)) {
            $series->setOverview($data['overview']);
        }
        if (array_key_exists('genreIds', $data)) {
            $series->setGenreIds($data['genreIds']);
        }
        if (array_key_exists('castData', $data)) {
            $series->setCastData($data['castData']);
        }
        if (array_key_exists('director', $data)) {
            $series->setDirector($data['director']);
        }
        if (array_key_exists('isFavorite', $data)) {
            $series->setIsFavorite($data['isFavorite']);
        }

        $series->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));

        $updated = $this->mapper->update($series);

        // Series-level watch metadata (rating/platform/language/date) lives in a
        // dedicated watch row, not on the series entity. Persist it if present.
        if ($this->hasWatchMetadata($data)) {
            $this->upsertSeriesWatch($id, $series->getUserId(), $libraryId, $data);
        }

        return $updated;
    }

    /**
     * Delete a series and cascade to its episodes and watch rows. No DB FK
     * exists, so the service deletes all three explicitly in a transaction.
     *
     * @throws DoesNotExistException
     */
    public function delete(int $id, int $libraryId): void {
        $series = $this->mapper->find($id, $libraryId);

        $this->db->beginTransaction();
        try {
            $this->watchMapper->deleteBySeries($id, $libraryId);
            $this->episodeMapper->deleteBySeries($id);
            $this->mapper->delete($series);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function extractYear(?string $date): ?int {
        if ($date === null) {
            return null;
        }
        $parts = explode('-', $date);
        return isset($parts[0]) && $parts[0] !== '' ? (int)$parts[0] : null;
    }
}
