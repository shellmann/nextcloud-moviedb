<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Util\WatchRegion;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\L10N\IFactory;

/**
 * Trailer and "where to watch" for a title, cached for twelve hours so that
 * opening a detail page does not call TMDB every time. A failed call (unknown
 * title, TMDB down or busy) is remembered for five minutes, so a page that is
 * opened again and again does not ask TMDB each time either.
 */
class TmdbExtrasService {
    public const TTL = 43200;
    public const FAILURE_TTL = 300;
    private const FAILED = ['failed' => true];

    private ?ICache $cache = null;

    public function __construct(
        private TmdbService $tmdb,
        private ICacheFactory $cacheFactory,
        private IFactory $l10nFactory,
    ) {
    }

    public function hasApiKey(?string $userId): bool {
        return $this->tmdb->hasApiKey($userId);
    }

    /**
     * @param string $type "movie" or "tv"
     * @return array{trailer:?array,watch:array}
     * @throws TmdbExtrasUnavailableException while an earlier failure is remembered
     * @throws \Exception when TMDB fails now
     */
    public function get(string $type, int $tmdbId, ?string $userId, string $language): array {
        $region = WatchRegion::resolve($language, $this->l10nFactory->findLocale());
        $key = 'extras-' . $type . '-' . $tmdbId . '-' . $language . '-' . $region;

        $cached = $this->cache()->get($key);
        if ($cached === self::FAILED) {
            throw new TmdbExtrasUnavailableException('TMDB failed for this title a few minutes ago');
        }
        if (is_array($cached) && array_key_exists('trailer', $cached) && array_key_exists('watch', $cached)) {
            return $cached;
        }

        try {
            $extras = $this->tmdb->getExtras($type, $tmdbId, $userId, $language, $region);
        } catch (\Exception $e) {
            $this->cache()->set($key, self::FAILED, self::FAILURE_TTL);
            throw $e;
        }
        $this->cache()->set($key, $extras, self::TTL);

        return $extras;
    }

    private function cache(): ICache {
        return $this->cache ??= $this->cacheFactory->createDistributed('moviedb-tmdb');
    }
}
