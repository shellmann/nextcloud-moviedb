<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Platform;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCP\IDBConnection;

/**
 * Writes an already validated export file (see ImportValidator) into a library.
 *
 * Everything runs in one transaction, so a failure leaves the library
 * untouched. Ownership always comes from the caller, never from the file. No
 * TMDB calls are made: metadata and episodes are taken from the file.
 * Titles that already exist in the target library are skipped.
 */
class ImportService {
    public function __construct(
        private MovieMapper $movieMapper,
        private MovieWatchMapper $watchMapper,
        private SeriesMapper $seriesMapper,
        private EpisodeMapper $episodeMapper,
        private WatchlistMapper $watchlistMapper,
        private PlatformMapper $platformMapper,
        private IDBConnection $db,
    ) {
    }

    /**
     * @param array $data Output of ImportValidator::validate()
     * @return array{imported: array<string,int>, skippedDuplicates: array<string,int>, invalid: array<string,int>}
     */
    public function import(int $libraryId, string $userId, array $data): array {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $imported = ['platforms' => 0, 'movies' => 0, 'watches' => 0, 'series' => 0, 'episodes' => 0, 'watchlist' => 0];
        $skipped = ['movies' => 0, 'series' => 0, 'watchlist' => 0];

        // Preload existing keys once instead of querying per row.
        $movieKeys = [];
        foreach ($this->movieMapper->findAllByLibrary($libraryId) as $m) {
            $movieKeys[$this->titleKey($m->getTmdbId(), $m->getTitle(), $m->getReleaseYear())] = true;
        }
        $seriesKeys = [];
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $s) {
            $seriesKeys[$this->titleKey($s->getTmdbId(), $s->getTitle(), $s->getFirstAirYear())] = true;
        }
        $watchlistKeys = [];
        foreach ($this->watchlistMapper->findAllByLibrary($libraryId) as $w) {
            $watchlistKeys[$this->titleKey($w->getTmdbId(), $w->getTitle(), null, $w->getMediaType())] = true;
        }

        $this->db->beginTransaction();
        try {
            $platformIds = $this->resolvePlatforms($userId, $data['platforms'], $now, $imported['platforms']);

            foreach ($data['movies'] as $row) {
                $key = $this->titleKey($row['tmdbId'], $row['title'], $row['releaseYear']);
                if (isset($movieKeys[$key])) {
                    $skipped['movies']++;
                    continue;
                }
                $movieKeys[$key] = true;

                $movie = new Movie();
                $movie->setUserId($userId);
                $movie->setLibraryId($libraryId);
                $movie->setTmdbId($row['tmdbId']);
                $movie->setTitle($row['title']);
                $movie->setOriginalTitle($row['originalTitle']);
                $movie->setPosterPath($row['posterPath']);
                $movie->setBackdropPath($row['backdropPath']);
                $movie->setOverview($row['overview']);
                $movie->setGenreIds($row['genreIds']);
                $movie->setReleaseDate($row['releaseDate']);
                $movie->setReleaseYear($row['releaseYear']);
                $movie->setRuntime($row['runtime']);
                $movie->setCastData($row['castData']);
                $movie->setDirector($row['director']);
                $movie->setMediaType('movie');
                $movie->setIsFavorite($row['isFavorite']);
                $movie->setCreatedAt($now);
                $movie = $this->movieMapper->insert($movie);
                $imported['movies']++;

                foreach ($row['watches'] as $w) {
                    $watch = $this->newWatch($userId, $libraryId, $w, $platformIds, $now);
                    $watch->setMovieId($movie->getId());
                    $this->watchMapper->insert($watch);
                    $imported['watches']++;
                }
            }

            foreach ($data['series'] as $row) {
                $key = $this->titleKey($row['tmdbId'], $row['title'], $row['firstAirYear']);
                if (isset($seriesKeys[$key])) {
                    $skipped['series']++;
                    continue;
                }
                $seriesKeys[$key] = true;

                $series = new Series();
                $series->setUserId($userId);
                $series->setLibraryId($libraryId);
                $series->setTmdbId($row['tmdbId']);
                $series->setTitle($row['title']);
                $series->setOriginalTitle($row['originalTitle']);
                $series->setPosterPath($row['posterPath']);
                $series->setBackdropPath($row['backdropPath']);
                $series->setOverview($row['overview']);
                $series->setGenreIds($row['genreIds']);
                $series->setFirstAirDate($row['firstAirDate']);
                $series->setFirstAirYear($row['firstAirYear']);
                $series->setNumberOfSeasons($row['numberOfSeasons']);
                $series->setNumberOfEpisodes($row['numberOfEpisodes']);
                $series->setStatus($row['status']);
                $series->setCastData($row['castData']);
                $series->setDirector($row['director']);
                $series->setIsFavorite($row['isFavorite']);
                $series->setCreatedAt($now);
                $series = $this->seriesMapper->insert($series);
                $imported['series']++;

                if ($row['watch'] !== null) {
                    $watch = $this->newWatch($userId, $libraryId, $row['watch'], $platformIds, $now);
                    $watch->setSeriesId($series->getId());
                    if ($watch->getWatchedAt() === null) {
                        $watch->setWatchedAt((new DateTime())->format('Y-m-d'));
                    }
                    $this->watchMapper->insert($watch);
                    $imported['watches']++;
                }

                $seenEpisodes = [];
                foreach ($row['episodes'] as $e) {
                    $epKey = $e['season'] . 'x' . $e['episode'];
                    if (isset($seenEpisodes[$epKey])) {
                        continue;
                    }
                    $seenEpisodes[$epKey] = true;

                    $episode = new Episode();
                    $episode->setSeriesId($series->getId());
                    $episode->setTmdbId($e['tmdbId']);
                    $episode->setSeasonNumber($e['season']);
                    $episode->setEpisodeNumber($e['episode']);
                    $episode->setName($e['name']);
                    $episode->setOverview($e['overview']);
                    $episode->setAirDate($e['airDate']);
                    $episode->setRuntime($e['runtime']);
                    $episode->setStillPath($e['stillPath']);
                    $episode->setWatched($e['watched']);
                    $episode->setCreatedAt($now);
                    $this->episodeMapper->insert($episode);
                    $imported['episodes']++;
                }
            }

            foreach ($data['watchlist'] as $row) {
                $key = $this->titleKey($row['tmdbId'], $row['title'], null, $row['mediaType']);
                if (isset($watchlistKeys[$key])) {
                    $skipped['watchlist']++;
                    continue;
                }
                $watchlistKeys[$key] = true;

                $item = new WatchlistItem();
                $item->setUserId($userId);
                $item->setLibraryId($libraryId);
                $item->setTmdbId($row['tmdbId']);
                $item->setTitle($row['title']);
                $item->setPosterPath($row['posterPath']);
                $item->setOverview($row['overview']);
                $item->setGenreIds($row['genreIds']);
                $item->setReleaseDate($row['releaseDate']);
                $item->setAddedAt($row['addedAt']);
                $item->setPriority($row['priority']);
                $item->setNotes($row['notes']);
                $item->setMediaType($row['mediaType']);
                $this->watchlistMapper->insert($item);
                $imported['watchlist']++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'imported' => $imported,
            'skippedDuplicates' => $skipped,
            'invalid' => $data['invalid'],
        ];
    }

