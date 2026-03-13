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
 * @method int|null getTmdbId()
 * @method void setTmdbId(?int $tmdbId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getPosterPath()
 * @method void setPosterPath(?string $posterPath)
 * @method string|null getOverview()
 * @method void setOverview(?string $overview)
 * @method array|null getGenreIds()
 * @method void setGenreIds(?array $genreIds)
 * @method string|null getReleaseDate()
 * @method void setReleaseDate(?string $releaseDate)
 * @method string getAddedAt()
 * @method void setAddedAt(string $addedAt)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 */
class WatchlistItem extends Entity implements JsonSerializable {
    protected string $userId = '';
    protected ?int $tmdbId = null;
    protected string $title = '';
    protected ?string $posterPath = null;
    protected ?string $overview = null;
    protected ?array $genreIds = null;
    protected ?string $releaseDate = null;
    protected string $addedAt = '';
    protected int $priority = 0;
    protected ?string $notes = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('tmdbId', 'integer');
        $this->addType('genreIds', 'json');
        $this->addType('priority', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'tmdbId' => $this->tmdbId,
            'title' => $this->title,
            'posterPath' => $this->posterPath,
            'overview' => $this->overview,
            'genreIds' => $this->genreIds,
            'releaseDate' => $this->releaseDate,
            'addedAt' => $this->addedAt,
            'priority' => $this->priority,
            'notes' => $this->notes,
        ];
    }
}
