<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Util\TmdbPath;

/**
 * Validates and normalizes a decoded library export file before import.
 *
 * The file is untrusted input: every field is type-checked, length-capped to
 * its column size and re-built from scratch, so nothing from the file (ids,
 * owners, unknown keys, markup) reaches the database unchecked. A structurally
 * invalid file is rejected whole; single bad rows are skipped and counted.
 * Pure logic, no DB access.
 */
class ImportValidator {
    public const APP = 'moviedb';
    public const FORMAT_VERSION = 1;

    public const MAX_MOVIES = 20000;
    public const MAX_SERIES = 5000;
    public const MAX_EPISODES_PER_SERIES = 10000;
    public const MAX_EPISODES_TOTAL = 200000;
    public const MAX_WATCHLIST = 20000;
    public const MAX_PLATFORMS = 200;
    public const MAX_WATCHES_PER_MOVIE = 1000;

    private const TEXT_MAX = 20000;
    private const MEDIA_TYPES = ['movie', 'series'];

    /**
     * @param array $data Decoded JSON
     * @return array{platforms: array<int,array{name:string,icon:?string}>, movies: array, series: array, watchlist: array, invalid: array<string,int>}
     * @throws \InvalidArgumentException when the file as a whole is unusable
     */
    public function validate(array $data): array {
        if (($data['app'] ?? null) !== self::APP) {
            throw new \InvalidArgumentException('This is not a MovieDB export file.');
        }
        $version = $data['formatVersion'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new \InvalidArgumentException('Unrecognized export file version.');
        }
        if ($version > self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('This file was created by a newer version of MovieDB. Please update the app first.');
        }

        $movies = $this->listOf($data, 'movies', self::MAX_MOVIES);
        $series = $this->listOf($data, 'series', self::MAX_SERIES);
        $watchlist = $this->listOf($data, 'watchlist', self::MAX_WATCHLIST);
        $platforms = $this->listOf($data, 'platforms', self::MAX_PLATFORMS);

        $invalid = ['platforms' => 0, 'movies' => 0, 'watches' => 0, 'series' => 0, 'episodes' => 0, 'watchlist' => 0];

        $platformNames = [];
        foreach ($platforms as $p) {
            $name = is_array($p) ? $this->str($p['name'] ?? null, 128) : null;
            if ($name === null) {
                $invalid['platforms']++;
                continue;
            }
            $platformNames[strtolower($name)] = ['name' => $name, 'icon' => $this->str($p['icon'] ?? null, 64)];
        }

        $outMovies = [];
        foreach ($movies as $m) {
            $row = is_array($m) ? $this->movie($m, $invalid) : null;
            if ($row === null) {
                $invalid['movies']++;
                continue;
            }
            $outMovies[] = $row;
        }

        $outSeries = [];
        $episodeTotal = 0;
        foreach ($series as $s) {
            $row = is_array($s) ? $this->series($s, $invalid) : null;
            if ($row === null) {
                $invalid['series']++;
                continue;
            }
            $episodeTotal += count($row['episodes']);
            if ($episodeTotal > self::MAX_EPISODES_TOTAL) {
                throw new \InvalidArgumentException('The file contains too many episodes.');
            }
            $outSeries[] = $row;
        }

        $outWatchlist = [];
        foreach ($watchlist as $w) {
            $row = is_array($w) ? $this->watchlistItem($w) : null;
            if ($row === null) {
                $invalid['watchlist']++;
                continue;
            }
            $outWatchlist[] = $row;
        }

        // Platforms referenced by a watch but missing from the platforms list
        // are still imported (matched or created by name).
        foreach ($outMovies as $m) {
            foreach ($m['watches'] as $w) {
                $this->collectPlatform($platformNames, $w['platform']);
            }
        }
        foreach ($outSeries as $s) {
            if ($s['watch'] !== null) {
                $this->collectPlatform($platformNames, $s['watch']['platform']);
            }
        }

        return [
            'platforms' => array_values($platformNames),
            'movies' => $outMovies,
            'series' => $outSeries,
            'watchlist' => $outWatchlist,
            'invalid' => $invalid,
        ];
    }

    private function collectPlatform(array &$names, ?string $name): void {
        if ($name !== null) {
            $names[strtolower($name)] ??= ['name' => $name, 'icon' => null];
        }
    }

