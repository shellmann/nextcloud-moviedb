<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Util\TmdbEpisode;
use OCP\IDBConnection;

/**
 * Brings the seasons and episodes of a show up to date with TMDB. A show's
 * episodes are fetched once when it is added; this adds later seasons and
 * episodes and updates air dates and names afterwards.
 *
 * Only what TMDB owns changes. Watched flags are never touched and episodes
 * TMDB no longer lists are kept, so nobody loses their history. TMDB is
 * called before the transaction opens (as in SeriesService::createFromTmdb),
 * so slow HTTP calls never hold row locks.
 */
class SeriesRefreshService {
    /** Seasons with an unaired or recently aired episode are re-read: air dates and names still move. */
    private const RECENT_DAYS = 14;

    public function __construct(
        private SeriesMapper $seriesMapper,
        private EpisodeMapper $episodeMapper,
        private TmdbService $tmdbService,
        private IDBConnection $db,
    ) {
    }

    public function hasApiKey(?string $userId): bool {
        return $this->tmdbService->hasApiKey($userId);
    }

    /**
     * True for a TMDB "too many requests" answer (Guzzle uses the HTTP status as exception code).
     */
    public static function isRateLimited(\Throwable $e): bool {
        return $e->getCode() === 429;
    }

    /**
     * @param string|null $keyUserId whose TMDB key to use (falls back to the instance key)
     * @param bool $full read every season (the button on the show page: a few calls for the user who asked)
     *                   instead of only those that can have changed (the background job: few calls for all shows)
     * @return array{newEpisodes: int, updatedEpisodes: int, newSeasons: int}
     * @throws \Exception TMDB errors; the series is left unchanged then
     */
    public function refresh(Series $series, ?string $keyUserId, string $language, bool $full = false): array {
        $tmdbId = $series->getTmdbId();
        if ($tmdbId === null) {
            throw new \InvalidArgumentException('Series has no TMDB id.');
        }

        $details = $this->tmdbService->getSeriesDetails($tmdbId, $keyUserId, $language);
        $stored = $this->episodeMapper->findBySeries($series->getId());

        $seasonsToFetch = $this->seasonsToFetch($details, $stored, $full);
        $payloads = [];
        foreach ($seasonsToFetch as $seasonNumber) {
            $payloads[$seasonNumber] = $this->tmdbService->getSeasonDetails($tmdbId, $seasonNumber, $keyUserId, $language);
        }

        $knownSeasons = [];
        $byTmdbId = [];
        $byPosition = [];
        foreach ($stored as $episode) {
            $knownSeasons[$episode->getSeasonNumber()] = true;
            if ($episode->getTmdbId() !== null) {
                $byTmdbId[$episode->getTmdbId()] = $episode;
            }
            $byPosition[$episode->getSeasonNumber() . 'x' . $episode->getEpisodeNumber()] = $episode;
        }

        $result = ['newEpisodes' => 0, 'updatedEpisodes' => 0, 'newSeasons' => 0];
        $now = (new DateTime())->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            foreach ($payloads as $seasonNumber => $season) {
                $added = 0;
                foreach (($season['episodes'] ?? []) as $ep) {
                    $epTmdbId = isset($ep['id']) ? (int)$ep['id'] : null;
                    $position = (int)($ep['season_number'] ?? $seasonNumber) . 'x' . (int)($ep['episode_number'] ?? 0);
                    $episode = ($epTmdbId !== null ? ($byTmdbId[$epTmdbId] ?? null) : null) ?? ($byPosition[$position] ?? null);

                    if ($episode === null) {
                        $episode = new Episode();
                        $episode->setSeriesId($series->getId());
                        TmdbEpisode::apply($episode, $ep, $seasonNumber);
                        $episode->setCreatedAt($now);
                        $this->episodeMapper->insert($episode);
                        // Known from now on: a repeated or moved episode in this run is an update, not a second row.
                        if ($episode->getTmdbId() !== null) {
                            $byTmdbId[$episode->getTmdbId()] = $episode;
                        }
                        $byPosition[$episode->getSeasonNumber() . 'x' . $episode->getEpisodeNumber()] = $episode;
                        $result['newEpisodes']++;
                        $added++;
                        continue;
                    }

                    TmdbEpisode::apply($episode, $ep, $seasonNumber);
                    if ($episode->getUpdatedFields() !== []) {
                        $this->episodeMapper->update($episode);
                        $result['updatedEpisodes']++;
                    }
                }
                if ($added > 0 && !isset($knownSeasons[$seasonNumber])) {
                    $result['newSeasons']++;
                }
            }

            $this->applySeriesDetails($series, $details, $now);
            $this->seriesMapper->update($series);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Records that the show was looked at without changing anything else. The
     * background job uses it after a failure, so one broken or removed show
     * does not block the queue and is retried at the normal interval.
     */
    public function markRefreshed(Series $series): void {
        // Re-read the row: after a failed refresh the passed entity can still carry
        // status and counts from the rolled-back write, which must not be saved now.
        $fresh = $this->seriesMapper->find($series->getId());
        $fresh->setTmdbRefreshedAt((new DateTime())->format('Y-m-d H:i:s'));
        $this->seriesMapper->update($fresh);
    }

    private function applySeriesDetails(Series $series, array $details, string $now): void {
        if (isset($details['status']) && is_string($details['status']) && $details['status'] !== '') {
            $series->setStatus($details['status']);
        }
        if (isset($details['number_of_seasons'])) {
            $series->setNumberOfSeasons((int)$details['number_of_seasons']);
        }
        if (isset($details['number_of_episodes'])) {
            $series->setNumberOfEpisodes((int)$details['number_of_episodes']);
        }
        $series->setTmdbRefreshedAt($now);
    }

    /**
     * The seasons worth a TMDB call: new ones, ones whose episode count
     * changed, ones with an undated or recently aired or unaired episode, and
     * the latest one. Usually one or two per show. All of them in $full mode.
     *
     * @param Episode[] $stored
     * @return int[]
     */
    private function seasonsToFetch(array $details, array $stored, bool $full): array {
        $tmdbSeasons = [];
        foreach (($details['seasons'] ?? []) as $s) {
            if (isset($s['season_number'])) {
                $tmdbSeasons[(int)$s['season_number']] = (int)($s['episode_count'] ?? 0);
            }
        }
        if ($tmdbSeasons === []) {
            for ($n = 1; $n <= (int)($details['number_of_seasons'] ?? 0); $n++) {
                $tmdbSeasons[$n] = -1;
            }
        }
        if ($tmdbSeasons === []) {
            return [];
        }
        if ($full) {
            $all = array_keys($tmdbSeasons);
            sort($all);
            return $all;
        }

        $recentSince = (new DateTime())->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d');
        $info = [];
        foreach ($stored as $episode) {
            $n = $episode->getSeasonNumber();
            $info[$n] ??= ['count' => 0, 'moving' => false];
            $info[$n]['count']++;
            $airDate = $episode->getAirDate();
            if ($airDate === null || $airDate >= $recentSince) {
                $info[$n]['moving'] = true;
            }
        }

        $latest = max(array_keys($tmdbSeasons));
        $fetch = [];
        foreach ($tmdbSeasons as $seasonNumber => $episodeCount) {
            $known = $info[$seasonNumber] ?? null;
            if ($known === null || $known['count'] !== $episodeCount || $known['moving'] || $seasonNumber === $latest) {
                $fetch[] = $seasonNumber;
            }
        }
        sort($fetch);
        return $fetch;
    }
}
