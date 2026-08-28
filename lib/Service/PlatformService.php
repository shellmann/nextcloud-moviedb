<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\Platform;
use OCA\MovieDB\Db\PlatformMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class PlatformService {
    private PlatformMapper $mapper;

    public function __construct(PlatformMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Platform {
        return $this->mapper->find($id);
    }

    /**
     * @return Platform[]
     */
    public function findAllForUser(string $userId): array {
        // Fresh installs can end up without the default platforms: Nextcloud's
        // migrator does not reliably fire the seeding postSchemaChange hook
        // during `app:enable`, so relying on the migration alone leaves the
        // platform picker empty. Seed lazily on first read instead — cheap
        // (a single COUNT) once defaults exist, and self-healing otherwise.
        if (!$this->mapper->hasDefaults()) {
            $this->mapper->createDefaults();
        }

        return $this->mapper->findAllForUser($userId);
    }

    public function create(string $userId, array $data): Platform {
        $platform = new Platform();
        $platform->setUserId($userId);
        $platform->setName($data['name']);
        $platform->setIcon($data['icon'] ?? null);
        $platform->setIsDefault(false);
        $platform->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->insert($platform);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): Platform {
        $platform = $this->mapper->find($id);

        // Only allow updating user's own platforms, not defaults
        if ($platform->getIsDefault() || $platform->getUserId() !== $userId) {
            throw new \Exception('Cannot modify this platform');
        }

        if (isset($data['name'])) {
            $platform->setName($data['name']);
        }
        if (array_key_exists('icon', $data)) {
            $platform->setIcon($data['icon']);
        }

        return $this->mapper->update($platform);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $platform = $this->mapper->find($id);

        // Only allow deleting user's own platforms, not defaults
        if ($platform->getIsDefault() || $platform->getUserId() !== $userId) {
            throw new \Exception('Cannot delete this platform');
        }

        $this->mapper->delete($platform);
    }
}
