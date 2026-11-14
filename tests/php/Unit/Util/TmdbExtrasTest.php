<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Tests\Unit\TestCase;
use OCA\MovieDB\Util\TmdbExtras;
use PHPUnit\Framework\Attributes\DataProvider;

class TmdbExtrasTest extends TestCase {
    private function video(array $overrides = []): array {
        return $overrides + [
            'site' => 'YouTube',
            'type' => 'Trailer',
            'key' => 'abc123',
            'name' => 'Official Trailer',
            'iso_639_1' => 'en',
            'official' => true,
            'published_at' => '2026-01-01T00:00:00.000Z',
        ];
    }

    public function testNoVideosGivesNoTrailer(): void {
        $this->assertNull(TmdbExtras::pickTrailer([], 'de-DE'));
    }

    public function testBuildsYouTubeAndVimeoUrls(): void {
        $this->assertSame(
            ['site' => 'YouTube', 'key' => 'abc123', 'name' => 'Official Trailer', 'url' => 'https://www.youtube.com/watch?v=abc123'],
            TmdbExtras::pickTrailer([$this->video()], 'en-US'),
        );
        $this->assertSame(
            'https://vimeo.com/76979871',
            TmdbExtras::pickTrailer([$this->video(['site' => 'Vimeo', 'key' => '76979871'])], 'en-US')['url'],
        );
    }

    public function testPrefersUserLanguageOverEnglish(): void {
        $picked = TmdbExtras::pickTrailer([
            $this->video(['key' => 'enkey']),
            $this->video(['key' => 'dekey', 'iso_639_1' => 'de']),
        ], 'de-DE');

        $this->assertSame('dekey', $picked['key']);
    }

    public function testFallsBackToEnglishThenAnyLanguage(): void {
        $this->assertSame('enkey', TmdbExtras::pickTrailer([
            $this->video(['key' => 'frkey', 'iso_639_1' => 'fr']),
            $this->video(['key' => 'enkey']),
        ], 'de-DE')['key']);
        $this->assertSame('frkey', TmdbExtras::pickTrailer([
            $this->video(['key' => 'frkey', 'iso_639_1' => 'fr']),
        ], 'de-DE')['key']);
    }

    public function testTrailerBeatsTeaserEvenInOtherLanguage(): void {
        $picked = TmdbExtras::pickTrailer([
            $this->video(['key' => 'teaser', 'type' => 'Teaser', 'iso_639_1' => 'de']),
            $this->video(['key' => 'trailer']),
        ], 'de-DE');

        $this->assertSame('trailer', $picked['key']);
    }

    public function testTeaserIsBetterThanNothing(): void {
        $this->assertSame('teaser', TmdbExtras::pickTrailer([
            $this->video(['key' => 'teaser', 'type' => 'Teaser']),
            $this->video(['key' => 'clip', 'type' => 'Clip']),
        ], 'en-US')['key']);
    }

    public function testOfficialThenNewestWins(): void {
        $this->assertSame('official', TmdbExtras::pickTrailer([
            $this->video(['key' => 'fan', 'official' => false, 'published_at' => '2026-05-01T00:00:00.000Z']),
            $this->video(['key' => 'official']),
        ], 'en-US')['key']);
        $this->assertSame('newer', TmdbExtras::pickTrailer([
            $this->video(['key' => 'older']),
            $this->video(['key' => 'newer', 'published_at' => '2026-03-01T00:00:00.000Z']),
        ], 'en-US')['key']);
    }

    public static function unusableVideos(): array {
        return [
            'other site' => [['site' => 'Dailymotion']],
            'clip' => [['type' => 'Clip']],
            'key with markup' => [['key' => '"><script>']],
            'key too long' => [['key' => str_repeat('a', 33)]],
            'key missing' => [['key' => null]],
        ];
    }

    #[DataProvider('unusableVideos')]
    public function testIgnoresUnusableVideos(array $overrides): void {
        $this->assertNull(TmdbExtras::pickTrailer([$this->video($overrides), 'garbage', null], 'en-US'));
    }

    public function testMapsProvidersOfTheRegion(): void {
        $mapped = TmdbExtras::mapProviders([
            'DE' => [
                'link' => 'https://www.themoviedb.org/movie/1/watch?locale=DE',
                'flatrate' => [
                    ['provider_id' => 8, 'provider_name' => 'Netflix', 'logo_path' => '/n.jpg', 'display_priority' => 5],
                    ['provider_id' => 9, 'provider_name' => 'Prime Video', 'logo_path' => '/p.jpg', 'display_priority' => 1],
                ],
                'free' => [['provider_id' => 20, 'provider_name' => 'ARD', 'logo_path' => '/a.png', 'display_priority' => 3]],
                'ads' => [
                    ['provider_id' => 20, 'provider_name' => 'ARD', 'logo_path' => '/a.png', 'display_priority' => 3],
                    ['provider_id' => 21, 'provider_name' => 'Pluto TV', 'logo_path' => '/pl.jpg', 'display_priority' => 9],
                ],
                'rent' => [['provider_id' => 2, 'provider_name' => 'Apple TV', 'logo_path' => '/ap.jpg']],
                'buy' => [],
            ],
            'US' => ['flatrate' => [['provider_id' => 1, 'provider_name' => 'Hulu', 'logo_path' => '/h.jpg']]],
        ], 'DE');

        $this->assertSame('DE', $mapped['region']);
        $this->assertSame('https://www.themoviedb.org/movie/1/watch?locale=DE', $mapped['link']);
        $this->assertSame(['Prime Video', 'Netflix'], array_column($mapped['stream'], 'name'));
        $this->assertSame(['ARD', 'Pluto TV'], array_column($mapped['free'], 'name'));
        $this->assertSame(['id' => 2, 'name' => 'Apple TV', 'logoPath' => '/ap.jpg'], $mapped['rent'][0]);
        $this->assertSame([], $mapped['buy']);
    }

    public function testRegionWithoutOffersGivesEmptyGroups(): void {
        $this->assertSame(
            ['region' => 'FR', 'link' => null, 'stream' => [], 'free' => [], 'rent' => [], 'buy' => []],
            TmdbExtras::mapProviders(['DE' => ['flatrate' => [['provider_id' => 8, 'provider_name' => 'Netflix']]]], 'FR'),
        );
    }

    public function testRejectsForeignLinksBadLogosAndInvalidProviders(): void {
        $mapped = TmdbExtras::mapProviders(['DE' => [
            'link' => 'https://evil.example/watch',
            'flatrate' => [
                ['provider_id' => 8, 'provider_name' => 'Netflix', 'logo_path' => 'https://evil.example/x.jpg'],
                ['provider_id' => 'x', 'provider_name' => 'No id'],
                ['provider_id' => 10, 'provider_name' => '   '],
                'garbage',
            ],
        ]], 'DE');

        $this->assertNull($mapped['link']);
        $this->assertSame([['id' => 8, 'name' => 'Netflix', 'logoPath' => null]], $mapped['stream']);
    }

    public function testCapsEachGroup(): void {
        $many = [];
        for ($i = 1; $i <= 15; $i++) {
            $many[] = ['provider_id' => $i, 'provider_name' => 'P' . $i, 'display_priority' => $i];
        }

        $mapped = TmdbExtras::mapProviders(['DE' => ['flatrate' => $many]], 'DE');

        $this->assertCount(TmdbExtras::MAX_PROVIDERS, $mapped['stream']);
        $this->assertSame('P1', $mapped['stream'][0]['name']);
    }
}
