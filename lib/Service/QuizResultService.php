<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\QuizResult;
use OCA\MovieDB\Db\QuizResultMapper;

/**
 * Stores finished quiz rounds and reads them back for the history view.
 *
 * The client scores the round, so the server only checks that the numbers are
 * internally consistent (shape, ranges, sums) before saving them.
 */
class QuizResultService {
    public const POINTS_PER_QUESTION = 10;
    private const HISTORY_ROUNDS = 30;

    public function __construct(private QuizResultMapper $mapper) {
    }

    /**
     * @return array{rounds: list<QuizResult>, best: ?QuizResult, accuracy: array<string, int>}
     */
    public function list(string $userId, int $libraryId): array {
        // Newest first from the DB; the chart wants oldest first.
        $rounds = array_reverse($this->mapper->findRecent($userId, $libraryId, self::HISTORY_ROUNDS));

        $points = [];
        $max = [];
        foreach ($rounds as $round) {
            foreach ($round->getBreakdown() ?? [] as $type => $entry) {
                $points[$type] = ($points[$type] ?? 0) + (int)($entry['points'] ?? 0);
                $max[$type] = ($max[$type] ?? 0) + (int)($entry['max'] ?? 0);
            }
        }
        $accuracy = [];
        foreach ($max as $type => $total) {
            if ($total > 0) {
                $accuracy[$type] = (int)round($points[$type] * 100 / $total);
            }
        }

        return [
            'rounds' => $rounds,
            'best' => $this->mapper->findBest($userId, $libraryId),
            'accuracy' => $accuracy,
        ];
    }

    /**
     * @param array<string, mixed> $data questionCount, score, maxScore, breakdown
     * @throws \InvalidArgumentException when the numbers are not consistent
     */
    public function create(string $userId, int $libraryId, array $data): QuizResult {
        $questionCount = $data['questionCount'] ?? null;
        $score = $data['score'] ?? null;
        $maxScore = $data['maxScore'] ?? null;
        $breakdown = $data['breakdown'] ?? null;

        if (!is_int($questionCount) || $questionCount < 1 || $questionCount > QuizService::MAX_QUESTIONS) {
            throw new \InvalidArgumentException('Invalid question count.');
        }
        if (!is_int($maxScore) || $maxScore !== $questionCount * self::POINTS_PER_QUESTION) {
            throw new \InvalidArgumentException('Invalid maximum score.');
        }
        if (!is_int($score) || $score < 0 || $score > $maxScore) {
            throw new \InvalidArgumentException('Invalid score.');
        }
        if (!is_array($breakdown) || $breakdown === []) {
            throw new \InvalidArgumentException('Invalid breakdown.');
        }

        $sumPoints = 0;
        $sumMax = 0;
        $clean = [];
        foreach ($breakdown as $type => $entry) {
            if (!is_string($type) || !in_array($type, QuizService::TYPES, true) || !is_array($entry)) {
                throw new \InvalidArgumentException('Invalid breakdown.');
            }
            $points = $entry['points'] ?? null;
            $entryMax = $entry['max'] ?? null;
            if (!is_int($points) || !is_int($entryMax) || $entryMax < 1 || $points < 0 || $points > $entryMax) {
                throw new \InvalidArgumentException('Invalid breakdown.');
            }
            $sumPoints += $points;
            $sumMax += $entryMax;
            $clean[$type] = ['points' => $points, 'max' => $entryMax];
        }
        if ($sumPoints !== $score || $sumMax !== $maxScore) {
            throw new \InvalidArgumentException('Breakdown does not match the score.');
        }

        $result = new QuizResult();
        $result->setUserId($userId);
        $result->setLibraryId($libraryId);
        $result->setPlayedAt((new DateTime())->format('Y-m-d H:i:s'));
        $result->setQuestionCount($questionCount);
        $result->setScore($score);
        $result->setMaxScore($maxScore);
        $result->setPercent((int)round($score * 100 / $maxScore));
        $result->setBreakdown($clean);

        return $this->mapper->insert($result);
    }

    /**
     * Delete the user's own results in one library. Returns how many were removed.
     */
    public function reset(string $userId, int $libraryId): int {
        return $this->mapper->deleteByUserAndLibrary($userId, $libraryId);
    }
}
