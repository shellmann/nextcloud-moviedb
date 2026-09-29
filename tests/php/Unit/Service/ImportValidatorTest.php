<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\ImportValidator;
use OCA\MovieDB\Tests\Unit\TestCase;

class ImportValidatorTest extends TestCase {
    private ImportValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new ImportValidator();
    }

    private function file(array $overrides = []): array {
        return array_merge(['app' => 'moviedb', 'formatVersion' => 1], $overrides);
    }

    public function testRejectsForeignFile(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate(['app' => 'other', 'formatVersion' => 1]);
    }

    public function testRejectsNewerFormatVersion(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('newer version');
        $this->validator->validate($this->file(['formatVersion' => 2]));
    }

    public function testRejectsNonIntegerVersion(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate($this->file(['formatVersion' => '1']));
    }

    public function testRejectsSectionThatIsNotAList(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate($this->file(['movies' => ['a' => 1]]));
    }

    public function testRejectsTooManyMovies(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate($this->file([
            'movies' => array_fill(0, ImportValidator::MAX_MOVIES + 1, ['title' => 'x']),
        ]));
    }

    public function testEmptyFileIsValid(): void {
        $r = $this->validator->validate($this->file());
        $this->assertSame([], $r['movies']);
        $this->assertSame([], $r['series']);
        $this->assertSame([], $r['watchlist']);
    }

    public function testMovieIsRebuiltFromKnownFieldsOnly(): void {
        $r = $this->validator->validate($this->file(['movies' => [[
            'id' => 99, 'userId' => 'mallory', 'libraryId' => 7, 'evil' => 'x',
            'tmdbId' => 603, 'title' => '  The Matrix ', 'releaseDate' => '1999-03-31',
            'posterPath' => '/abc.jpg', 'isFavorite' => true,
            'watches' => [['watchedAt' => '2024-01-02', 'rating' => 9, 'platform' => 'Netflix']],
        ]]]));

        $movie = $r['movies'][0];
        $this->assertSame('The Matrix', $movie['title']);
        $this->assertSame(603, $movie['tmdbId']);
        $this->assertSame(1999, $movie['releaseYear']);
        $this->assertTrue($movie['isFavorite']);
        foreach (['id', 'userId', 'libraryId', 'evil'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $movie);
        }
        $this->assertSame('netflix', strtolower($r['platforms'][0]['name']));
    }

    public function testMovieWithoutTitleIsSkippedAndCounted(): void {
        $r = $this->validator->validate($this->file(['movies' => [['tmdbId' => 1], 'junk', ['title' => 'Ok']]]));
        $this->assertCount(1, $r['movies']);
        $this->assertSame(2, $r['invalid']['movies']);
    }

    /**
     * @dataProvider badPathProvider
     */
    public function testMaliciousImagePathsAreDropped(string $path): void {
        $r = $this->validator->validate($this->file(['movies' => [['title' => 'A', 'posterPath' => $path]]]));
        $this->assertNull($r['movies'][0]['posterPath']);
    }

    public static function badPathProvider(): array {
        return [
            'traversal' => ['/../etc/passwd'],
            'url' => ['http://evil.example/x.jpg'],
            'markup' => ['<script>alert(1)</script>.jpg'],
            'subdir' => ['/a/b.jpg'],
            'bad extension' => ['/abc.php'],
        ];
    }

    public function testTextIsKeptAsPlainTextAndLengthCapped(): void {
        $r = $this->validator->validate($this->file(['movies' => [[
            'title' => str_repeat('a', 600),
            'director' => '<script>alert(1)</script>',
        ]]]));
        $this->assertSame(512, mb_strlen($r['movies'][0]['title']));
        $this->assertSame('<script>alert(1)</script>', $r['movies'][0]['director']);
    }

    /**
     * @dataProvider badRatingProvider
     */
    public function testWatchWithInvalidRatingIsDropped(mixed $rating): void {
        $r = $this->validator->validate($this->file(['movies' => [[
            'title' => 'A', 'watches' => [['watchedAt' => '2024-01-01', 'rating' => $rating]],
        ]]]));
        $this->assertSame([], $r['movies'][0]['watches']);
        $this->assertSame(1, $r['invalid']['watches']);
    }

    public static function badRatingProvider(): array {
        return [[0], [11], [-3], ['9'], [5.5]];
    }

    public function testNullRatingIsAllowed(): void {
        $r = $this->validator->validate($this->file(['movies' => [[
            'title' => 'A', 'watches' => [['watchedAt' => '2024-01-01', 'rating' => null]],
        ]]]));
        $this->assertCount(1, $r['movies'][0]['watches']);
        $this->assertNull($r['movies'][0]['watches'][0]['rating']);
    }

    public function testInvalidDatesBecomeNull(): void {
        $r = $this->validator->validate($this->file(['movies' => [[
            'title' => 'A', 'releaseDate' => '2024-02-31',
            'watches' => [['watchedAt' => 'yesterday']],
        ]]]));
        $this->assertNull($r['movies'][0]['releaseDate']);
        $this->assertNull($r['movies'][0]['watches'][0]['watchedAt']);
    }

    public function testSeriesWithEpisodesAndWatch(): void {
        $r = $this->validator->validate($this->file(['series' => [[
            'tmdbId' => 1396, 'title' => 'Breaking Bad', 'firstAirDate' => '2008-01-20',
            'watch' => ['rating' => 10, 'watchedAt' => '2024-05-01', 'platform' => 'Netflix'],
            'episodes' => [
                ['season' => 1, 'episode' => 1, 'name' => 'Pilot', 'watched' => true],
                ['season' => 'x', 'episode' => 2],
                ['season' => 1, 'episode' => 3, 'stillPath' => '/../x.jpg'],
            ],
        ]]]));

        $s = $r['series'][0];
        $this->assertSame(2008, $s['firstAirYear']);
        $this->assertSame(10, $s['watch']['rating']);
        $this->assertCount(2, $s['episodes']);
        $this->assertTrue($s['episodes'][0]['watched']);
        $this->assertNull($s['episodes'][1]['stillPath']);
        $this->assertSame(1, $r['invalid']['episodes']);
    }

    public function testWatchlistRejectsUnknownMediaType(): void {
        $r = $this->validator->validate($this->file(['watchlist' => [
            ['title' => 'A', 'mediaType' => 'series', 'priority' => 2],
            ['title' => 'B', 'mediaType' => 'tv'],
            ['title' => 'C'],
        ]]));
        $this->assertCount(2, $r['watchlist']);
        $this->assertSame('movie', $r['watchlist'][1]['mediaType']);
        $this->assertSame(1, $r['invalid']['watchlist']);
    }

    public function testWatchlistFallsBackToNowForBadAddedAt(): void {
        $r = $this->validator->validate($this->file(['watchlist' => [['title' => 'A', 'addedAt' => 'nope']]]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r['watchlist'][0]['addedAt']);
    }

    public function testPlatformsAreMergedFromListAndWatches(): void {
        $r = $this->validator->validate($this->file([
            'platforms' => [['name' => 'My Cinema', 'icon' => 'film']],
            'movies' => [['title' => 'A', 'watches' => [['platform' => 'my cinema'], ['platform' => 'Disney+']]]],
        ]));
        $names = array_column($r['platforms'], 'name');
        sort($names);
        $this->assertSame(['Disney+', 'My Cinema'], $names);
    }
}
