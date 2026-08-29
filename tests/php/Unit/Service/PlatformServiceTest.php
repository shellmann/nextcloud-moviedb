<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Platform;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Service\PlatformService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Unit tests for PlatformService
 *
 * Tests the business logic for streaming platform management.
 */
class PlatformServiceTest extends TestCase {
    private PlatformMapper $mapper;
    private PlatformService $service;

    protected function setUp(): void {
        parent::setUp();

        // Reset the static seed-guard so each test starts fresh.
        $ref = new \ReflectionProperty(PlatformService::class, 'defaultsSeeded');
        $ref->setValue(null, false);

        $this->mapper = $this->createMock(PlatformMapper::class);
        $this->service = new PlatformService($this->mapper);
    }

    public function testFind(): void {
        $platformId = 1;
        $platform = new Platform();
        $platform->setId($platformId);
        $platform->setName('Netflix');

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($platformId)
            ->willReturn($platform);

        $result = $this->service->find($platformId);

        $this->assertInstanceOf(Platform::class, $result);
        $this->assertEquals('Netflix', $result->getName());
    }

    public function testFindAllForUser(): void {
        $userId = 'testuser';
        $platforms = [
            $this->createPlatform(1, 'Netflix', $userId),
            $this->createPlatform(2, 'Disney+', $userId),
            $this->createPlatform(3, 'HBO Max', null, true), // Default platform
        ];

        // Defaults already present → no lazy seeding.
        $this->mapper->expects($this->once())
            ->method('hasDefaults')
            ->willReturn(true);
        $this->mapper->expects($this->never())->method('createDefaults');

        $this->mapper->expects($this->once())
            ->method('findAllForUser')
            ->with($userId)
            ->willReturn($platforms);

        $result = $this->service->findAllForUser($userId);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertInstanceOf(Platform::class, $result[0]);
    }

    public function testFindAllForUserSeedsDefaultsWhenMissing(): void {
        // Fresh install: no defaults exist yet, so findAllForUser must seed them
        // before returning (self-heals when the migration hook didn't fire).
        $userId = 'testuser';

        $this->mapper->expects($this->once())
            ->method('hasDefaults')
            ->willReturn(false);
        $this->mapper->expects($this->once())
            ->method('createDefaults');
        $this->mapper->expects($this->once())
            ->method('findAllForUser')
            ->with($userId)
            ->willReturn([$this->createPlatform(1, 'Netflix', null, true)]);

        $result = $this->service->findAllForUser($userId);

        $this->assertCount(1, $result);
    }

    public function testCreate(): void {
        $userId = 'testuser';
        $data = [
            'name' => 'Apple TV+',
            'icon' => 'apple-tv',
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Platform $platform) use ($userId, $data) {
                return $platform->getUserId() === $userId
                    && $platform->getName() === $data['name']
                    && $platform->getIcon() === $data['icon']
                    && $platform->getIsDefault() === false
                    && $platform->getCreatedAt() !== null;
            }))
            ->willReturnCallback(function (Platform $platform) {
                $platform->setId(1);
                return $platform;
            });

        $result = $this->service->create($userId, $data);

        $this->assertInstanceOf(Platform::class, $result);
        $this->assertEquals($data['name'], $result->getName());
        $this->assertFalse($result->getIsDefault());
    }

    public function testCreateWithoutIcon(): void {
        $userId = 'testuser';
        $data = ['name' => 'Prime Video'];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Platform $platform) {
                return $platform->getName() === 'Prime Video'
                    && $platform->getIcon() === null;
            }))
            ->willReturnCallback(function (Platform $platform) {
                $platform->setId(1);
                return $platform;
            });

        $result = $this->service->create($userId, $data);

        $this->assertEquals('Prime Video', $result->getName());
        $this->assertNull($result->getIcon());
    }

    public function testUpdate(): void {
        $userId = 'testuser';
        $platformId = 1;
        $existingPlatform = $this->createPlatform($platformId, 'Old Name', $userId);

        $updateData = [
            'name' => 'Updated Name',
            'icon' => 'new-icon',
        ];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($platformId)
            ->willReturn($existingPlatform);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Platform $platform) use ($updateData) {
                return $platform->getName() === $updateData['name']
                    && $platform->getIcon() === $updateData['icon'];
            }))
            ->willReturnArgument(0);

        $result = $this->service->update($platformId, $userId, $updateData);

        $this->assertEquals('Updated Name', $result->getName());
        $this->assertEquals('new-icon', $result->getIcon());
    }

    public function testUpdateCannotModifyDefaultPlatform(): void {
        $userId = 'testuser';
        $platformId = 1;
        $defaultPlatform = $this->createPlatform($platformId, 'Netflix', null, true);

        $updateData = ['name' => 'Should Not Update'];

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($platformId)
            ->willReturn($defaultPlatform);

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify this platform');

        $this->service->update($platformId, $userId, $updateData);
    }

    public function testUpdateCannotModifyOtherUsersPlatform(): void {
        $userId = 'testuser';
        $otherUserId = 'otheruser';
        $platformId = 1;
        $otherUserPlatform = $this->createPlatform($platformId, 'Other Platform', $otherUserId);

        $updateData = ['name' => 'Should Not Update'];

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($otherUserPlatform);

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify this platform');

        $this->service->update($platformId, $userId, $updateData);
    }

    public function testDelete(): void {
        $userId = 'testuser';
        $platformId = 1;
        $platform = $this->createPlatform($platformId, 'To Delete', $userId);

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($platformId)
            ->willReturn($platform);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($platform);

        $this->service->delete($platformId, $userId);
    }

    public function testDeleteCannotDeleteDefaultPlatform(): void {
        $userId = 'testuser';
        $platformId = 1;
        $defaultPlatform = $this->createPlatform($platformId, 'Netflix', null, true);

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($defaultPlatform);

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete this platform');

        $this->service->delete($platformId, $userId);
    }

    public function testDeleteCannotDeleteOtherUsersPlatform(): void {
        $userId = 'testuser';
        $otherUserId = 'otheruser';
        $platformId = 1;
        $otherUserPlatform = $this->createPlatform($platformId, 'Other Platform', $otherUserId);

        $this->mapper->expects($this->once())
            ->method('find')
            ->willReturn($otherUserPlatform);

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete this platform');

        $this->service->delete($platformId, $userId);
    }

    private function createPlatform(
        int $id,
        string $name,
        ?string $userId = null,
        bool $isDefault = false
    ): Platform {
        $platform = new Platform();
        $platform->setId($id);
        $platform->setName($name);
        $platform->setUserId($userId);
        $platform->setIsDefault($isDefault);
        $platform->setCreatedAt('2024-01-01 12:00:00');
        return $platform;
    }
}
