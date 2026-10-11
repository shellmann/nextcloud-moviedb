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
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {

    private UserDataCleanupService&MockObject $cleanup;
    private LoggerInterface&MockObject $logger;
    private UserDeletedListener $listener;

    protected function setUp(): void {
        parent::setUp();
        $this->cleanup = $this->createMock(UserDataCleanupService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new UserDeletedListener($this->cleanup, $this->logger);
    }

    private function event(): UserDeletedEvent {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return new UserDeletedEvent($user);
    }

    public function testIgnoresOtherEvents(): void {
        $this->cleanup->expects($this->never())->method('deleteUserData');

        $this->listener->handle(new Event());
    }

    public function testDeletesDataOfDeletedUser(): void {
        $this->cleanup->expects($this->once())->method('deleteUserData')->with('alice');

        $this->listener->handle($this->event());
    }

    public function testFailureIsLoggedAndSwallowed(): void {
        $this->cleanup->method('deleteUserData')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->listener->handle($this->event());
        $this->addToAssertionCount(1);
    }
}
