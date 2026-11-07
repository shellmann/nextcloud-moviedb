<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Maps the show details TMDB returns (/tv/{id}) onto the extra series fields
 * shown on the detail page: type, networks and the TMDB user score.
 */
final class TmdbSeriesFields {
    public const MAX_NETWORKS = 5;

    /**
     * @return array{seriesType: string, networks: ?array, tmdbRating: ?int, tmdbVoteCount: ?int}
     */
    public static function fromDetails(array $tmdb): array {
        $networks = [];
        foreach (is_array($tmdb['networks'] ?? null) ? $tmdb['networks'] : [] as $n) {
            if (is_array($n)) {
                $networks[] = ['name' => $n['name'] ?? null, 'logoPath' => $n['logo_path'] ?? null];
            }
        }

        $votes = isset($tmdb['vote_count']) && is_numeric($tmdb['vote_count']) ? max(0, (int)$tmdb['vote_count']) : null;
        $rating = null;
        if ($votes && isset($tmdb['vote_average']) && is_numeric($tmdb['vote_average'])) {
            $rating = max(0, min(100, (int)round((float)$tmdb['vote_average'] * 10)));
        }

        return [
            // '' (not null) = "TMDB has no type": the backfill must not loop on it.
            'seriesType' => is_string($tmdb['type'] ?? null) ? mb_substr($tmdb['type'], 0, 32) : '',
            'networks' => self::sanitizeNetworks($networks),
            'tmdbRating' => $rating,
            'tmdbVoteCount' => $votes,
        ];
    }

    /**
     * Keeps at most MAX_NETWORKS entries with a non-empty name (<= 255 chars)
     * and a valid logo path (or none). Returns null for an empty result.
     */
    public static function sanitizeNetworks(mixed $networks): ?array {
        if (!is_array($networks)) {
            return null;
        }
        $clean = [];
        foreach ($networks as $n) {
            if (!is_array($n) || !is_string($n['name'] ?? null) || trim($n['name']) === '') {
                continue;
            }
            $logo = $n['logoPath'] ?? null;
            $clean[] = [
                'name' => mb_substr(trim($n['name']), 0, 255),
                'logoPath' => is_string($logo) && TmdbPath::isValid($logo) ? $logo : null,
            ];
            if (count($clean) >= self::MAX_NETWORKS) {
                break;
            }
        }
        return $clean === [] ? null : $clean;
    }
}
