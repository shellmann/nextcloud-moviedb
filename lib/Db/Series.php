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
 * @method string|null getOriginalTitle()
 * @method void setOriginalTitle(?string $originalTitle)
 * @method string|null getPosterPath()
 * @method void setPosterPath(?string $posterPath)
 * @method string|null getBackdropPath()
 * @method void setBackdropPath(?string $backdropPath)
 * @method string|null getOverview()
 * @method void setOverview(?string $overview)
 * @method array|null getGenreIds()
 * @method void setGenreIds(?array $genreIds)
 * @method string|null getFirstAirDate()
 * @method void setFirstAirDate(?string $firstAirDate)
 * @method int|null getFirstAirYear()
 * @method void setFirstAirYear(?int $firstAirYear)
 * @method int|null getNumberOfSeasons()
 * @method void setNumberOfSeasons(?int $numberOfSeasons)
 * @method int|null getNumberOfEpisodes()
 * @method void setNumberOfEpisodes(?int $numberOfEpisodes)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getCastData()
 * @method void setCastData(?array $castData)
 * @method string|null getDirector()
 * @method void setDirector(?string $director)
 * @method int|null getLastRating()
 * @method void setLastRating(?int $lastRating)
 * @method string|null getLastWatchedAt()
 * @method void setLastWatchedAt(?string $lastWatchedAt)
 * @method bool getIsFavorite()
 * @method void setIsFavorite(bool $isFavorite)
 * @method int|null getLibraryId()
 * @method void setLibraryId(?int $libraryId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Series extends Entity implements JsonSerializable {
    protected string $userId = '';
    protected ?int $libraryId = null;
    protected ?int $tmdbId = null;
    protected string $title = '';
    protected ?string $originalTitle = null;
    protected ?string $posterPath = null;
    protected ?string $backdropPath = null;
    protected ?string $overview = null;
    protected ?array $genreIds = null;
    protected ?string $firstAirDate = null;
    protected ?int $firstAirYear = null;
    protected ?int $numberOfSeasons = null;
    protected ?int $numberOfEpisodes = null;
    protected ?string $status = null;
    protected ?array $castData = null;
    protected ?string $director = null;
    protected ?int $lastRating = null;
    protected ?string $lastWatchedAt = null;
    protected bool $isFavorite = false;
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('libraryId', 'integer');
        $this->addType('tmdbId', 'integer');
        $this->addType('genreIds', 'json');
        $this->addType('firstAirYear', 'integer');
        $this->addType('numberOfSeasons', 'integer');
        $this->addType('numberOfEpisodes', 'integer');
        $this->addType('castData', 'json');
        $this->addType('lastRating', 'integer');
        $this->addType('isFavorite', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'libraryId' => $this->libraryId,
            'tmdbId' => $this->tmdbId,
            'title' => $this->title,
            'originalTitle' => $this->originalTitle,
            'posterPath' => $this->posterPath,
            'backdropPath' => $this->backdropPath,
            'overview' => $this->overview,
            'genreIds' => $this->genreIds,
            'firstAirDate' => $this->firstAirDate,
            'firstAirYear' => $this->firstAirYear,
            'numberOfSeasons' => $this->numberOfSeasons,
            'numberOfEpisodes' => $this->numberOfEpisodes,
            'status' => $this->status,
            'castData' => $this->castData,
            'director' => $this->director,
            'lastRating' => $this->lastRating,
            'lastWatchedAt' => $this->lastWatchedAt,
            'isFavorite' => $this->isFavorite,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
