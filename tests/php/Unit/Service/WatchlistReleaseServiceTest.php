<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Service\WatchlistReleaseService;
use OCA\MovieDB\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class WatchlistReleaseServiceTest extends TestCase {
    private WatchlistMapper&MockObject $mapper;
    private TmdbService&MockObject $tmdb;
    private WatchlistReleaseService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = $this->createMock(WatchlistMapper::class);
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->service = new WatchlistReleaseService($this->mapper, $this->tmdb);
    }

    private function item(?string $releaseDate, string $mediaType = 'movie', ?int $tmdbId = 42): WatchlistItem {
        $i = new WatchlistItem();
        $i->setId(1);
        $i->setTmdbId($tmdbId);
        $i->setMediaType($mediaType);
        $i->setReleaseDate($releaseDate);
        return $i;
    }

    public function testTakesOverANewDateAndMarksItAsNews(): void {
        $item = $this->item(null);
        $this->tmdb->method('getMovieDetails')->with(42, 'owner', 'de-DE')->willReturn(['release_date' => '2027-03-12']);
        $this->mapper->expects($this->once())->method('update')->with($item)->willReturnArgument(0);

        $this->assertTrue($this->service->refresh($item, 'owner', 'de-DE'));
        $this->assertSame('2027-03-12', $item->getReleaseDate());
        $this->assertTrue($item->hasReleaseDateNews());
    }

    public function testTakesOverAMovedDate(): void {
        $item = $this->item('2027-03-12');
        $this->tmdb->method('getMovieDetails')->willReturn(['release_date' => '2027-06-01']);
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $this->assertTrue($this->service->refresh($item, 'owner', 'de-DE'));
        $this->assertSame('2027-06-01', $item->getReleaseDate());
        $this->assertTrue($item->hasReleaseDateNews());
    }

    public function testDoesNothingWhenTheDateIsTheSame(): void {
        $item = $this->item('2027-03-12');
        $this->tmdb->method('getMovieDetails')->willReturn(['release_date' => '2027-03-12']);
        $this->mapper->expects($this->never())->method('update');

        $this->assertFalse($this->service->refresh($item, 'owner', 'de-DE'));
        $this->assertFalse($item->hasReleaseDateNews());
    }

    /**
     * @dataProvider noDateProvider
     */
    public function testKeepsTheStoredDateWhenTmdbHasNone(array $details): void {
        $item = $this->item('2027-03-12');
        $this->tmdb->method('getMovieDetails')->willReturn($details);
        $this->mapper->expects($this->never())->method('update');

        $this->assertFalse($this->service->refresh($item, 'owner', 'de-DE'));
        $this->assertSame('2027-03-12', $item->getReleaseDate());
    }

    public static function noDateProvider(): array {
        return [
            'empty string' => [['release_date' => '']],
            'missing' => [[]],
            'garbage' => [['release_date' => 'soon']],
        ];
    }

    public function testUsesTheFirstAirDateForSeries(): void {
        $item = $this->item(null, 'series');
        $this->tmdb->expects($this->never())->method('getMovieDetails');
        $this->tmdb->method('getSeriesDetails')->with(42, 'owner', 'de-DE')->willReturn(['first_air_date' => '2027-01-15']);
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $this->assertTrue($this->service->refresh($item, 'owner', 'de-DE'));
        $this->assertSame('2027-01-15', $item->getReleaseDate());
    }

    public function testIgnoresItemsWithoutTmdbId(): void {
        $this->tmdb->expects($this->never())->method('getMovieDetails');

        $this->assertFalse($this->service->refresh($this->item(null, 'movie', null), 'owner', 'de-DE'));
    }
}
