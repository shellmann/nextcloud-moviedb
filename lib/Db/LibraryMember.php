<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getLibraryId()
 * @method void setLibraryId(int $libraryId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method bool getPermissionEdit()
 * @method void setPermissionEdit(bool $permissionEdit)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class LibraryMember extends Entity implements JsonSerializable {
    protected int $libraryId = 0;
    protected string $userId = '';
    protected bool $permissionEdit = false;
    protected string $createdAt = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('libraryId', 'integer');
        $this->addType('permissionEdit', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id'             => $this->id,
            'libraryId'      => $this->libraryId,
            'userId'         => $this->userId,
            'permissionEdit' => $this->permissionEdit,
            'createdAt'      => $this->createdAt,
        ];
    }
}
