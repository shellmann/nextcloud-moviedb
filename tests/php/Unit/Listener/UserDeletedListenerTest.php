<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Listener;

use OCA\MovieDB\Listener\UserDeletedListener;
use OCA\MovieDB\Service\UserDataCleanupService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {

    private UserDataCleanupService&Stub $cleanup;
    private LoggerInterface&Stub $logger;
    private ?UserDeletedListener $listener = null;

    protected function setUp(): void {
        parent::setUp();
        $this->cleanup = $this->createStub(UserDataCleanupService::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function listener(): UserDeletedListener {
        return $this->listener ??= new UserDeletedListener($this->cleanup, $this->logger);
    }

    private function event(): UserDeletedEvent {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return new UserDeletedEvent($user);
    }

    public function testIgnoresOtherEvents(): void {
        $this->cleanup = $this->createMock(UserDataCleanupService::class);
        $this->cleanup->expects($this->never())->method('deleteUserData');

        $this->listener()->handle(new Event());
    }

    public function testDeletesDataOfDeletedUser(): void {
        $this->cleanup = $this->createMock(UserDataCleanupService::class);
        $this->cleanup->expects($this->once())->method('deleteUserData')->with('alice');

        $this->listener()->handle($this->event());
    }

    public function testFailureIsLoggedAndSwallowed(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cleanup->method('deleteUserData')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->listener()->handle($this->event());
        $this->addToAssertionCount(1);
    }
}
