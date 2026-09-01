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
 * Tests for MovieService::findWithLatestWatch — the endpoint used by the edit
 * form to pre-populate watch fields (review, rating, platform, language, date).
 *
 * Regression guard: before this method existed, the single-movie endpoint
 * returned bare entity fields only, leaving review/rating/platformId/
 * languageWatched/dateWatched always null in the edit form.
 */
class MovieServiceFindWithLatestWatchTest extends TestCase {
    private MovieMapper $mapper;
    private MovieWatchMapper $watchMapper;
    private MovieService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = $this->createMock(MovieMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->service = new MovieService($this->mapper, $this->watchMapper);
    }

    public function testReturnsLatestWatchFieldsMergedIntoMovieArray(): void {
        $movie = $this->makeMovie(1, 'Inception');
        $watch = $this->makeWatch(1, '2024-06-15', 9, 'Mind-blowing', 2, 'en');

        $this->mapper->method('find')->willReturn($movie);
        $this->watchMapper->method('findByMovie')->willReturn([$watch]);

        $result = $this->service->findWithLatestWatch(1, 1);

        $this->assertIsArray($result);
        $this->assertEquals('2024-06-15', $result['lastWatchedAt']);
        $this->assertEquals(9, $result['lastRating']);
        $this->assertEquals('2024-06-15', $result['dateWatched']);
        $this->assertEquals(9, $result['rating']);
        $this->assertEquals('Mind-blowing', $result['review']);
        $this->assertEquals(2, $result['platformId']);
        $this->assertEquals('en', $result['languageWatched']);
        $this->assertEquals(42, $result['latestWatchId']);
    }

    public function testFirstWatchIsUsedWhenMultipleExist(): void {
        // findByMovie returns DESC order — index 0 is the most recent
        $movie = $this->makeMovie(1, 'Dune');
        $latest = $this->makeWatch(10, '2025-01-01', 8, 'Epic rewatch', null, 'de');
        $older  = $this->makeWatch(5,  '2023-05-20', 7, 'First watch',  1, 'en');

        $this->mapper->method('find')->willReturn($movie);
        $this->watchMapper->method('findByMovie')->willReturn([$latest, $older]);

        $result = $this->service->findWithLatestWatch(1, 1);

        $this->assertEquals('2025-01-01', $result['dateWatched']);
        $this->assertEquals(8, $result['rating']);
        $this->assertEquals('Epic rewatch', $result['review']);
        $this->assertNull($result['platformId']);
        $this->assertEquals('de', $result['languageWatched']);
    }

    public function testNullsReturnedWhenNoWatchExists(): void {
        $movie = $this->makeMovie(1, 'Unwatched');

        $this->mapper->method('find')->willReturn($movie);
        $this->watchMapper->method('findByMovie')->willReturn([]);

        $result = $this->service->findWithLatestWatch(1, 1);

        $this->assertNull($result['lastWatchedAt']);
        $this->assertNull($result['lastRating']);
        $this->assertNull($result['dateWatched']);
        $this->assertNull($result['rating']);
        $this->assertNull($result['review']);
        $this->assertNull($result['platformId']);
        $this->assertNull($result['languageWatched']);
        $this->assertNull($result['latestWatchId']);
    }

    public function testMovieTitleStillPresentInReturnedArray(): void {
        $movie = $this->makeMovie(7, 'The Dark Knight');
        $watch = $this->makeWatch(1, null, null, null, null, null);

        $this->mapper->method('find')->willReturn($movie);
        $this->watchMapper->method('findByMovie')->willReturn([$watch]);

        $result = $this->service->findWithLatestWatch(7, 1);

        $this->assertEquals(7, $result['id']);
        $this->assertEquals('The Dark Knight', $result['title']);
    }

    public function testThrowsWhenMovieNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(DoesNotExistException::class);
        $this->service->findWithLatestWatch(999, 1);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeMovie(int $id, string $title): Movie {
        $m = new Movie();
        $m->setId($id);
        $m->setTitle($title);
        $m->setUserId('user1');
        $m->setCreatedAt('2024-01-01 00:00:00');
        return $m;
    }

    private function makeWatch(
        int $id,
        ?string $watchedAt,
        ?int $rating,
        ?string $review,
        ?int $platformId,
        ?string $language,
    ): MovieWatch {
        $w = new MovieWatch();
        $w->setId(42);
        $w->setMovieId(1);
        $w->setUserId('user1');
        $w->setWatchedAt($watchedAt);
        $w->setRating($rating);
        $w->setReview($review);
        $w->setPlatformId($platformId);
        $w->setLanguageWatched($language);
        return $w;
    }
}
