<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\QuizResult;
use OCA\MovieDB\Tests\Unit\TestCase;

class QuizResultTest extends TestCase {

    public function testJsonSerializeExposesRoundWithoutUserId(): void {
        $result = new QuizResult();
        $result->setId(3);
        $result->setUserId('alice');
        $result->setLibraryId(7);
        $result->setPlayedAt('2026-10-11 12:00:00');
        $result->setQuestionCount(2);
        $result->setScore(15);
        $result->setMaxScore(20);
        $result->setPercent(75);
        $result->setBreakdown(['release_year' => ['points' => 10, 'max' => 10]]);

        $json = $result->jsonSerialize();

        $this->assertSame(
            ['id', 'libraryId', 'playedAt', 'questionCount', 'score', 'maxScore', 'percent', 'breakdown'],
            array_keys($json)
        );
        $this->assertSame(75, $json['percent']);
        $this->assertSame(['release_year' => ['points' => 10, 'max' => 10]], $json['breakdown']);
    }

    public function testMissingBreakdownSerializesAsEmptyArray(): void {
        $this->assertSame([], (new QuizResult())->jsonSerialize()['breakdown']);
    }
}
