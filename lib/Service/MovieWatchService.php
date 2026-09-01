<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class MovieWatchService {
    private MovieWatchMapper $mapper;
    private PlatformMapper $platformMapper;

    public function __construct(MovieWatchMapper $mapper, PlatformMapper $platformMapper) {
        $this->mapper = $mapper;
        $this->platformMapper = $platformMapper;
    }

    /**
     * Returns watch entries enriched with platformName so the frontend can
     * display the name even when the platform belongs to another library member.
     *
     * @return array[]
     */
    public function findByMovie(int $movieId, int $libraryId): array {
        $watches = $this->mapper->findByMovie($movieId, $libraryId);
        return array_map(function (MovieWatch $w) {
            $data = $w->jsonSerialize();
            $platformId = $w->getPlatformId();
            if ($platformId !== null) {
                try {
                    $data['platformName'] = $this->platformMapper->find($platformId)->getName();
                } catch (DoesNotExistException $e) {
                    $data['platformName'] = null;
                }
            } else {
                $data['platformName'] = null;
            }
            return $data;
        }, $watches);
    }

    /**
     * Returns raw MovieWatch entities (for internal mutation, e.g. update-on-edit).
     *
     * @return MovieWatch[]
     */
    public function findRawByMovie(int $movieId, int $libraryId): array {
        return $this->mapper->findByMovie($movieId, $libraryId);
    }

    public function create(int $movieId, string $userId, int $libraryId, array $data): MovieWatch {
        if (isset($data['rating']) && $data['rating'] !== null) {
            $rating = (int)$data['rating'];
            if ($rating < 1 || $rating > 10) {
                throw new \InvalidArgumentException('Rating must be between 1 and 10');
            }
            $data['rating'] = $rating;
        }

        $watch = new MovieWatch();
        $watch->setMovieId($movieId);
        $watch->setUserId($userId);
        $watch->setLibraryId($libraryId);
        $watch->setWatchedAt($data['watchedAt'] ?? null);
        $watch->setRating($data['rating'] ?? null);
        $watch->setReview($data['review'] ?? null);
        $watch->setPlatformId($data['platformId'] ?? null);
        $watch->setLanguageWatched($data['languageWatched'] ?? null);
        $watch->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->insert($watch);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, int $libraryId, array $data): MovieWatch {
        $watch = $this->mapper->find($id, $libraryId);

        if (array_key_exists('rating', $data) && $data['rating'] !== null) {
            $rating = (int)$data['rating'];
            if ($rating < 1 || $rating > 10) {
                throw new \InvalidArgumentException('Rating must be between 1 and 10');
            }
            $data['rating'] = $rating;
        }

        if (array_key_exists('watchedAt', $data)) {
            $watch->setWatchedAt($data['watchedAt']);
        }
        if (array_key_exists('rating', $data)) {
            $watch->setRating($data['rating']);
        }
        if (array_key_exists('review', $data)) {
            $watch->setReview($data['review']);
        }
        if (array_key_exists('platformId', $data)) {
            $watch->setPlatformId($data['platformId']);
        }
        if (array_key_exists('languageWatched', $data)) {
            $watch->setLanguageWatched($data['languageWatched']);
        }

        $watch->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->update($watch);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, int $libraryId): void {
        $watch = $this->mapper->find($id, $libraryId);
        $this->mapper->delete($watch);
    }
}
