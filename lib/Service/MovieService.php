<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class MovieService {
    private MovieMapper $mapper;
    private MovieWatchMapper $watchMapper;

    public function __construct(MovieMapper $mapper, MovieWatchMapper $watchMapper) {
        $this->mapper = $mapper;
        $this->watchMapper = $watchMapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Movie {
        return $this->mapper->find($id, $userId);
    }

    /**
     * Returns the movie as an array merged with its latest watch fields
     * (rating, review, platformId, languageWatched, lastWatchedAt).
     * Used by the show endpoint so the edit form can pre-populate watch data.
     *
     * @throws DoesNotExistException
     */
    public function findWithLatestWatch(int $id, string $userId): array {
        $movie = $this->mapper->find($id, $userId);
        $data = $movie->jsonSerialize();

        $watches = $this->watchMapper->findByMovie($id, $userId);
        if (!empty($watches)) {
            $latest = $watches[0]; // already ordered DESC by watched_at
            $data['lastWatchedAt'] = $latest->getWatchedAt();
            $data['lastRating'] = $latest->getRating();
            $data['rating'] = $latest->getRating();
            $data['dateWatched'] = $latest->getWatchedAt();
            $data['review'] = $latest->getReview();
            $data['platformId'] = $latest->getPlatformId();
            $data['languageWatched'] = $latest->getLanguageWatched();
            $data['latestWatchId'] = $latest->getId();
        } else {
            $data['lastWatchedAt'] = null;
            $data['lastRating'] = null;
            $data['review'] = null;
            $data['platformId'] = null;
            $data['languageWatched'] = null;
            $data['latestWatchId'] = null;
        }

        return $data;
    }

    /**
     * @return Movie[]
     */
    public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
        return $this->mapper->findAll($userId, $filters, $limit, $offset);
    }

    public function count(string $userId, array $filters = []): int {
        return $this->mapper->countAll($userId, $filters);
    }

    public function create(string $userId, array $data): Movie {
        if (isset($data['rating']) && $data['rating'] !== null) {
            $rating = (int)$data['rating'];
            if ($rating < 1 || $rating > 10) {
                throw new \InvalidArgumentException('Rating must be between 1 and 10');
            }
            $data['rating'] = $rating;
        }

        $movie = new Movie();
        $movie->setUserId($userId);
        $movie->setTmdbId($data['tmdbId'] ?? null);
        $movie->setTitle($data['title']);
        $movie->setOriginalTitle($data['originalTitle'] ?? null);
        $movie->setPosterPath($data['posterPath'] ?? null);
        $movie->setBackdropPath($data['backdropPath'] ?? null);
        $movie->setOverview($data['overview'] ?? null);
        $movie->setGenreIds($data['genreIds'] ?? null);
        $movie->setReleaseDate($data['releaseDate'] ?? null);
        $movie->setReleaseYear($data['releaseYear'] ?? $this->extractYear($data['releaseDate'] ?? null));
        $movie->setRuntime($data['runtime'] ?? null);
        $movie->setCastData($data['castData'] ?? null);
        $movie->setDirector($data['director'] ?? null);
        $movie->setIsFavorite($data['isFavorite'] ?? false);
        $movie->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        $movie = $this->mapper->insert($movie);

        // Create an initial watch row if any watch-specific data was provided
        $hasWatchData = isset($data['dateWatched']) || isset($data['rating']) ||
                        isset($data['review']) || isset($data['platformId']) ||
                        isset($data['languageWatched']);

        if ($hasWatchData) {
            $watch = new MovieWatch();
            $watch->setMovieId($movie->getId());
            $watch->setUserId($userId);
            $watch->setWatchedAt($data['dateWatched'] ?? null);
            $watch->setRating($data['rating'] ?? null);
            $watch->setReview($data['review'] ?? null);
            $watch->setPlatformId($data['platformId'] ?? null);
            $watch->setLanguageWatched($data['languageWatched'] ?? null);
            $watch->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));
            $this->watchMapper->insert($watch);
        }

        return $movie;
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): Movie {
        $movie = $this->mapper->find($id, $userId);

        if (array_key_exists('rating', $data) && $data['rating'] !== null) {
            $rating = (int)$data['rating'];
            if ($rating < 1 || $rating > 10) {
                throw new \InvalidArgumentException('Rating must be between 1 and 10');
            }
            $data['rating'] = $rating;
        }

        if (isset($data['title'])) {
            $movie->setTitle($data['title']);
        }
        if (array_key_exists('tmdbId', $data)) {
            $movie->setTmdbId($data['tmdbId']);
        }
        if (array_key_exists('originalTitle', $data)) {
            $movie->setOriginalTitle($data['originalTitle']);
        }
        if (array_key_exists('posterPath', $data)) {
            $movie->setPosterPath($data['posterPath']);
        }
        if (array_key_exists('backdropPath', $data)) {
            $movie->setBackdropPath($data['backdropPath']);
        }
        if (array_key_exists('overview', $data)) {
            $movie->setOverview($data['overview']);
        }
        if (array_key_exists('genreIds', $data)) {
            $movie->setGenreIds($data['genreIds']);
        }
        if (array_key_exists('releaseDate', $data)) {
            $movie->setReleaseDate($data['releaseDate']);
            $movie->setReleaseYear($this->extractYear($data['releaseDate']));
        }
        if (array_key_exists('runtime', $data)) {
            $movie->setRuntime($data['runtime']);
        }
        if (array_key_exists('castData', $data)) {
            $movie->setCastData($data['castData']);
        }
        if (array_key_exists('director', $data)) {
            $movie->setDirector($data['director']);
        }
        if (array_key_exists('isFavorite', $data)) {
            $movie->setIsFavorite($data['isFavorite']);
        }

        $movie->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->update($movie);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $movie = $this->mapper->find($id, $userId);
        $this->mapper->delete($movie);
    }

    public function existsByTmdbId(string $userId, int $tmdbId): bool {
        return $this->mapper->findByTmdbId($userId, $tmdbId) !== null;
    }

    public function findByTmdbId(string $userId, int $tmdbId): ?Movie {
        return $this->mapper->findByTmdbId($userId, $tmdbId);
    }

    private function extractYear(?string $releaseDate): ?int {
        if ($releaseDate === null) {
            return null;
        }
        $parts = explode('-', $releaseDate);
        return isset($parts[0]) ? (int)$parts[0] : null;
    }
}

