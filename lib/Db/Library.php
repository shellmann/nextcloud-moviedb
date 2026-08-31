<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string getName()
 * @method void setName(string $name)
 * @method bool getIsPersonal()
 * @method void setIsPersonal(bool $isPersonal)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Library extends Entity implements JsonSerializable {
    protected string $owner = '';
    protected string $name = '';
    protected bool $isPersonal = false;
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('isPersonal', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id'         => $this->id,
            'owner'      => $this->owner,
            'name'       => $this->name,
            'isPersonal' => $this->isPersonal,
            'createdAt'  => $this->createdAt,
            'updatedAt'  => $this->updatedAt,
        ];
    }
}
