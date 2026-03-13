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
 * @method int|null getPlatformId()
 * @method void setPlatformId(?int $platformId)
 * @method string|null getLanguageWatched()
 * @method void setLanguageWatched(?string $languageWatched)
 * @method string|null getDateWatched()
 * @method void setDateWatched(?string $dateWatched)
 * @method int|null getRating()
 * @method void setRating(?int $rating)
 * @method string|null getReview()
 * @method void setReview(?string $review)
 * @method bool getIsFavorite()
 * @method void setIsFavorite(bool $isFavorite)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Movie extends Entity implements JsonSerializable {
    protected string $userId = '';
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
    protected ?int $platformId = null;
    protected ?string $languageWatched = null;
    protected ?string $dateWatched = null;
    protected ?int $rating = null;
    protected ?string $review = null;
    protected bool $isFavorite = false;
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('tmdbId', 'integer');
        $this->addType('genreIds', 'json');
        $this->addType('releaseYear', 'integer');
        $this->addType('runtime', 'integer');
        $this->addType('castData', 'json');
        $this->addType('platformId', 'integer');
        $this->addType('rating', 'integer');
        $this->addType('isFavorite', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
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
            'platformId' => $this->platformId,
            'languageWatched' => $this->languageWatched,
            'dateWatched' => $this->dateWatched,
            'rating' => $this->rating,
            'review' => $this->review,
            'isFavorite' => $this->isFavorite,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
