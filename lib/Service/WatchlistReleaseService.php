<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Util\DateValue;

/**
 * Brings the release date of a watchlist item up to date with TMDB. Titles
 * are often added before they have a date; TMDB fills it in later or moves it.
 *
 * Only a date TMDB actually states is taken over: if TMDB has none (or takes
 * it back), the stored one stays. A new or moved date also sets
 * release_date_changed_at, which shows the "New date" badge.
 */
class WatchlistReleaseService {
    public function __construct(
        private WatchlistMapper $mapper,
        private TmdbService $tmdbService,
    ) {
    }

    /**
     * @param string|null $keyUserId whose TMDB key to use (falls back to the instance key)
     * @return bool whether the release date changed
     * @throws \Exception TMDB errors; the item is left unchanged then
     */
    public function refresh(WatchlistItem $item, ?string $keyUserId, string $language): bool {
        $tmdbId = $item->getTmdbId();
        if ($tmdbId === null) {
            return false;
        }

        if ($item->getMediaType() === 'series') {
            $details = $this->tmdbService->getSeriesDetails($tmdbId, $keyUserId, $language);
            $raw = $details['first_air_date'] ?? null;
        } else {
            $details = $this->tmdbService->getMovieDetails($tmdbId, $keyUserId, $language);
            $raw = $details['release_date'] ?? null;
        }

        $date = DateValue::normalize($raw);
        if ($date === null || $date === $item->getReleaseDate()) {
            return false;
        }

        $item->setReleaseDate($date);
        $item->setReleaseDateChangedAt((new DateTime())->format('Y-m-d H:i:s'));
        $this->mapper->update($item);

        return true;
    }
}
