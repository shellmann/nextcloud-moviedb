<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Unit tests for MovieService
 *
 * Tests the business logic layer for movie CRUD operations.
 */
class MovieServiceTest extends TestCase {
    private MovieMapper $mapper;
    private MovieWatchMapper $watchMapper;
    private MovieService $service;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(MovieMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->service = new MovieService($this->mapper, $this->watchMapper);
    }

    public function testFind(): void {
        $movieId = 1;
        $movie = new Movie();
        $movie->setId($movieId);
        $movie->setTitle('Inception');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willReturn($movie);

        $result = $this->service->find($movieId, self::LIBRARY_ID);

        $this->assertInstanceOf(Movie::class, $result);
        $this->assertEquals($movieId, $result->getId());
        $this->assertEquals('Inception', $result->getTitle());
    }

    public function testFindThrowsDoesNotExistException(): void {
        $movieId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->find($movieId, self::LIBRARY_ID);
    }

    public function testFindAll(): void {
        $filters = ['genre' => 28, 'year' => 2020];
        $limit = 25;
        $offset = 0;

        $movies = [
            $this->createMovieEntity(1, 'Movie 1'),
            $this->createMovieEntity(2, 'Movie 2'),
        ];

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $filters, $limit, $offset)
            ->willReturn($movies);

        $result = $this->service->findAll(self::LIBRARY_ID, $filters, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Movie::class, $result[0]);
    }

    public function testCount(): void {
        $filters = ['favorite' => true];
        $expectedCount = 42;

        $this->mapper->expects($this->once())
            ->method('countAll')
            ->with(self::LIBRARY_ID, $filters)
            ->willReturn($expectedCount);

        $result = $this->service->count(self::LIBRARY_ID, $filters);

        $this->assertEquals($expectedCount, $result);
    }

    public function testCreateWithMinimalData(): void {
        $userId = 'testuser';
        $data = [
            'title' => 'The Matrix',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Movie $movie) use ($userId) {
                return $movie->getUserId() === $userId
                    && $movie->getTitle() === 'The Matrix'
                    && $movie->getIsFavorite() === false
                    && $movie->getCreatedAt() !== null;
            }))
            ->willReturnCallback(function (Movie $movie) {
                $movie->setId(1);
                return $movie;
            });

        // No watch data provided → no watch row created
        $this->watchMapper->expects($this->never())
            ->method('insert');

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertInstanceOf(Movie::class, $result);
        $this->assertEquals('The Matrix', $result->getTitle());
        $this->assertEquals($userId, $result->getUserId());
    }

    public function testCreateWithFullData(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 603,
            'title' => 'The Matrix',
            'originalTitle' => 'The Matrix',
            'posterPath' => '/poster.jpg',
            'backdropPath' => '/backdrop.jpg',
            'overview' => 'A computer hacker learns...',
            'genreIds' => '[28, 878]',
            'releaseDate' => '1999-03-31',
            'releaseYear' => 1999,
            'runtime' => 136,
            'castData' => '[{"name":"Keanu Reeves"}]',
            'director' => 'Wachowski Brothers',
            'platformId' => 1,
            'languageWatched' => 'en',
            'dateWatched' => '2024-01-15',
            'rating' => 5,
            'review' => 'Mind-blowing!',
            'isFavorite' => true,
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Movie $movie) {
                $movie->setId(1);
                return $movie;
            });

        // Watch-specific fields must be written to a MovieWatch row, not the movie
        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $watch) {
                return $watch->getMovieId() === 1
                    && $watch->getRating() === 5
                    && $watch->getReview() === 'Mind-blowing!'
                    && $watch->getPlatformId() === 1
                    && $watch->getWatchedAt() === '2024-01-15'
                    && $watch->getLanguageWatched() === 'en';
            }))
            ->willReturnArgument(0);

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertEquals(603, $result->getTmdbId());
        $this->assertEquals('The Matrix', $result->getTitle());
        $this->assertEquals(1999, $result->getReleaseYear());
        $this->assertTrue($result->getIsFavorite());
    }

    public function testCreateExtractsYearFromReleaseDate(): void {
        $userId = 'testuser';
        $data = [
            'title' => 'Inception',
            'releaseDate' => '2010-07-16',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Movie $movie) {
                return $movie->getReleaseYear() === 2010;
            }))
            ->willReturnCallback(function (Movie $movie) {
                $movie->setId(1);
                return $movie;
            });

        $result = $this->service->create($userId, self::LIBRARY_ID, $data);

        $this->assertEquals(2010, $result->getReleaseYear());
    }

    public function testUpdate(): void {
        $movieId = 1;
        $existingMovie = $this->createMovieEntity($movieId, 'Old Title');

        $updateData = [
            'title' => 'New Title',
            'isFavorite' => true,
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willReturn($existingMovie);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Movie $movie) use ($updateData) {
                return $movie->getTitle() === $updateData['title']
                    && $movie->getIsFavorite() === $updateData['isFavorite']
                    && $movie->getUpdatedAt() !== null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($movieId, self::LIBRARY_ID, $updateData);

        $this->assertEquals('New Title', $result->getTitle());
        $this->assertTrue($result->getIsFavorite());
    }

    public function testUpdateIgnoresWatchFields(): void {
        // Watch data (rating/review/etc.) is owned by MovieWatch now; update must
        // not touch the movie row with it and must not create a watch row.
        $movieId = 1;
        $existingMovie = $this->createMovieEntity($movieId, 'Title');

        $updateData = [
            'title' => 'Renamed',
            'rating' => 4,
            'review' => 'ignored here',
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($existingMovie);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);
        $this->watchMapper->expects($this->never())
            ->method('insert');

        $result = $this->service->update($movieId, self::LIBRARY_ID, $updateData);

        $this->assertEquals('Renamed', $result->getTitle());
    }

    public function testUpdateThrowsDoesNotExistException(): void {
        $movieId = 999;
        $updateData = ['title' => 'New Title'];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->update($movieId, self::LIBRARY_ID, $updateData);
    }

    public function testDelete(): void {
        $movieId = 1;
        $movie = $this->createMovieEntity($movieId, 'To Delete');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willReturn($movie);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($movie);

        $this->service->delete($movieId, self::LIBRARY_ID);
    }

    public function testDeleteThrowsDoesNotExistException(): void {
        $movieId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, self::LIBRARY_ID)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->delete($movieId, self::LIBRARY_ID);
    }

    public function testExistsByTmdbIdReturnsTrueWhenExists(): void {
        $tmdbId = 603;
        $movie = $this->createMovieEntity(1, 'The Matrix');

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with(self::LIBRARY_ID, $tmdbId)
            ->willReturn($movie);

        $result = $this->service->existsByTmdbId(self::LIBRARY_ID, $tmdbId);

        $this->assertTrue($result);
    }

    public function testExistsByTmdbIdReturnsFalseWhenNotExists(): void {
        $tmdbId = 999;

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with(self::LIBRARY_ID, $tmdbId)
            ->willReturn(null);

        $result = $this->service->existsByTmdbId(self::LIBRARY_ID, $tmdbId);

        $this->assertFalse($result);
    }

    /**
     * Helper method to create a Movie entity for testing
     */
    private function createMovieEntity(int $id, string $title): Movie {
        $movie = new Movie();
        $movie->setId($id);
        $movie->setTitle($title);
        $movie->setCreatedAt('2024-01-01 12:00:00');
        return $movie;
    }
}
