<?php

declare(strict_types=1);

namespace OCA\MovieDB\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Platform>
 */
class PlatformMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'moviedb_platforms', Platform::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, ?string $userId = null): Platform {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // If userId is provided, restrict to user's own platforms or defaults
        if ($userId !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
                    $qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
                )
            );
        }

        return $this->findEntity($qb);
    }

    /**
     * Get all platforms available to a user (defaults + user's custom ones)
     * @return Platform[]
     */
    public function findAllForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
                    $qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
                )
            )
            ->orderBy('is_default', 'DESC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get only default platforms
     * @return Platform[]
     */
    public function findDefaults(): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Check if default platforms exist
     */
    public function hasDefaults(): bool {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return ((int)($row['count'] ?? 0)) > 0;
    }

    /**
     * Create default platforms
     */
    public function createDefaults(): void {
        $defaults = [
            ['name' => 'Netflix', 'icon' => 'netflix'],
            ['name' => 'Amazon Prime Video', 'icon' => 'prime'],
            ['name' => 'Disney+', 'icon' => 'disney'],
            ['name' => 'HBO Max', 'icon' => 'hbo'],
            ['name' => 'Apple TV+', 'icon' => 'appletv'],
            ['name' => 'Waipu TV', 'icon' => 'waipu'],
            ['name' => 'Sky', 'icon' => 'sky'],
            ['name' => 'YouTube', 'icon' => 'youtube'],
            ['name' => 'TV', 'icon' => 'tv'],
            ['name' => 'Cinema', 'icon' => 'cinema'],
            ['name' => 'DVD/Blu-ray', 'icon' => 'disc'],
            ['name' => 'Other', 'icon' => 'other'],
        ];

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($defaults as $default) {
            $platform = new Platform();
            $platform->setUserId(null);
            $platform->setName($default['name']);
            $platform->setIcon($default['icon']);
            $platform->setIsDefault(true);
            $platform->setCreatedAt($now);
            $this->insert($platform);
        }
    }
}
