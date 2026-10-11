<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getLibraryId()
 * @method void setLibraryId(int $libraryId)
 * @method string getPlayedAt()
 * @method void setPlayedAt(string $playedAt)
 * @method int getQuestionCount()
 * @method void setQuestionCount(int $questionCount)
 * @method int getScore()
 * @method void setScore(int $score)
 * @method int getMaxScore()
 * @method void setMaxScore(int $maxScore)
 * @method int getPercent()
 * @method void setPercent(int $percent)
 * @method ?array getBreakdown()
 * @method void setBreakdown(?array $breakdown)
 */
class QuizResult extends Entity implements JsonSerializable {
    protected string $userId = '';
    protected int $libraryId = 0;
    protected string $playedAt = '';
    protected int $questionCount = 0;
    protected int $score = 0;
    protected int $maxScore = 0;
    protected int $percent = 0;
    protected ?array $breakdown = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('libraryId', 'integer');
        $this->addType('questionCount', 'integer');
        $this->addType('score', 'integer');
        $this->addType('maxScore', 'integer');
        $this->addType('percent', 'integer');
        $this->addType('breakdown', 'json');
    }

    public function jsonSerialize(): array {
        return [
            'id'            => $this->id,
            'libraryId'     => $this->libraryId,
            'playedAt'      => $this->playedAt,
            'questionCount' => $this->questionCount,
            'score'         => $this->score,
            'maxScore'      => $this->maxScore,
            'percent'       => $this->percent,
            'breakdown'     => $this->breakdown ?? [],
        ];
    }
}
