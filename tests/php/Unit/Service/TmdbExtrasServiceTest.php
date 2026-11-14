<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\TmdbExtrasService;
use OCA\MovieDB\Service\TmdbExtrasUnavailableException;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\L10N\IFactory;

class TmdbExtrasServiceTest extends TestCase {
    private TmdbService $tmdb;
    private ICache $cache;
    private ICacheFactory $cacheFactory;
    private IFactory $l10nFactory;
    private ?TmdbExtrasService $service = null;

    private const EXTRAS = ['trailer' => null, 'watch' => ['region' => 'DE', 'link' => null, 'stream' => [], 'free' => [], 'rent' => [], 'buy' => []]];

    protected function setUp(): void {
        parent::setUp();
        $this->tmdb = $this->createStub(TmdbService::class);
        $this->cache = $this->createStub(ICache::class);
        $this->cacheFactory = $this->createStub(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturnCallback(fn () => $this->cache);
        $this->l10nFactory = $this->createStub(IFactory::class);
        $this->l10nFactory->method('findLocale')->willReturn('en_US');
    }

    private function service(): TmdbExtrasService {
        return $this->service ??= new TmdbExtrasService($this->tmdb, $this->cacheFactory, $this->l10nFactory);
    }

    public function testMissWithoutCacheFetchesAndStores(): void {
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->tmdb->expects($this->once())->method('getExtras')
            ->with('tv', 42, 'alice', 'de-DE', 'DE')->willReturn(self::EXTRAS);
        $this->cache = $this->createMock(ICache::class);
        $this->cache->method('get')->with('extras-tv-42-de-DE-DE')->willReturn(null);
        $this->cache->expects($this->once())->method('set')
            ->with('extras-tv-42-de-DE-DE', self::EXTRAS, TmdbExtrasService::TTL);

        $this->assertSame(self::EXTRAS, $this->service()->get('tv', 42, 'alice', 'de-DE'));
    }

    public function testHitDoesNotCallTmdb(): void {
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->tmdb->expects($this->never())->method('getExtras');
        $this->cache->method('get')->willReturn(self::EXTRAS);

        $this->assertSame(self::EXTRAS, $this->service()->get('movie', 7, 'alice', 'en-US'));
    }

    public function testBrokenCacheEntryIsIgnored(): void {
        $this->cache->method('get')->willReturn('garbage');
        $this->tmdb->method('getExtras')->willReturn(self::EXTRAS);

        $this->assertSame(self::EXTRAS, $this->service()->get('movie', 7, 'alice', 'en-US'));
    }

    public function testRegionFollowsLanguageThenLocale(): void {
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->tmdb->expects($this->once())->method('getExtras')
            ->with('movie', 1, null, 'de', 'US')->willReturn(self::EXTRAS);
        $this->cache->method('get')->willReturn(null);

        $this->service()->get('movie', 1, null, 'de');
    }

    public function testFailureIsRememberedForFiveMinutes(): void {
        $this->tmdb->method('getExtras')->willThrowException(new \RuntimeException('boom'));
        $this->cache = $this->createMock(ICache::class);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('set')
            ->with('extras-movie-1-en-US-US', ['failed' => true], TmdbExtrasService::FAILURE_TTL);

        $this->expectException(\RuntimeException::class);
        $this->service()->get('movie', 1, 'alice', 'en-US');
    }

    public function testRememberedFailureDoesNotCallTmdb(): void {
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->tmdb->expects($this->never())->method('getExtras');
        $this->cache->method('get')->willReturn(['failed' => true]);

        $this->expectException(TmdbExtrasUnavailableException::class);
        $this->service()->get('movie', 1, 'alice', 'en-US');
    }
}
