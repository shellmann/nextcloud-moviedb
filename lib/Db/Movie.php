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
 * @method string|null getReleaseDate()
 * @method void setReleaseDate(?string $releaseDate)
 * @method int|null getReleaseYear()
 * @method void setReleaseYear(?int $releaseYear)
 * @method int|null getRuntime()
 * @method void setRuntime(?int $runtime)
 * @method array|null getCastData()
 * @method void setCastData(?array $castData)
 * @method string|null getDirector()
 * @method void setDirector(?string $director)
 * @method string getMediaType()
 * @method void setMediaType(string $mediaType)
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
class Movie extends Entity implements JsonSerializable {
    protected string $userId = '';
    protected ?int $libraryId = null;
    protected ?int $tmdbId = null;
    protected string $title = '';
    protected ?string $originalTitle = null;
    protected ?string $posterPath = null;
    protected ?string $backdropPath = null;
    protected ?string $overview = null;
    protected ?array $genreIds = null;
    protected ?string $releaseDate = null;
    protected ?int $releaseYear = null;
    protected ?int $runtime = null;
    protected ?array $castData = null;
    protected ?string $director = null;
    protected string $mediaType = 'movie';
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
        $this->addType('releaseYear', 'integer');
        $this->addType('runtime', 'integer');
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
            'releaseDate' => $this->releaseDate,
            'releaseYear' => $this->releaseYear,
            'runtime' => $this->runtime,
            'castData' => $this->castData,
            'director' => $this->director,
            'mediaType' => $this->mediaType,
            'lastRating' => $this->lastRating,
            'lastWatchedAt' => $this->lastWatchedAt,
            'isFavorite' => $this->isFavorite,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