    /**
     * Map platform names from the file to platform ids of the importing user:
     * defaults and the user's own platforms match by name (case-insensitive);
     * unknown ones are created as the user's custom platforms.
     *
     * @param array<int,array{name:string,icon:?string}> $wanted
     * @return array<string,int> lowercase name => platform id
     */
    private function resolvePlatforms(string $userId, array $wanted, string $now, int &$created): array {
        $ids = [];
        foreach ($this->platformMapper->findAllForUser($userId) as $p) {
            $ids[mb_strtolower($p->getName())] = $p->getId();
        }
        foreach ($wanted as $p) {
            $key = mb_strtolower($p['name']);
            if (isset($ids[$key])) {
                continue;
            }
            $platform = new Platform();
            $platform->setUserId($userId);
            $platform->setName($p['name']);
            $platform->setIcon($p['icon']);
            $platform->setIsDefault(false);
            $platform->setCreatedAt($now);
            $ids[$key] = $this->platformMapper->insert($platform)->getId();
            $created++;
        }
        return $ids;
    }

    private function newWatch(string $userId, int $libraryId, array $w, array $platformIds, string $now): MovieWatch {
        $watch = new MovieWatch();
        $watch->setUserId($userId);
        $watch->setLibraryId($libraryId);
        $watch->setWatchedAt($w['watchedAt']);
        $watch->setRating($w['rating']);
        $watch->setReview($w['review']);
        $watch->setPlatformId($w['platform'] !== null ? ($platformIds[mb_strtolower($w['platform'])] ?? null) : null);
        $watch->setLanguageWatched($w['language']);
        $watch->setCreatedAt($now);
        return $watch;
    }

    /** Duplicate key: the TMDB id when known, otherwise normalized title (+ year / media type). */
    private function titleKey(?int $tmdbId, string $title, ?int $year, string $mediaType = ''): string {
        if ($tmdbId !== null) {
            return 't' . $tmdbId . '|' . $mediaType;
        }
        return 'n' . mb_strtolower(trim($title)) . '|' . ($year ?? '') . '|' . $mediaType;
    }
}
