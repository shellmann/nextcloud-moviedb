<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Service\MovieWatchService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Unit tests for MovieWatchService — the per-viewing (rewatch) layer.
 */
class MovieWatchServiceTest extends TestCase {
    private MovieWatchMapper $mapper;
    private MovieWatchService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(MovieWatchMapper::class);
        $this->service = new MovieWatchService($this->mapper);
    }

    public function testFindByMovie(): void {
        $movieId = 7;
        $userId = 'testuser';
        $watches = [
            $this->createWatch(1, $movieId),
            $this->createWatch(2, $movieId),
        ];

        $this->mapper->expects($this->once())
            ->method('findByMovie')
            ->with($movieId, $userId)
            ->willReturn($watches);

        $result = $this->service->findByMovie($movieId, $userId);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MovieWatch::class, $result[0]);
    }

    public function testCreatePopulatesWatch(): void {
        $movieId = 7;
        $userId = 'testuser';
        $data = [
            'watchedAt' => '2026-08-24',
            'rating' => 8,
            'review' => 'Even better the second time',
            'platformId' => 3,
            'languageWatched' => 'de',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $watch) use ($movieId, $userId) {
                return $watch->getMovieId() === $movieId
                    && $watch->getUserId() === $userId
                    && $watch->getWatchedAt() === '2026-08-24'
                    && $watch->getRating() === 8
                    && $watch->getReview() === 'Even better the second time'
                    && $watch->getPlatformId() === 3
                    && $watch->getLanguageWatched() === 'de'
                    && $watch->getCreatedAt() !== '';
            }))
            ->willReturnArgument(0);

        $result = $this->service->create($movieId, $userId, $data);

        $this->assertInstanceOf(MovieWatch::class, $result);
        $this->assertEquals(8, $result->getRating());
    }

    public function testCreateWithMinimalData(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $watch) {
                return $watch->getMovieId() === 5
                    && $watch->getRating() === null
                    && $watch->getWatchedAt() === null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->create(5, 'testuser', []);

        $this->assertInstanceOf(MovieWatch::class, $result);
    }

    public function testCreateRejectsRatingBelowRange(): void {
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(1, 'testuser', ['rating' => 0]);
    }

    public function testCreateRejectsRatingAboveRange(): void {
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(1, 'testuser', ['rating' => 11]);
    }

    public function testUpdateAppliesOnlyProvidedFields(): void {
        $userId = 'testuser';
        $watch = $this->createWatch(1, 7);
        $watch->setRating(5);
        $watch->setReview('old');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with(1, $userId)
            ->willReturn($watch);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (MovieWatch $w) {
                // rating updated, review left untouched
                return $w->getRating() === 9
                    && $w->getReview() === 'old'
                    && $w->getUpdatedAt() !== null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update(1, $userId, ['rating' => 9]);

        $this->assertEquals(9, $result->getRating());
    }

    public function testUpdateCanNullField(): void {
        $userId = 'testuser';
        $watch = $this->createWatch(1, 7);
        $watch->setRating(5);

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($watch);
        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (MovieWatch $w) {
                return $w->getRating() === null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update(1, $userId, ['rating' => null]);

        $this->assertNull($result->getRating());
    }

    public function testUpdateRejectsInvalidRating(): void {
        $userId = 'testuser';
        $watch = $this->createWatch(1, 7);

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($watch);
        $this->mapper->expects($this->never())
            ->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update(1, $userId, ['rating' => 99]);
    }

    public function testUpdateThrowsWhenNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(DoesNotExistException::class);
        $this->service->update(999, 'testuser', ['rating' => 5]);
    }

    public function testDelete(): void {
        $userId = 'testuser';
        $watch = $this->createWatch(1, 7);

        $this->mapper->expects($this->once())
            ->method('find')
            ->with(1, $userId)
            ->willReturn($watch);
        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($watch);

        $this->service->delete(1, $userId);
    }

    public function testDeleteThrowsWhenNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(DoesNotExistException::class);
        $this->service->delete(999, 'testuser');
    }

    public function testFindByEpisode(): void {
        $episodeId = 55;
        $userId = 'testuser';
        $watches = [$this->createWatch(1, 7), $this->createWatch(2, 7)];

        $this->mapper->expects($this->once())
            ->method('findByEpisode')
            ->with($episodeId, $userId)
            ->willReturn($watches);

        $result = $this->service->findByEpisode($episodeId, $userId);

        $this->assertCount(2, $result);
    }

    public function testCreateForEpisodePopulatesEpisodeAndSeries(): void {
        $episodeId = 55;
        $seriesId = 9;
        $userId = 'testuser';
        $data = ['watchedAt' => '2026-08-24', 'rating' => 7];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $w) use ($episodeId, $seriesId, $userId) {
                return $w->getEpisodeId() === $episodeId
                    && $w->getSeriesId() === $seriesId
                    && $w->getMovieId() === null
                    && $w->getUserId() === $userId
                    && $w->getRating() === 7
                    && $w->getCreatedAt() !== '';
            }))
            ->willReturnArgument(0);

        $result = $this->service->createForEpisode($episodeId, $seriesId, $userId, $data);

        $this->assertInstanceOf(MovieWatch::class, $result);
        $this->assertEquals($episodeId, $result->getEpisodeId());
    }

    public function testCreateForEpisodeRejectsInvalidRating(): void {
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createForEpisode(55, 9, 'testuser', ['rating' => 11]);
    }

    private function createWatch(int $id, int $movieId): MovieWatch {
        $watch = new MovieWatch();
        $watch->setId($id);
        $watch->setMovieId($movieId);
        $watch->setUserId('testuser');
        $watch->setCreatedAt('2026-01-01 12:00:00');
        return $watch;
    }
}
