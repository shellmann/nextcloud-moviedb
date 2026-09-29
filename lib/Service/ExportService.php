<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Builds the portable JSON export of one library.
 *
 * The file deliberately carries no database ids, user ids, library ids,
 * members or shares — only the collection itself, keyed by TMDB ids. Watches
 * refer to platforms by name, since platform ids differ between instances.
 */
class ExportService {
    public function __construct(
        private MovieMapper $movieMapper,
        private MovieWatchMapper $watchMapper,
        private SeriesMapper $seriesMapper,
        private EpisodeMapper $episodeMapper,
        private WatchlistMapper $watchlistMapper,
        private PlatformMapper $platformMapper,
        private LibraryMapper $libraryMapper,
        private IAppManager $appManager,
    ) {
    }

    /**
     * @throws DoesNotExistException when the library does not exist
     */
    public function export(int $libraryId): array {
        $library = $this->libraryMapper->find($libraryId);

        $platformNames = [];
        $usedPlatforms = [];
        $platformName = function (?int $id) use (&$platformNames, &$usedPlatforms): ?string {
            if ($id === null) {
                return null;
            }
            if (!array_key_exists($id, $platformNames)) {
                $platformNames[$id] = null;
                try {
                    $platform = $this->platformMapper->find($id);
                    $platformNames[$id] = $platform->getName();
                    if (!$platform->getIsDefault()) {
                        $usedPlatforms[$platform->getName()] = $platform->getIcon();
                    }
                } catch (DoesNotExistException $e) {
                    // Watch points at a deleted platform: export it without one.
                }
            }
            return $platformNames[$id];
        };

        $watchesByMovie = [];
        $watchBySeries = [];
        foreach ($this->watchMapper->findAllByLibrary($libraryId) as $w) {
            if ($w->getMovieId() !== null) {
                $watchesByMovie[$w->getMovieId()][] = $this->watchRow($w, $platformName);
            } elseif ($w->getSeriesId() !== null) {
                // One series-level row per show; keep the first if duplicates exist.
                $watchBySeries[$w->getSeriesId()] ??= $this->watchRow($w, $platformName);
            }
        }

        $movies = [];
        foreach ($this->movieMapper->findAllByLibrary($libraryId) as $m) {
            $movies[] = [
                'tmdbId' => $m->getTmdbId(),
                'title' => $m->getTitle(),
                'originalTitle' => $m->getOriginalTitle(),
                'posterPath' => $m->getPosterPath(),
                'backdropPath' => $m->getBackdropPath(),
                'overview' => $m->getOverview(),
                'genreIds' => $m->getGenreIds(),
                'releaseDate' => $this->date($m->getReleaseDate()),
                'releaseYear' => $m->getReleaseYear(),
                'runtime' => $m->getRuntime(),
                'castData' => $m->getCastData(),
                'director' => $m->getDirector(),
                'isFavorite' => (bool)$m->getIsFavorite(),
                'watches' => $watchesByMovie[$m->getId()] ?? [],
            ];
        }

        $episodesBySeries = [];
        foreach ($this->episodeMapper->findAllByLibrary($libraryId) as $e) {
            $episodesBySeries[$e->getSeriesId()][] = [
                'tmdbId' => $e->getTmdbId(),
                'season' => $e->getSeasonNumber(),
                'episode' => $e->getEpisodeNumber(),
                'name' => $e->getName(),
                'overview' => $e->getOverview(),
                'airDate' => $this->date($e->getAirDate()),
                'runtime' => $e->getRuntime(),
                'stillPath' => $e->getStillPath(),
                'watched' => (bool)$e->getWatched(),
            ];
        }

        $series = [];
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $s) {
            $series[] = [
                'tmdbId' => $s->getTmdbId(),
                'title' => $s->getTitle(),
                'originalTitle' => $s->getOriginalTitle(),
                'posterPath' => $s->getPosterPath(),
                'backdropPath' => $s->getBackdropPath(),
                'overview' => $s->getOverview(),
                'genreIds' => $s->getGenreIds(),
                'firstAirDate' => $this->date($s->getFirstAirDate()),
                'firstAirYear' => $s->getFirstAirYear(),
                'numberOfSeasons' => $s->getNumberOfSeasons(),
                'numberOfEpisodes' => $s->getNumberOfEpisodes(),
                'status' => $s->getStatus(),
                'castData' => $s->getCastData(),
                'director' => $s->getDirector(),
                'isFavorite' => (bool)$s->getIsFavorite(),
                'watch' => $watchBySeries[$s->getId()] ?? null,
                'episodes' => $episodesBySeries[$s->getId()] ?? [],
            ];
        }

        $watchlist = [];
        foreach ($this->watchlistMapper->findAllByLibrary($libraryId) as $w) {
            $watchlist[] = [
                'tmdbId' => $w->getTmdbId(),
                'mediaType' => $w->getMediaType(),
                'title' => $w->getTitle(),
                'posterPath' => $w->getPosterPath(),
                'overview' => $w->getOverview(),
                'genreIds' => $w->getGenreIds(),
                'releaseDate' => $this->date($w->getReleaseDate()),
                'priority' => $w->getPriority(),
                'notes' => $w->getNotes(),
                'addedAt' => $w->getAddedAt(),
            ];
        }

        $platforms = [];
        foreach ($usedPlatforms as $name => $icon) {
            $platforms[] = ['name' => $name, 'icon' => $icon];
        }

        return [
            'app' => ImportValidator::APP,
            'formatVersion' => ImportValidator::FORMAT_VERSION,
            'appVersion' => $this->appManager->getAppVersion('moviedb'),
            'exportedAt' => (new DateTime())->format('c'),
            'library' => ['name' => $library->getName()],
            'platforms' => $platforms,
            'movies' => $movies,
            'series' => $series,
            'watchlist' => $watchlist,
        ];
    }

    private function watchRow(MovieWatch $w, callable $platformName): array {
        return [
            'watchedAt' => $this->date($w->getWatchedAt()),
            'rating' => $w->getRating(),
            'review' => $w->getReview(),
            'platform' => $platformName($w->getPlatformId()),
            'language' => $w->getLanguageWatched(),
        ];
    }

    /** Normalize DATE/DATETIME values to a plain Y-m-d string. */
    private function date(?string $value): ?string {
        return ($value !== null && $value !== '') ? substr($value, 0, 10) : null;
    }
}
