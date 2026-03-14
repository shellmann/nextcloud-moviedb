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

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(WatchlistMapper::class);
        $this->service = new WatchlistService($this->mapper);
    }

    public function testFind(): void {
        $userId = 'testuser';
        $itemId = 1;
        $item = new WatchlistItem();
        $item->setId($itemId);
        $item->setUserId($userId);
        $item->setTitle('Dune');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, $userId)
            ->willReturn($item);

        $result = $this->service->find($itemId, $userId);

        $this->assertInstanceOf(WatchlistItem::class, $result);
        $this->assertEquals($itemId, $result->getId());
        $this->assertEquals('Dune', $result->getTitle());
    }

    public function testFindAll(): void {
        $userId = 'testuser';
        $filters = ['search' => 'Dune', 'sort' => 'priority'];

        $items = [
            $this->createWatchlistItem(1, 'Dune'),
            $this->createWatchlistItem(2, 'Dune: Part Two'),
        ];

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with($userId, $filters)
            ->willReturn($items);

        $result = $this->service->findAll($userId, $filters);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(WatchlistItem::class, $result[0]);
    }

    public function testCount(): void {
        $userId = 'testuser';
        $expectedCount = 15;

        $this->mapper->expects($this->once())
            ->method('countAll')
            ->with($userId)
            ->willReturn($expectedCount);

        $result = $this->service->count($userId);

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

        $result = $this->service->create($userId, $data);

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
                    && $item->getAddedAt() !== null;
            }))
            ->willReturnCallback(function (WatchlistItem $item) {
                $item->setId(1);
                return $item;
            });

        $result = $this->service->create($userId, $data);

        $this->assertEquals('Oppenheimer', $result->getTitle());
        $this->assertEquals(0, $result->getPriority());
    }

    public function testUpdate(): void {
        $userId = 'testuser';
        $itemId = 1;
        $existingItem = $this->createWatchlistItem($itemId, 'Old Title');
        $existingItem->setUserId($userId);
        $existingItem->setPriority(1);

        $updateData = [
            'title' => 'Updated Title',
            'priority' => 10,
            'notes' => 'High priority',
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, $userId)
            ->willReturn($existingItem);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (WatchlistItem $item) use ($updateData) {
                return $item->getTitle() === $updateData['title']
                    && $item->getPriority() === $updateData['priority']
                    && $item->getNotes() === $updateData['notes'];
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($itemId, $userId, $updateData);

        $this->assertEquals('Updated Title', $result->getTitle());
        $this->assertEquals(10, $result->getPriority());
    }

    public function testUpdateWithNullValues(): void {
        $userId = 'testuser';
        $itemId = 1;
        $existingItem = $this->createWatchlistItem($itemId, 'Title');
        $existingItem->setUserId($userId);
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

        $result = $this->service->update($itemId, $userId, $updateData);

        $this->assertNull($result->getNotes());
    }

    public function testDelete(): void {
        $userId = 'testuser';
        $itemId = 1;
        $item = $this->createWatchlistItem($itemId, 'To Delete');
        $item->setUserId($userId);

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($itemId, $userId)
            ->willReturn($item);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($item);

        $this->service->delete($itemId, $userId);
    }

    public function testDeleteThrowsDoesNotExistException(): void {
        $userId = 'testuser';
        $itemId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('Item not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->delete($itemId, $userId);
    }

    public function testExistsByTmdbIdReturnsTrueWhenExists(): void {
        $userId = 'testuser';
        $tmdbId = 438631;
        $item = $this->createWatchlistItem(1, 'Dune');

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with($userId, $tmdbId)
            ->willReturn($item);

        $result = $this->service->existsByTmdbId($userId, $tmdbId);

        $this->assertTrue($result);
    }

    public function testExistsByTmdbIdReturnsFalseWhenNotExists(): void {
        $userId = 'testuser';
        $tmdbId = 999;

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->willReturn(null);

        $result = $this->service->existsByTmdbId($userId, $tmdbId);

        $this->assertFalse($result);
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
