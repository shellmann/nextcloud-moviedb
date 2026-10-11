<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\QuizResult;
use OCA\MovieDB\Db\QuizResultMapper;
use OCA\MovieDB\Service\QuizResultService;
use OCA\MovieDB\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class QuizResultServiceTest extends TestCase {

    private QuizResultMapper&MockObject $mapper;
    private QuizResultService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = $this->createMock(QuizResultMapper::class);
        $this->mapper->method('insert')->willReturnArgument(0);
        $this->service = new QuizResultService($this->mapper);
    }

    private function valid(array $override = []): array {
        return array_merge([
            'questionCount' => 2,
            'score' => 15,
            'maxScore' => 20,
            'breakdown' => [
                'release_year' => ['points' => 10, 'max' => 10],
                'rating' => ['points' => 5, 'max' => 10],
            ],
        ], $override);
    }

    public function testCreateStoresRoundWithServerSidePercentAndTimestamp(): void {
        $result = $this->service->create('alice', 7, $this->valid());

        $this->assertSame('alice', $result->getUserId());
        $this->assertSame(7, $result->getLibraryId());
        $this->assertSame(75, $result->getPercent());
        $this->assertSame(15, $result->getScore());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result->getPlayedAt());
        $this->assertSame(['points' => 5, 'max' => 10], $result->getBreakdown()['rating']);
    }

    public function testZeroPointRoundStillWritesScoreAndPercent(): void {
        // Regression: score 0 / percent 0 equal the property defaults, so the
        // Entity skipped them in the INSERT and MariaDB rejected the row
        // ("Field 'score' doesn't have a default value").
        $result = $this->service->create('alice', 7, $this->valid([
            'questionCount' => 1, 'score' => 0, 'maxScore' => 10,
            'breakdown' => ['rating' => ['points' => 0, 'max' => 10]],
        ]));

        $this->assertSame(0, $result->getScore());
        $this->assertSame(0, $result->getPercent());
        $updated = array_keys($result->getUpdatedFields());
        foreach (['userId', 'libraryId', 'playedAt', 'questionCount', 'score', 'maxScore', 'percent'] as $field) {
            $this->assertContains($field, $updated, "$field must be part of the INSERT");
        }
    }

    public function testPercentIsRounded(): void {
        $result = $this->service->create('alice', 7, $this->valid([
            'questionCount' => 3, 'score' => 20, 'maxScore' => 30,
            'breakdown' => ['rating' => ['points' => 20, 'max' => 30]],
        ]));

        $this->assertSame(67, $result->getPercent());
    }

    public static function invalidProvider(): array {
        $b = fn (array $o = []) => array_merge(['release_year' => ['points' => 10, 'max' => 10], 'rating' => ['points' => 5, 'max' => 10]], $o);
        return [
            'zero questions' => [['questionCount' => 0, 'maxScore' => 0, 'score' => 0]],
            'too many questions' => [['questionCount' => 101, 'maxScore' => 1010]],
            'string question count' => [['questionCount' => '2']],
            'null question count' => [['questionCount' => null]],
            'wrong max score' => [['maxScore' => 25]],
            'string score' => [['score' => '15']],
            'negative score' => [['score' => -1]],
            'score above max' => [['score' => 21]],
            'missing breakdown' => [['breakdown' => null]],
            'empty breakdown' => [['breakdown' => []]],
            'unknown type' => [['breakdown' => ['trivia' => ['points' => 15, 'max' => 20]]]],
            'list instead of map' => [['breakdown' => [['points' => 15, 'max' => 20]]]],
            'points above max' => [['breakdown' => ['release_year' => ['points' => 11, 'max' => 10], 'rating' => ['points' => 4, 'max' => 10]]]],
            'float points' => [['breakdown' => ['release_year' => ['points' => 10.5, 'max' => 10], 'rating' => ['points' => 4.5, 'max' => 10]]]],
            'points do not sum to score' => [['breakdown' => ['release_year' => ['points' => 10, 'max' => 10], 'rating' => ['points' => 6, 'max' => 10]]]],
            'max does not sum to maxScore' => [['breakdown' => ['release_year' => ['points' => 15, 'max' => 20], 'rating' => ['points' => 0, 'max' => 10]]]],
            'zero max entry' => [['breakdown' => ['release_year' => ['points' => 15, 'max' => 20], 'rating' => ['points' => 0, 'max' => 0]]]],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidRoundsAreRejectedAndNotStored(array $override): void {
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('alice', 7, $this->valid($override));
    }

    public function testResetDeletesOnlyTheUsersRoundsInThatLibrary(): void {
        $this->mapper->expects($this->once())->method('deleteByUserAndLibrary')->with('alice', 7)->willReturn(4);

        $this->assertSame(4, $this->service->reset('alice', 7));
    }

    private function round(int $id, array $breakdown): QuizResult {
        $r = new QuizResult();
        $r->setId($id);
        $r->setBreakdown($breakdown);
        return $r;
    }

    public function testListReturnsRoundsOldestFirstBestAndAccuracy(): void {
        $newest = $this->round(2, ['release_year' => ['points' => 10, 'max' => 10], 'rating' => ['points' => 0, 'max' => 10]]);
        $oldest = $this->round(1, ['release_year' => ['points' => 0, 'max' => 10]]);
        $this->mapper->method('findRecent')->with('alice', 7, 30)->willReturn([$newest, $oldest]);
        $this->mapper->method('findBest')->with('alice', 7)->willReturn($newest);
        $this->mapper->method('countByUserAndLibrary')->with('alice', 7)->willReturn(58);

        $data = $this->service->list('alice', 7);

        $this->assertSame([1, 2], array_map(fn ($r) => $r->getId(), $data['rounds']));
        $this->assertSame($newest, $data['best']);
        $this->assertSame(58, $data['total'], 'total counts all rounds, not just the loaded ones');
        $this->assertSame(['release_year' => 50, 'rating' => 0], $data['accuracy']);
    }

    public function testListWithoutRoundsIsEmpty(): void {
        $this->mapper->method('findRecent')->willReturn([]);
        $this->mapper->method('findBest')->willReturn(null);

        $this->assertSame(['rounds' => [], 'total' => 0, 'best' => null, 'accuracy' => []], $this->service->list('alice', 7));
    }
}
