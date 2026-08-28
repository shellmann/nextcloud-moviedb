<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getSeriesId()
 * @method void setSeriesId(int $seriesId)
 * @method int|null getTmdbId()
 * @method void setTmdbId(?int $tmdbId)
 * @method int getSeasonNumber()
 * @method void setSeasonNumber(int $seasonNumber)
 * @method int getEpisodeNumber()
 * @method void setEpisodeNumber(int $episodeNumber)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getOverview()
 * @method void setOverview(?string $overview)
 * @method string|null getAirDate()
 * @method void setAirDate(?string $airDate)
 * @method int|null getRuntime()
 * @method void setRuntime(?int $runtime)
 * @method string|null getStillPath()
 * @method void setStillPath(?string $stillPath)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Episode extends Entity implements JsonSerializable {
    protected int $seriesId = 0;
    protected ?int $tmdbId = null;
    protected int $seasonNumber = 0;
    protected int $episodeNumber = 0;
    protected string $name = '';
    protected ?string $overview = null;
    protected ?string $airDate = null;
    protected ?int $runtime = null;
    protected ?string $stillPath = null;
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('seriesId', 'integer');
        $this->addType('tmdbId', 'integer');
        $this->addType('seasonNumber', 'integer');
        $this->addType('episodeNumber', 'integer');
        $this->addType('runtime', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'seriesId' => $this->seriesId,
            'tmdbId' => $this->tmdbId,
            'seasonNumber' => $this->seasonNumber,
            'episodeNumber' => $this->episodeNumber,
            'name' => $this->name,
            'overview' => $this->overview,
            'airDate' => $this->airDate,
            'runtime' => $this->runtime,
            'stillPath' => $this->stillPath,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
