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
    public function find(int $id, int $libraryId): WatchlistItem {
        return $this->mapper->find($id, $libraryId);
    }

    /**
     * @return WatchlistItem[]
     */
    public function findAll(int $libraryId, array $filters = [], int $limit = 50, int $offset = 0): array {
        return $this->mapper->findAll($libraryId, $filters, $limit, $offset);
    }

    /**
     * Pick one random item out of the WHOLE watchlist (not just one page) that
     * matches the filters. With `excludeId` the previous pick is skipped; if that
     * leaves nothing, the excluded item is the only match and is returned again.
     *
     * Portable random: count, then a random offset (no RAND() in QueryBuilder).
     */
    public function pickRandom(int $libraryId, array $filters = []): ?WatchlistItem {
        $total = $this->mapper->countAll($libraryId, $filters);

        if ($total === 0 && !empty($filters['excludeId'])) {
            unset($filters['excludeId']);
            $total = $this->mapper->countAll($libraryId, $filters);
        }
        if ($total === 0) {
            return null;
        }

        $items = $this->mapper->findAll(
            $libraryId,
            $filters + ['sort' => 'title', 'dir' => 'ASC'],
            1,
            random_int(0, $total - 1)
        );

        return $items[0] ?? null;
    }

    /**
     * Up to $n distinct random items matching the filters (the segments of the
     * "wheel"), in random order. Same portable approach as pickRandom(): count,
     * then random offsets. With `excludeId` the previous winner is skipped, unless
     * that leaves nothing.
     *
     * @return WatchlistItem[]
     */
    public function pickRandomSet(int $libraryId, array $filters = [], int $n = 8): array {
        $n = max(1, $n);
        $total = $this->mapper->countAll($libraryId, $filters);

        if ($total === 0 && !empty($filters['excludeId'])) {
            unset($filters['excludeId']);
            $total = $this->mapper->countAll($libraryId, $filters);
        }
        if ($total === 0) {
            return [];
        }

        $filters += ['sort' => 'title', 'dir' => 'ASC'];

        if ($total <= $n) {
            $items = $this->mapper->findAll($libraryId, $filters, $n, 0);
        } else {
            $offsets = [];
            while (count($offsets) < $n) {
                $offsets[random_int(0, $total - 1)] = true;
            }
            $items = [];
            foreach (array_keys($offsets) as $offset) {
                foreach ($this->mapper->findAll($libraryId, $filters, 1, $offset) as $item) {
                    $items[] = $item;
                }
            }
        }

        // Fisher-Yates with a CSPRNG-backed int, so the order is random too.
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    public function count(int $libraryId, array $filters = []): int {
        return $this->mapper->countAll($libraryId, $filters);
    }

    public function create(string $userId, int $libraryId, array $data): WatchlistItem {
        $item = new WatchlistItem();
        $item->setUserId($userId);
        $item->setLibraryId($libraryId);
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
    public function update(int $id, int $libraryId, array $data): WatchlistItem {
        $item = $this->mapper->find($id, $libraryId);

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
    public function delete(int $id, int $libraryId): void {
        $item = $this->mapper->find($id, $libraryId);
        $this->mapper->delete($item);
    }

    public function existsByTmdbId(int $libraryId, int $tmdbId, ?string $mediaType = null): bool {
        return $this->mapper->findByTmdbId($libraryId, $tmdbId, $mediaType) !== null;
    }
}
