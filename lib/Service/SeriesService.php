<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class SeriesService {
    private SeriesMapper $mapper;
    private EpisodeMapper $episodeMapper;
    private MovieWatchMapper $watchMapper;
    private TmdbService $tmdbService;
    private IDBConnection $db;

    public function __construct(
        SeriesMapper $mapper,
        EpisodeMapper $episodeMapper,
        MovieWatchMapper $watchMapper,
        TmdbService $tmdbService,
        IDBConnection $db
    ) {
        $this->mapper = $mapper;
        $this->episodeMapper = $episodeMapper;
        $this->watchMapper = $watchMapper;
        $this->tmdbService = $tmdbService;
        $this->db = $db;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Series {
        return $this->mapper->find($id, $userId);
    }

    /**
     * @return Series[]
     */
    public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
        return $this->mapper->findAll($userId, $filters, $limit, $offset);
    }

    public function count(string $userId, array $filters = []): int {
        return $this->mapper->countAll($userId, $filters);
    }

    public function existsByTmdbId(string $userId, int $tmdbId): bool {
        return $this->mapper->findByTmdbId($userId, $tmdbId) !== null;
    }

    public function findByTmdbId(string $userId, int $tmdbId): ?Series {
        return $this->mapper->findByTmdbId($userId, $tmdbId);
    }

    /**
     * Create a series from TMDB data and eagerly fetch + persist all its episodes
     * (one TMDB call per season). Wrapped in a transaction so a failed episode
     * fetch does not leave a series with a partial episode set.
     *
     * @param array $data Series metadata (title required) + tmdbId to drive the
     *                    per-season episode fetch. $language selects TMDB locale.
     */
    public function createFromTmdb(string $userId, array $data, string $language = 'en-US'): Series {
        $this->db->beginTransaction();
        try {
            $series = new Series();
            $series->setUserId($userId);
            $series->setTmdbId(isset($data['tmdbId']) ? (int)$data['tmdbId'] : null);
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

            // Eagerly fetch episodes per season if we have a TMDB id.
            $tmdbId = $series->getTmdbId();
            if ($tmdbId !== null) {
                $numSeasons = (int)($data['numberOfSeasons'] ?? 0);
                // Season 0 (specials) if TMDB reports it in the seasons list.
                $seasonNumbers = [];
                foreach (($data['seasons'] ?? []) as $s) {
                    if (isset($s['season_number'])) {
                        $seasonNumbers[] = (int)$s['season_number'];
                    }
                }
                if (empty($seasonNumbers)) {
                    // Fallback: 1..numberOfSeasons
                    for ($n = 1; $n <= $numSeasons; $n++) {
                        $seasonNumbers[] = $n;
                    }
                }
                $seasonNumbers = array_values(array_unique($seasonNumbers));
                sort($seasonNumbers);

                foreach ($seasonNumbers as $seasonNumber) {
                    $this->fetchAndStoreSeason($series->getId(), $tmdbId, $seasonNumber, $userId, $language);
                }
            }

            $this->db->commit();
            return $series;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function fetchAndStoreSeason(int $seriesId, int $tmdbId, int $seasonNumber, string $userId, string $language): void {
        $season = $this->tmdbService->getSeasonDetails($tmdbId, $seasonNumber, $userId, $language);
        foreach (($season['episodes'] ?? []) as $ep) {
            $epTmdbId = isset($ep['id']) ? (int)$ep['id'] : null;
            // Skip episodes we already have (idempotent re-fetch).
            if ($epTmdbId !== null && $this->episodeMapper->findByTmdbId($epTmdbId) !== null) {
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
    public function findWithProgress(int $id, string $userId): array {
        $series = $this->mapper->find($id, $userId);
        $data = $series->jsonSerialize();

        $episodes = $this->episodeMapper->findBySeries($id);
        $watchCounts = $this->watchMapper->countWatchesPerEpisode($id, $userId);
        $today = (new DateTime())->format('Y-m-d');

        $seasons = [];
        $watchedCount = 0;
        $airedCount = 0;
        $nextEpisode = null;

        foreach ($episodes as $ep) {
            $epArr = $ep->jsonSerialize();
            $count = $watchCounts[$ep->getId()] ?? 0;
            $epArr['watchCount'] = $count;
            $epArr['watched'] = $count > 0;

            $aired = $ep->getAirDate() !== null && $ep->getAirDate() <= $today;
            $epArr['aired'] = $aired;

            // Specials (season 0) are excluded from progress denominator.
            $isSpecial = $ep->getSeasonNumber() === 0;
            if ($aired && !$isSpecial) {
                $airedCount++;
                if ($count > 0) {
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
                if ($count > 0) {
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

        return $data;
    }

    /**
     * @return Episode[]
     */
    public function getEpisodes(int $seriesId, string $userId): array {
        // Verify ownership.
        $this->mapper->find($seriesId, $userId);
        return $this->episodeMapper->findBySeries($seriesId);
    }

    /**
     * Mark a single episode watched (idempotent: skips if already watched).
     *
     * @throws DoesNotExistException
     */
    public function markEpisodeWatched(int $seriesId, int $episodeId, string $userId, array $data = []): void {
        $this->mapper->find($seriesId, $userId);
        $episode = $this->episodeMapper->find($episodeId);
        if ($episode->getSeriesId() !== $seriesId) {
            throw new DoesNotExistException('Episode does not belong to series');
        }
        $watched = $this->watchMapper->findWatchedEpisodeIds($seriesId, $userId);
        if (in_array($episodeId, $watched, true)) {
            return; // already watched — idempotent
        }
        $this->insertEpisodeWatch($episodeId, $seriesId, $userId, $data);
    }

    /**
     * Fan out over aired episodes of a season, skipping already-watched ones.
     *
     * @throws DoesNotExistException
     */
    public function markSeasonWatched(int $seriesId, int $seasonNumber, string $userId, array $data = []): int {
        $this->mapper->find($seriesId, $userId);
        $episodes = $this->episodeMapper->findBySeriesAndSeason($seriesId, $seasonNumber);
        return $this->markEpisodesWatched($seriesId, $episodes, $userId, $data);
    }

    /**
     * Fan out over all aired episodes of the series (excluding specials).
     *
     * @throws DoesNotExistException
     */
    public function markSeriesWatched(int $seriesId, string $userId, array $data = []): int {
        $this->mapper->find($seriesId, $userId);
        $episodes = array_filter(
            $this->episodeMapper->findBySeries($seriesId),
            static fn (Episode $e): bool => $e->getSeasonNumber() !== 0
        );
        return $this->markEpisodesWatched($seriesId, $episodes, $userId, $data);
    }

    /**
     * @param Episode[] $episodes
     * @return int number of new watch rows inserted
     */
    private function markEpisodesWatched(int $seriesId, array $episodes, string $userId, array $data): int {
        $watched = $this->watchMapper->findWatchedEpisodeIds($seriesId, $userId);
        $watchedSet = array_flip($watched);
        $today = (new DateTime())->format('Y-m-d');
        $inserted = 0;

        $this->db->beginTransaction();
        try {
            foreach ($episodes as $ep) {
                $aired = $ep->getAirDate() !== null && $ep->getAirDate() <= $today;
                if (!$aired) {
                    continue;
                }
                if (isset($watchedSet[$ep->getId()])) {
                    continue; // idempotent
                }
                $this->insertEpisodeWatch($ep->getId(), $seriesId, $userId, $data);
                $inserted++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $inserted;
    }

    private function insertEpisodeWatch(int $episodeId, int $seriesId, string $userId, array $data): void {
        $watch = new MovieWatch();
        $watch->setEpisodeId($episodeId);
        $watch->setSeriesId($seriesId);
        $watch->setUserId($userId);
        $watch->setWatchedAt($data['watchedAt'] ?? (new DateTime())->format('Y-m-d'));
        $watch->setRating(isset($data['rating']) ? (int)$data['rating'] : null);
        $watch->setReview($data['review'] ?? null);
        $watch->setPlatformId($data['platformId'] ?? null);
        $watch->setLanguageWatched($data['languageWatched'] ?? null);
        $watch->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));
        $this->watchMapper->insert($watch);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): Series {
        $series = $this->mapper->find($id, $userId);

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

        return $this->mapper->update($series);
    }

    /**
     * Delete a series and cascade to its episodes and watch rows. No DB FK
     * exists, so the service deletes all three explicitly in a transaction.
     *
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $series = $this->mapper->find($id, $userId);

        $this->db->beginTransaction();
        try {
            $this->watchMapper->deleteBySeries($id, $userId);
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
