<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Unit tests for MovieWatchMapper's year/platform aggregation, verifying TV
 * show watches are now included (previously excluded via a movie_id filter
 * on getCountByPlatform, and structural exclusion via an inner join to
 * moviedb_movies on getCountByYear).
 */
class MovieWatchMapperTest extends TestCase {
    private MovieWatchMapper $mapper;
    private RecordingQBStub $qbStub;

    protected function setUp(): void {
        parent::setUp();

        $this->qbStub = new RecordingQBStub();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->qbStub);

        $this->mapper = new MovieWatchMapper($db);
    }

    public function testGetCountByPlatformDoesNotFilterOnMovieId(): void {
        $this->mapper->getCountByPlatform(1);

        $this->assertNotContains('movie_id', $this->qbStub->isNotNullCalls,
            'getCountByPlatform must not exclude rows with a null movie_id — that filter excluded TV series watches.');
    }

    public function testGetCountByPlatformStillRequiresPlatformId(): void {
        $this->mapper->getCountByPlatform(1);

        $this->assertContains('platform_id', $this->qbStub->isNotNullCalls);
    }

    public function testGetCountByYearJoinsSeriesTable(): void {
        $this->mapper->getCountByYear(1);

        $joinedTables = array_column($this->qbStub->leftJoinCalls, 1);
        $this->assertContains('moviedb_movies', $joinedTables);
        $this->assertContains('moviedb_series', $joinedTables,
            'getCountByYear must join moviedb_series so TV show watches contribute to the year breakdown.');
    }

    public function testGetCountByYearGroupsByCoalescedYear(): void {
        $this->mapper->getCountByYear(1);

        $this->assertNotEmpty($this->qbStub->createFunctionCalls);
        $this->assertStringContainsString('COALESCE', $this->qbStub->createFunctionCalls[0]);
        $this->assertStringContainsString('release_year', $this->qbStub->createFunctionCalls[0]);
        $this->assertStringContainsString('first_air_year', $this->qbStub->createFunctionCalls[0]);
    }

    public function testGetCountByPlatformWithMovieFilterRequiresMovieId(): void {
        $this->mapper->getCountByPlatform(1, 'movie');

        $this->assertContains('movie_id', $this->qbStub->isNotNullCalls);
        $this->assertNotContains('series_id', $this->qbStub->isNotNullCalls);
    }

    public function testGetCountByPlatformWithSeriesFilterRequiresSeriesIdAndNullEpisode(): void {
        $this->mapper->getCountByPlatform(1, 'series');

        $this->assertContains('series_id', $this->qbStub->isNotNullCalls);
        $this->assertContains('episode_id', $this->qbStub->isNullCalls);
        $this->assertNotContains('movie_id', $this->qbStub->isNotNullCalls);
    }

    public function testGetCountByPlatformWithNoFilterAppliesNeither(): void {
        $this->mapper->getCountByPlatform(1);

        $this->assertNotContains('movie_id', $this->qbStub->isNotNullCalls);
        $this->assertNotContains('series_id', $this->qbStub->isNotNullCalls);
    }

    public function testGetCountByYearWithMovieFilterRequiresMovieId(): void {
        $this->mapper->getCountByYear(1, 'movie');

        $this->assertContains('w.movie_id', $this->qbStub->isNotNullCalls);
    }

    public function testGetCountByYearWithSeriesFilterRequiresSeriesIdAndNullEpisode(): void {
        $this->mapper->getCountByYear(1, 'series');

        $this->assertContains('w.series_id', $this->qbStub->isNotNullCalls);
        $this->assertContains('w.episode_id', $this->qbStub->isNullCalls);
    }

    public function testGetCountByYearAggregatesFetchedRowsByCoalescedYear(): void {
        // Exercises the actual row-mapping loop, not just the query shape:
        // rows already grouped/counted by the (stubbed) SQL COALESCE come back
        // as {year, count} pairs, and getCountByYear must key the result array
        // by year (as a string) with the count cast to int.
        $this->qbStub->fetchRows = [
            ['year' => '2024', 'count' => '3'],
            ['year' => '2022', 'count' => '1'],
        ];

        $result = $this->mapper->getCountByYear(1);

        $this->assertSame(['2024' => 3, '2022' => 1], $result);
    }

    public function testGetCountByYearSkipsRowsWithNullYear(): void {
        // A watch row that matches neither the movie nor the series LEFT JOIN
        // (movie_id and series_id both null, or a deleted parent) surfaces a
        // null COALESCE result — must be dropped, not counted under a "" key.
        $this->qbStub->fetchRows = [
            ['year' => '2024', 'count' => '2'],
            ['year' => null, 'count' => '5'],
        ];

        $result = $this->mapper->getCountByYear(1);

        $this->assertSame(['2024' => 2], $result);
    }

    public function testGetCountByYearReturnsEmptyArrayWhenNoRows(): void {
        $this->qbStub->fetchRows = [];

        $result = $this->mapper->getCountByYear(1);

        $this->assertSame([], $result);
    }

    public function testGetCountByPlatformAggregatesFetchedRows(): void {
        $this->qbStub->fetchRows = [
            ['platform_id' => '1', 'count' => '4'],
            ['platform_id' => '2', 'count' => '2'],
        ];

        $result = $this->mapper->getCountByPlatform(1);

        $this->assertSame([1 => 4, 2 => 2], $result);
    }
}

