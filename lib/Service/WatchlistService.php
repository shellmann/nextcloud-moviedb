<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class WatchlistService {
    private WatchlistMapper $mapper;

    public function __construct(WatchlistMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): WatchlistItem {
        return $this->mapper->find($id, $userId);
    }

    /**
     * @return WatchlistItem[]
     */
    public function findAll(string $userId, array $filters = []): array {
        return $this->mapper->findAll($userId, $filters);
    }

    public function count(string $userId): int {
        return $this->mapper->countAll($userId);
    }

    public function create(string $userId, array $data): WatchlistItem {
        $item = new WatchlistItem();
        $item->setUserId($userId);
        $item->setTmdbId($data['tmdbId'] ?? null);
        $item->setTitle($data['title']);
        $item->setPosterPath($data['posterPath'] ?? null);
        $item->setOverview($data['overview'] ?? null);
        $item->setGenreIds($data['genreIds'] ?? null);
        $item->setReleaseDate($data['releaseDate'] ?? null);
        $item->setAddedAt((new DateTime())->format('Y-m-d H:i:s'));
        $item->setPriority($data['priority'] ?? 0);
        $item->setNotes($data['notes'] ?? null);
        $item->setMediaType($data['mediaType'] ?? 'movie');

        return $this->mapper->insert($item);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): WatchlistItem {
        $item = $this->mapper->find($id, $userId);

        if (isset($data['title'])) {
            $item->setTitle($data['title']);
        }
        if (array_key_exists('priority', $data)) {
            $item->setPriority($data['priority']);
        }
        if (array_key_exists('notes', $data)) {
            $item->setNotes($data['notes']);
        }

        return $this->mapper->update($item);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $item = $this->mapper->find($id, $userId);
        $this->mapper->delete($item);
    }

    public function existsByTmdbId(string $userId, int $tmdbId, ?string $mediaType = null): bool {
        return $this->mapper->findByTmdbId($userId, $tmdbId, $mediaType) !== null;
    }
}