    /**
     * @return array<int,mixed>
     */
    private function listOf(array $data, string $key, int $max): array {
        $value = $data[$key] ?? [];
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new \InvalidArgumentException("Invalid \"$key\" section in the export file.");
        }
        if (count($value) > $max) {
            throw new \InvalidArgumentException("The file contains too many entries in \"$key\" (maximum $max).");
        }
        return $value;
    }

    private function movie(array $m, array &$invalid): ?array {
        $title = $this->str($m['title'] ?? null, 512);
        if ($title === null) {
            return null;
        }
        $releaseDate = $this->date($m['releaseDate'] ?? null);

        $watches = [];
        $rawWatches = is_array($m['watches'] ?? null) && array_is_list($m['watches']) ? $m['watches'] : [];
        foreach (array_slice($rawWatches, 0, self::MAX_WATCHES_PER_MOVIE) as $w) {
            $row = is_array($w) ? $this->watch($w) : null;
            if ($row === null) {
                $invalid['watches']++;
                continue;
            }
            $watches[] = $row;
        }

        return [
            'tmdbId' => $this->posInt($m['tmdbId'] ?? null),
            'title' => $title,
            'originalTitle' => $this->str($m['originalTitle'] ?? null, 512),
            'posterPath' => $this->tmdbPath($m['posterPath'] ?? null),
            'backdropPath' => $this->tmdbPath($m['backdropPath'] ?? null),
            'overview' => $this->str($m['overview'] ?? null, self::TEXT_MAX),
            'genreIds' => $this->genreIds($m['genreIds'] ?? null),
            'releaseDate' => $releaseDate,
            'releaseYear' => $this->year($m['releaseYear'] ?? null) ?? $this->yearFromDate($releaseDate),
            'runtime' => $this->intRange($m['runtime'] ?? null, 0, 100000),
            'castData' => $this->cast($m['castData'] ?? null),
            'director' => $this->str($m['director'] ?? null, 255),
            'isFavorite' => ($m['isFavorite'] ?? false) === true,
            'watches' => $watches,
        ];
    }

    private function series(array $s, array &$invalid): ?array {
        $title = $this->str($s['title'] ?? null, 512);
        if ($title === null) {
            return null;
        }
        $firstAirDate = $this->date($s['firstAirDate'] ?? null);

        $watch = null;
        if (is_array($s['watch'] ?? null)) {
            $watch = $this->watch($s['watch']);
            if ($watch === null) {
                $invalid['watches']++;
            }
        }

        $episodes = [];
        $rawEpisodes = is_array($s['episodes'] ?? null) && array_is_list($s['episodes']) ? $s['episodes'] : [];
        if (count($rawEpisodes) > self::MAX_EPISODES_PER_SERIES) {
            throw new \InvalidArgumentException('A show in the file contains too many episodes.');
        }
        foreach ($rawEpisodes as $e) {
            $row = is_array($e) ? $this->episode($e) : null;
            if ($row === null) {
                $invalid['episodes']++;
                continue;
            }
            $episodes[] = $row;
        }

        return [
            'tmdbId' => $this->posInt($s['tmdbId'] ?? null),
            'title' => $title,
            'originalTitle' => $this->str($s['originalTitle'] ?? null, 512),
            'posterPath' => $this->tmdbPath($s['posterPath'] ?? null),
            'backdropPath' => $this->tmdbPath($s['backdropPath'] ?? null),
            'overview' => $this->str($s['overview'] ?? null, self::TEXT_MAX),
            'genreIds' => $this->genreIds($s['genreIds'] ?? null),
            'firstAirDate' => $firstAirDate,
            'firstAirYear' => $this->year($s['firstAirYear'] ?? null) ?? $this->yearFromDate($firstAirDate),
            'numberOfSeasons' => $this->intRange($s['numberOfSeasons'] ?? null, 0, 10000),
            'numberOfEpisodes' => $this->intRange($s['numberOfEpisodes'] ?? null, 0, 100000),
            'status' => $this->str($s['status'] ?? null, 32),
            'castData' => $this->cast($s['castData'] ?? null),
            'director' => $this->str($s['director'] ?? null, 255),
            'isFavorite' => ($s['isFavorite'] ?? false) === true,
            'watch' => $watch,
            'episodes' => $episodes,
        ];
    }

    private function episode(array $e): ?array {
        $season = $this->intRange($e['season'] ?? null, 0, 10000);
        $number = $this->intRange($e['episode'] ?? null, 0, 100000);
        if ($season === null || $number === null) {
            return null;
        }
        return [
            'tmdbId' => $this->posInt($e['tmdbId'] ?? null),
            'season' => $season,
            'episode' => $number,
            'name' => $this->str($e['name'] ?? null, 512) ?? '',
            'overview' => $this->str($e['overview'] ?? null, self::TEXT_MAX),
            'airDate' => $this->date($e['airDate'] ?? null),
            'runtime' => $this->intRange($e['runtime'] ?? null, 0, 100000),
            'stillPath' => $this->tmdbPath($e['stillPath'] ?? null),
            'watched' => ($e['watched'] ?? false) === true,
        ];
    }

    private function watch(array $w): ?array {
        $rating = null;
        if (($w['rating'] ?? null) !== null) {
            $rating = $this->intRange($w['rating'], 1, 10);
            if ($rating === null) {
                return null;
            }
        }
        return [
            'watchedAt' => $this->date($w['watchedAt'] ?? null),
            'rating' => $rating,
            'review' => $this->str($w['review'] ?? null, self::TEXT_MAX),
            'platform' => $this->str($w['platform'] ?? null, 128),
            'language' => $this->str($w['language'] ?? null, 10),
        ];
    }

    private function watchlistItem(array $w): ?array {
        $title = $this->str($w['title'] ?? null, 512);
        $mediaType = $w['mediaType'] ?? 'movie';
        if ($title === null || !in_array($mediaType, self::MEDIA_TYPES, true)) {
            return null;
        }
        return [
            'tmdbId' => $this->posInt($w['tmdbId'] ?? null),
            'mediaType' => $mediaType,
            'title' => $title,
            'posterPath' => $this->tmdbPath($w['posterPath'] ?? null),
            'overview' => $this->str($w['overview'] ?? null, self::TEXT_MAX),
            'genreIds' => $this->genreIds($w['genreIds'] ?? null),
            'releaseDate' => $this->date($w['releaseDate'] ?? null),
            'priority' => $this->intRange($w['priority'] ?? null, 0, 10) ?? 0,
            'notes' => $this->str($w['notes'] ?? null, self::TEXT_MAX),
            'addedAt' => $this->dateTime($w['addedAt'] ?? null),
        ];
    }

    /**
     * Trimmed non-empty string, capped to $max characters, or null.
     *
     * Control characters other than tab, newline and carriage return are
     * removed: PostgreSQL's driver silently truncates text at a NUL byte, and
     * none of them belong in titles or reviews.
     */
    private function str(mixed $v, int $max): ?string {
        if (!is_string($v)) {
            return null;
        }
        $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v));
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $max);
    }

    private function posInt(mixed $v): ?int {
        return $this->intRange($v, 1, PHP_INT_MAX);
    }

    private function intRange(mixed $v, int $min, int $max): ?int {
        if (!is_int($v) || $v < $min || $v > $max) {
            return null;
        }
        return $v;
    }

    private function year(mixed $v): ?int {
        return $this->intRange($v, 1800, 2200);
    }

    private function yearFromDate(?string $date): ?int {
        return $date !== null ? (int)substr($date, 0, 4) : null;
    }

    /** Strict Y-m-d calendar date, or null. */
    private function date(mixed $v): ?string {
        if (!is_string($v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $d->format('Y-m-d');
    }

    /** "Y-m-d H:i:s" (or a plain date), falling back to now. */
    private function dateTime(mixed $v): string {
        if (is_string($v)) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d->format('Y-m-d H:i:s');
            }
            $date = $this->date($v);
            if ($date !== null) {
                return $date . ' 00:00:00';
            }
        }
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function tmdbPath(mixed $v): ?string {
        if (!is_string($v) || strlen($v) > 255 || !TmdbPath::isValid($v)) {
            return null;
        }
        return $v;
    }

    /** @return int[]|null */
    private function genreIds(mixed $v): ?array {
        if (!is_array($v) || !array_is_list($v)) {
            return null;
        }
        $ids = [];
        foreach (array_slice($v, 0, 50) as $id) {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return array<int,array{name:string,character:?string,profilePath:?string}>|null */
    private function cast(mixed $v): ?array {
        if (!is_array($v) || !array_is_list($v)) {
            return null;
        }
        $out = [];
        foreach (array_slice($v, 0, 50) as $actor) {
            if (!is_array($actor)) {
                continue;
            }
            $name = $this->str($actor['name'] ?? null, 255);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'character' => $this->str($actor['character'] ?? null, 255),
                'profilePath' => $this->tmdbPath($actor['profilePath'] ?? null),
            ];
        }
        return $out;
    }
}