/**
 * Fluent QueryBuilder stub that satisfies the IQueryBuilder typehint and
 * records the calls these tests assert on.
 */
class RecordingQBStub implements IQueryBuilder {
    public array $isNotNullCalls = [];
    public array $isNullCalls = [];
    public array $leftJoinCalls = [];
    public array $createFunctionCalls = [];

    /**
     * Rows to hand back from executeQuery()->fetch(), one per call, in order.
     * Defaults to empty (no rows) when a test doesn't care about aggregation.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $fetchRows = [];

    public function select(...$_): static { return $this; }
    public function addSelect(...$_): static { return $this; }
    public function from(string $table, ?string $alias = null): static { return $this; }
    public function where(string $_): static { return $this; }
    public function andWhere(string $_): static { return $this; }
    public function orWhere(string $_): static { return $this; }
    public function orderBy(string $sort, ?string $order = null): static { return $this; }
    public function setMaxResults(?int $_): static { return $this; }
    public function setFirstResult(int $_): static { return $this; }

    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static {
        $this->leftJoinCalls[] = [$fromAlias, $join, $alias, $condition];
        return $this;
    }

    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static { return $this; }
    public function groupBy(...$_): static { return $this; }
    public function selectAlias(mixed $select, string $alias): static { return $this; }
    public function selectDistinct(string $_): static { return $this; }

    public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string {
        return (string)$value;
    }

    public function createFunction(string $call): string {
        $this->createFunctionCalls[] = $call;
        return $call;
    }

    public function getSQL(): string { return 'SELECT 1'; }

    public function executeQuery(): mixed {
        $rows = $this->fetchRows;
        return new class ($rows) {
            private array $rows;

            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            public function fetch(): mixed {
                return array_shift($this->rows) ?? false;
            }

            public function closeCursor(): bool { return true; }
        };
    }

    public function func(): mixed {
        return new class {
            public function count(...$_): string { return 'COUNT(*)'; }
            public function max(...$_): string { return 'MAX(x)'; }
        };
    }

    public function expr(): mixed {
        return new class ($this) {
            private RecordingQBStub $stub;

            public function __construct(RecordingQBStub $stub) {
                $this->stub = $stub;
            }

            public function eq(...$_): string { return '1=1'; }
            public function andX(...$_): string { return '1=1'; }
            public function orX(...$_): string { return '1=1'; }
            public function in(...$_): string { return '1=1'; }

            public function isNotNull(string $column): string {
                $this->stub->isNotNullCalls[] = $column;
                return '1=1';
            }

            public function isNull(string $column): string {
                $this->stub->isNullCalls[] = $column;
                return '1=1';
            }
        };
    }
}
