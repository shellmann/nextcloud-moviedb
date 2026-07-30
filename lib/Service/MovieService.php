<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class MovieService {
    private MovieMapper $mapper;

    public function __construct(MovieMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Movie {
        return $this->mapper->find($id, $userId);
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
        // Validate rating range
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
        $movie->setPlatformId($data['platformId'] ?? null);
        $movie->setLanguageWatched($data['languageWatched'] ?? null);
        $movie->setDateWatched($data['dateWatched'] ?? null);
        $movie->setRating($data['rating'] ?? null);
        $movie->setReview($data['review'] ?? null);
        $movie->setIsFavorite($data['isFavorite'] ?? false);
        $movie->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->insert($movie);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): Movie {
        $movie = $this->mapper->find($id, $userId);

        // Validate rating range
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
        if (array_key_exists('platformId', $data)) {
            $movie->setPlatformId($data['platformId']);
        }
        if (array_key_exists('languageWatched', $data)) {
            $movie->setLanguageWatched($data['languageWatched']);
        }
        if (array_key_exists('dateWatched', $data)) {
            $movie->setDateWatched($data['dateWatched']);
        }
        if (array_key_exists('rating', $data)) {
            $movie->setRating($data['rating']);
        }
        if (array_key_exists('review', $data)) {
            $movie->setReview($data['review']);
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

    private function extractYear(?string $releaseDate): ?int {
        if ($releaseDate === null) {
            return null;
        }
        $parts = explode('-', $releaseDate);
        return isset($parts[0]) ? (int)$parts[0] : null;
    }
}
