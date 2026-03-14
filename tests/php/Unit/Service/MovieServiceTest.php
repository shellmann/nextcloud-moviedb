<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
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
    private MovieService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(MovieMapper::class);
        $this->service = new MovieService($this->mapper);
    }

    public function testFind(): void {
        $userId = 'testuser';
        $movieId = 1;
        $movie = new Movie();
        $movie->setId($movieId);
        $movie->setUserId($userId);
        $movie->setTitle('Inception');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willReturn($movie);

        $result = $this->service->find($movieId, $userId);

        $this->assertInstanceOf(Movie::class, $result);
        $this->assertEquals($movieId, $result->getId());
        $this->assertEquals('Inception', $result->getTitle());
    }

    public function testFindThrowsDoesNotExistException(): void {
        $userId = 'testuser';
        $movieId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->find($movieId, $userId);
    }

    public function testFindAll(): void {
        $userId = 'testuser';
        $filters = ['genre' => 28, 'year' => 2020];
        $limit = 25;
        $offset = 0;

        $movies = [
            $this->createMovieEntity(1, 'Movie 1'),
            $this->createMovieEntity(2, 'Movie 2'),
        ];

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with($userId, $filters, $limit, $offset)
            ->willReturn($movies);

        $result = $this->service->findAll($userId, $filters, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Movie::class, $result[0]);
    }

    public function testCount(): void {
        $userId = 'testuser';
        $filters = ['favorite' => true];
        $expectedCount = 42;

        $this->mapper->expects($this->once())
            ->method('countAll')
            ->with($userId, $filters)
            ->willReturn($expectedCount);

        $result = $this->service->count($userId, $filters);

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

        $result = $this->service->create($userId, $data);

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

        $result = $this->service->create($userId, $data);

        $this->assertEquals(603, $result->getTmdbId());
        $this->assertEquals('The Matrix', $result->getTitle());
        $this->assertEquals(1999, $result->getReleaseYear());
        $this->assertEquals(5, $result->getRating());
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

        $result = $this->service->create($userId, $data);

        $this->assertEquals(2010, $result->getReleaseYear());
    }

    public function testUpdate(): void {
        $userId = 'testuser';
        $movieId = 1;
        $existingMovie = $this->createMovieEntity($movieId, 'Old Title');
        $existingMovie->setUserId($userId);

        $updateData = [
            'title' => 'New Title',
            'rating' => 4,
            'review' => 'Updated review',
            'isFavorite' => true,
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willReturn($existingMovie);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Movie $movie) use ($updateData) {
                return $movie->getTitle() === $updateData['title']
                    && $movie->getRating() === $updateData['rating']
                    && $movie->getReview() === $updateData['review']
                    && $movie->getIsFavorite() === $updateData['isFavorite']
                    && $movie->getUpdatedAt() !== null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($movieId, $userId, $updateData);

        $this->assertEquals('New Title', $result->getTitle());
        $this->assertEquals(4, $result->getRating());
        $this->assertTrue($result->getIsFavorite());
    }

    public function testUpdateWithNullValues(): void {
        $userId = 'testuser';
        $movieId = 1;
        $existingMovie = $this->createMovieEntity($movieId, 'Title');
        $existingMovie->setUserId($userId);
        $existingMovie->setRating(5);
        $existingMovie->setReview('Old review');

        // Test that null values can be set (using array_key_exists pattern)
        $updateData = [
            'rating' => null,
            'review' => null,
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($existingMovie);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Movie $movie) {
                return $movie->getRating() === null
                    && $movie->getReview() === null;
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($movieId, $userId, $updateData);

        $this->assertNull($result->getRating());
        $this->assertNull($result->getReview());
    }

    public function testUpdateThrowsDoesNotExistException(): void {
        $userId = 'testuser';
        $movieId = 999;
        $updateData = ['title' => 'New Title'];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->update($movieId, $userId, $updateData);
    }

    public function testDelete(): void {
        $userId = 'testuser';
        $movieId = 1;
        $movie = $this->createMovieEntity($movieId, 'To Delete');
        $movie->setUserId($userId);

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willReturn($movie);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($movie);

        $this->service->delete($movieId, $userId);
    }

    public function testDeleteThrowsDoesNotExistException(): void {
        $userId = 'testuser';
        $movieId = 999;

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($movieId, $userId)
            ->willThrowException(new DoesNotExistException('Movie not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->delete($movieId, $userId);
    }

    public function testExistsByTmdbIdReturnsTrueWhenExists(): void {
        $userId = 'testuser';
        $tmdbId = 603;
        $movie = $this->createMovieEntity(1, 'The Matrix');

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with($userId, $tmdbId)
            ->willReturn($movie);

        $result = $this->service->existsByTmdbId($userId, $tmdbId);

        $this->assertTrue($result);
    }

    public function testExistsByTmdbIdReturnsFalseWhenNotExists(): void {
        $userId = 'testuser';
        $tmdbId = 999;

        $this->mapper->expects($this->once())
            ->method('findByTmdbId')
            ->with($userId, $tmdbId)
            ->willReturn(null);

        $result = $this->service->existsByTmdbId($userId, $tmdbId);

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
