<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int|null getMovieId()
 * @method void setMovieId(?int $movieId)
 * @method int|null getEpisodeId()
 * @method void setEpisodeId(?int $episodeId)
 * @method int|null getSeriesId()
 * @method void setSeriesId(?int $seriesId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getWatchedAt()
 * @method void setWatchedAt(?string $watchedAt)
 * @method int|null getRating()
 * @method void setRating(?int $rating)
 * @method string|null getReview()
 * @method void setReview(?string $review)
 * @method int|null getPlatformId()
 * @method void setPlatformId(?int $platformId)
 * @method string|null getLanguageWatched()
 * @method void setLanguageWatched(?string $languageWatched)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class MovieWatch extends Entity implements JsonSerializable {
    protected ?int $movieId = null;
    protected ?int $episodeId = null;
    protected ?int $seriesId = null;
    protected string $userId = '';
    protected ?string $watchedAt = null;
    protected ?int $rating = null;
    protected ?string $review = null;
    protected ?int $platformId = null;
    protected ?string $languageWatched = null;
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('movieId', 'integer');
        $this->addType('episodeId', 'integer');
        $this->addType('seriesId', 'integer');
        $this->addType('rating', 'integer');
        $this->addType('platformId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id'               => $this->id,
            'movieId'          => $this->movieId,
            'episodeId'        => $this->episodeId,
            'seriesId'         => $this->seriesId,
            'userId'           => $this->userId,
            'watchedAt'        => $this->watchedAt,
            'rating'           => $this->rating,
            'review'           => $this->review,
            'platformId'       => $this->platformId,
            'languageWatched'  => $this->languageWatched,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
        ];
    }
}
