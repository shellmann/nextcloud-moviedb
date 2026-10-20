<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

use OCA\MovieDB\Db\Episode;

/**
 * Maps one TMDB episode payload onto an Episode entity. Shared by adding a
 * show (SeriesService) and refreshing it (SeriesRefreshService), so both agree
 * on which fields TMDB owns.
 *
 * The watched flag and updated_at are never touched here: the latter doubles
 * as "last watched" (EpisodeMapper::progressByLibrary), so a metadata change
 * must not make an old episode look freshly watched.
 */
final class TmdbEpisode {
    public static function apply(Episode $episode, array $ep, int $seasonNumber): void {
        $episode->setTmdbId(isset($ep['id']) ? (int)$ep['id'] : $episode->getTmdbId());
        $episode->setSeasonNumber((int)($ep['season_number'] ?? $seasonNumber));
        $episode->setEpisodeNumber((int)($ep['episode_number'] ?? 0));
        $episode->setName((string)($ep['name'] ?? ''));
        $episode->setAirDate(!empty($ep['air_date']) ? $ep['air_date'] : null);
        $episode->setRuntime(isset($ep['runtime']) ? (int)$ep['runtime'] : null);
        $episode->setStillPath($ep['still_path'] ?? null);
        // TMDB sometimes has no text in the requested language yet; keep what we have then.
        if (!empty($ep['overview']) || $episode->getOverview() === null) {
            $episode->setOverview($ep['overview'] ?? null);
        }
    }
}
