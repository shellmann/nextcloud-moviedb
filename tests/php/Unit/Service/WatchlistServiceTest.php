<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\WatchlistService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Unit tests for WatchlistService
 *
 * Tests the business logic for watchlist management.
 */
class WatchlistServiceTest extends TestCase {
    private WatchlistMapper $mapper;
    private WatchlistService $service;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(WatchlistMapper::class);
        $this->service = new WatchlistService($this->mapper);
    }

    public function testFind(): void {
        $itemId = 1;
        $item = new WatchlistItem();
        $item->setId($itemId);
        $item->setUserId('testuser');
        $item->setTitle('Dune');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, self::LIBRARY_ID)
            ->willReturn($item);

        $result = $this->service->find($itemId, self::LIBRARY_ID);

        $this->assertInstanceOf(WatchlistItem::class, $result);
        $this->assertEquals($itemId, $result->getId());
        $this->assertEquals('Dune', $result->getTitle());
    }

    public function testFindAll(): void {
        $filters = ['search' => 'Dune', 'sort' => 'priority'];

        $items = [
            $this->createWatchlistItem(1, 'Dune'),
            $this->createWatchlistItem(2, 'Dune: Part Two'),
        ];

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $filters, 50, 0)
            ->willReturn($items);

        $result = $this->service->findAll(self::LIBRARY_ID, $filters);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(WatchlistItem::class, $result[0]);
    }

    public function testFindAllForwardsLimitAndOffset(): void {
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, [], 10, 20)
            ->willReturn([]);

        $this->service->findAll(self::LIBRARY_ID, [], 10, 20);
    }

    public function testCount(): void {
        $expectedCount = 15;
        $filters = ['search' => 'Dune'];

        $this->mapper->expects($this->once())
            ->method('countAll')
            ->with(self::LIBRARY_ID, $filters)
            ->willReturn($expectedCount);

        $result = $this->service->count(self::LIBRARY_ID, $filters);

        $this->assertEquals($expectedCount, $result);
    }

    public function testCreate(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 438631,
            'title' => 'Dune',
            'posterPath' => '/poster.jpg',
            'overview' => 'A noble family...',
            'genreIds' => '[878, 12]',
            'releaseDate' => '2021-09-15',
            'priority' => 5,
            'notes' => 'Must watch in IMAX',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WatchlistItem $item) use ($userId, $data) {
                return $item->getUserId() === $userId
                    && $item->getTitle() === $data['title']
                    && $item->getTmdbId() === $data['tmdbId']
                    && $item->getPriority() === $data['priority']
                    && $item->getNotes() === $data['notes']
                    && $item->getAddedAt() !== null;
            }))
            ->willReturnCallback(function (WatchlistItem $item) {
                $item->setId(1);
                return $item;
            });

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertInstanceOf(WatchlistItem::class, $result);
        $this->assertEquals($data['title'], $result->getTitle());
    }

    public function testCreateWithMinimalData(): void {
        $userId = 'testuser';
        $data = ['title' => 'Oppenheimer'];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WatchlistItem $item) use ($userId) {
                return $item->getUserId() === $userId
                    && $item->getTitle() === 'Oppenheimer'
                    && $item->getPriority() === 0
                    && $item->getMediaType() === 'movie'
                    && $item->getAddedAt() !== null;
            }))
            ->willReturnCallback(function (WatchlistItem $item) {
                $item->setId(1);
                return $item;
            });

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertEquals('Oppenheimer', $result->getTitle());
        $this->assertEquals(0, $result->getPriority());
        $this->assertEquals('movie', $result->getMediaType());
    }

    public function testCreateStoresNullForAnEmptyReleaseDate(): void {
        // TMDB sends "" for titles that are not released yet; a DATE column rejects it (issue #90).
        $data = ['title' => 'Heat 2', 'tmdbId' => 1, 'releaseDate' => ''];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (WatchlistItem $item) => $item->getReleaseDate() === null))
            ->willReturnArgument(0);

        $this->assertNull($this->service->create('testuser', self::LIBRARY_ID, $data)->getReleaseDate());
    }

    public function testReleaseDateNewsLastsThirtyDays(): void {
        $item = new WatchlistItem();
        $this->assertFalse($item->hasReleaseDateNews());

        $item->setReleaseDateChangedAt((new \DateTime('-29 days'))->format('Y-m-d H:i:s'));
        $this->assertTrue($item->hasReleaseDateNews());
        $this->assertTrue($item->jsonSerialize()['releaseDateNews']);

        $item->setReleaseDateChangedAt((new \DateTime('-31 days'))->format('Y-m-d H:i:s'));
        $this->assertFalse($item->hasReleaseDateNews());
    }

    public function testAcknowledgeReleaseDateClearsTheNews(): void {
        $item = $this->createWatchlistItem(1, 'Heat 2');
        $item->setReleaseDateChangedAt((new \DateTime())->format('Y-m-d H:i:s'));

        $this->mapper->expects($this->once())->method('find')->with(1, self::LIBRARY_ID)->willReturn($item);
        $this->mapper->expects($this->once())->method('update')->with($item)->willReturnArgument(0);

        $result = $this->service->acknowledgeReleaseDate(1, self::LIBRARY_ID);

        $this->assertNull($result->getReleaseDateChangedAt());
        $this->assertFalse($result->hasReleaseDateNews());
    }

    public function testUpdateClearsTheNews(): void {
        $item = $this->createWatchlistItem(1, 'Heat 2');
        $item->setReleaseDateChangedAt((new \DateTime())->format('Y-m-d H:i:s'));

        $this->mapper->method('find')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->assertFalse($this->service->update(1, self::LIBRARY_ID, ['priority' => 3])->hasReleaseDateNews());
    }

    public function testCreatePersistsSeriesMediaType(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 1399,
            'title' => 'Game of Thrones',
            'mediaType' => 'series',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WatchlistItem $item) {
                return $item->getTitle() === 'Game of Thrones'
                    && $item->getMediaType() === 'series';
            }))
            ->willReturnCallback(function (WatchlistItem $item) {
                $item->setId(1);
                return $item;
            });

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertEquals('series', $result->getMediaType());
    }

    public function testUpdate(): void {
        $itemId = 1;
        $existingItem = $this->createWatchlistItem($itemId, 'Old Title');
        $existingItem->setUserId('testuser');
        $existingItem->setPriority(1);

        $updateData = [
            'title' => 'Updated Title',
            'priority' => 10,
            'notes' => 'High priority',
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, self::LIBRARY_ID)
            ->willReturn($existingItem);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (WatchlistItem $item) use ($updateData) {
                return $item->getTitle() === $updateData['title']
                    && $item->getPriority() === $updateData['priority']
                    && $item->getNotes() === $updateData['notes'];
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($itemId, self::LIBRARY_ID, $updateData);

        $this->assertEquals('Updated Title', $result->getTitle());
        $this->assertEquals(10, $result->getPriority());
    }

    public function testUpdateWithNullValues(): void {
        $itemId = 1;
        $existingItem = $this->createWatchlistItem($itemId, 'Title');
        $existingItem->setUserId('testuser');
        $existingItem->setNotes('Old notes');

        $updateData = ['notes' => null];

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($existingItem);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (WatchlistItem $item) {
                return $item->getNotes() === null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($itemId, self::LIBRARY_ID, $updateData);

        $this->assertNull($result->getNotes());
    }

    public function testDelete(): void {
        $itemId = 1;
        $item = $this->createWatchlistItem($itemId, 'To Delete');
        $item->setUserId('testuser');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, self::LIBRARY_ID)
            ->willReturn($item);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($item);

        $this->service->delete($itemId, self::LIBRARY_ID);
    }

    public function testDeleteThrowsDoesNotExistException(): void {
        $itemId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('Item not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->delete($itemId, self::LIBRARY_ID);
    }

    public function testExistsByTmdbIdReturnsTrueWhenExists(): void {
        $tmdbId = 438631;
        $item = $this->createWatchlistItem(1, 'Dune');

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with(self::LIBRARY_ID, $tmdbId, null)
            ->willReturn($item);

        $result = $this->service->existsByTmdbId(self::LIBRARY_ID, $tmdbId);

        $this->assertTrue($result);
    }

    public function testExistsByTmdbIdReturnsFalseWhenNotExists(): void {
        $tmdbId = 999;

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->willReturn(null);

        $result = $this->service->existsByTmdbId(self::LIBRARY_ID, $tmdbId);

        $this->assertFalse($result);
    }

    public function testExistsByTmdbIdIsTypeAware(): void {
        $tmdbId = 1399;

        // A movie with this TMDB id exists, but no series with it does.
        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with(self::LIBRARY_ID, $tmdbId, 'series')
            ->willReturn(null);

        $result = $this->service->existsByTmdbId(self::LIBRARY_ID, $tmdbId, 'series');

        $this->assertFalse($result);
    }

    public function testPickRandomReturnsNullWhenNothingMatches(): void {
        $this->mapper->method('countAll')->willReturn(0);
        $this->mapper->expects($this->never())->method('findAll');

        $this->assertNull($this->service->pickRandom(self::LIBRARY_ID, ['genre' => 28]));
    }

    public function testPickRandomUsesOffsetWithinRangeAndStableSort(): void {
        $item = $this->createWatchlistItem(3, 'Dune');
        $this->mapper->method('countAll')->willReturn(5);
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(
                self::LIBRARY_ID,
                $this->callback(fn ($f) => $f['sort'] === 'title' && $f['dir'] === 'ASC' && $f['genre'] === 28),
                1,
                $this->callback(fn ($offset) => $offset >= 0 && $offset <= 4)
            )
            ->willReturn([$item]);

        $this->assertSame($item, $this->service->pickRandom(self::LIBRARY_ID, ['genre' => 28]));
    }

    public function testPickRandomRetriesWithoutExcludeWhenOnlyTheExcludedItemMatches(): void {
        $item = $this->createWatchlistItem(7, 'Only one');
        $counts = [];
        $this->mapper->method('countAll')->willReturnCallback(function ($lib, $filters) use (&$counts) {
            $counts[] = $filters;
            return isset($filters['excludeId']) ? 0 : 1;
        });
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn ($f) => !isset($f['excludeId'])), 1, 0)
            ->willReturn([$item]);

        $result = $this->service->pickRandom(self::LIBRARY_ID, ['excludeId' => 7]);

        $this->assertSame($item, $result);
        $this->assertCount(2, $counts);
    }

    public function testPickRandomKeepsExcludeWhenOtherItemsMatch(): void {
        $this->mapper->method('countAll')->willReturn(2);
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn ($f) => $f['excludeId'] === 7), 1, $this->anything())
            ->willReturn([$this->createWatchlistItem(8, 'Other')]);

        $this->service->pickRandom(self::LIBRARY_ID, ['excludeId' => 7]);
    }

    public function testPickRandomSetReturnsNothingWhenNoMatch(): void {
        $this->mapper->method('countAll')->willReturn(0);
        $this->mapper->expects($this->never())->method('findAll');

        $this->assertSame([], $this->service->pickRandomSet(self::LIBRARY_ID, [], 8));
    }

    public function testPickRandomSetReturnsEverythingWhenFewerThanRequested(): void {
        $items = [$this->createWatchlistItem(1, 'A'), $this->createWatchlistItem(2, 'B'), $this->createWatchlistItem(3, 'C')];
        $this->mapper->method('countAll')->willReturn(3);
        $this->mapper->expects($this->once())->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 8, 0)->willReturn($items);

        $result = $this->service->pickRandomSet(self::LIBRARY_ID, [], 8);

        $this->assertCount(3, $result);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_map(fn ($i) => $i->getId(), $result));
    }

    public function testPickRandomSetUsesDistinctOffsetsWhenThereAreMoreItems(): void {
        $this->mapper->method('countAll')->willReturn(20);
        $offsets = [];
        $this->mapper->method('findAll')->willReturnCallback(function ($lib, $f, $limit, $offset) use (&$offsets) {
            $offsets[] = $offset;
            return [$this->createWatchlistItem($offset + 1, "T$offset")];
        });

        $result = $this->service->pickRandomSet(self::LIBRARY_ID, [], 8);

        $this->assertCount(8, $result);
        $this->assertSame($offsets, array_values(array_unique($offsets)));
        $this->assertCount(8, array_unique(array_map(fn ($i) => $i->getId(), $result)));
        foreach ($offsets as $o) {
            $this->assertGreaterThanOrEqual(0, $o);
            $this->assertLessThanOrEqual(19, $o);
        }
    }

    public function testPickRandomSetDropsTheExclusionWhenItLeavesNothing(): void {
        $this->mapper->method('countAll')->willReturnCallback(fn ($lib, $f) => isset($f['excludeId']) ? 0 : 1);
        $this->mapper->expects($this->once())->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn ($f) => !isset($f['excludeId'])), 8, 0)
            ->willReturn([$this->createWatchlistItem(7, 'Only')]);

        $this->assertCount(1, $this->service->pickRandomSet(self::LIBRARY_ID, ['excludeId' => 7], 8));
    }

    private function createWatchlistItem(int $id, string $title): WatchlistItem {
        $item = new WatchlistItem();
        $item->setId($id);
        $item->setTitle($title);
        $item->setAddedAt('2024-01-01 12:00:00');
        $item->setPriority(0);
        return $item;
    }
}
