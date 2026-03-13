<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getIcon()
 * @method void setIcon(?string $icon)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Platform extends Entity implements JsonSerializable {
    protected ?string $userId = null;
    protected string $name = '';
    protected ?string $icon = null;
    protected bool $isDefault = false;
    protected string $createdAt = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('isDefault', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'name' => $this->name,
            'icon' => $this->icon,
            'isDefault' => $this->isDefault,
            'createdAt' => $this->createdAt,
        ];
    }
}
