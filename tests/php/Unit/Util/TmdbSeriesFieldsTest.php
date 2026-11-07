<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Util\TmdbSeriesFields;
use PHPUnit\Framework\TestCase;

class TmdbSeriesFieldsTest extends TestCase {
    public function testMapsTypeNetworksAndScore(): void {
        $r = TmdbSeriesFields::fromDetails([
            'type' => 'Scripted',
            'networks' => [['id' => 1, 'name' => 'Apple TV+', 'logo_path' => '/a.png']],
            'vote_average' => 8.449,
            'vote_count' => 321,
        ]);
        $this->assertSame('Scripted', $r['seriesType']);
        $this->assertSame([['name' => 'Apple TV+', 'logoPath' => '/a.png']], $r['networks']);
        $this->assertSame(84, $r['tmdbRating']);
        $this->assertSame(321, $r['tmdbVoteCount']);
    }

    public function testNoVotesMeansNoScore(): void {
        $r = TmdbSeriesFields::fromDetails(['vote_average' => 0, 'vote_count' => 0]);
        $this->assertNull($r['tmdbRating']);
        $this->assertSame(0, $r['tmdbVoteCount']);
    }

    public function testMissingDataGivesEmptyTypeAndNulls(): void {
        $r = TmdbSeriesFields::fromDetails([]);
        $this->assertSame('', $r['seriesType']);
        $this->assertNull($r['networks']);
        $this->assertNull($r['tmdbRating']);
        $this->assertNull($r['tmdbVoteCount']);
    }

    public function testNetworksAreCappedAndInvalidEntriesDropped(): void {
        $many = array_map(fn ($i) => ['name' => "N$i", 'logoPath' => null], range(1, 9));
        $clean = TmdbSeriesFields::sanitizeNetworks(array_merge([['name' => ' '], 'x', ['name' => 'Evil', 'logoPath' => 'http://x/y.png']], $many));
        $this->assertCount(5, $clean);
        $this->assertSame(['name' => 'Evil', 'logoPath' => null], $clean[0]);
        $this->assertNull(TmdbSeriesFields::sanitizeNetworks('nope'));
        $this->assertNull(TmdbSeriesFields::sanitizeNetworks([]));
    }
}
