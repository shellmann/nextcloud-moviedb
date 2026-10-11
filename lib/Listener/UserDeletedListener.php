<?php

declare(strict_types=1);

namespace OCA\MovieDB\Listener;

use OCA\MovieDB\Service\UserDataCleanupService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

    public function __construct(
        private UserDataCleanupService $cleanupService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }

        $userId = (string)$event->getUser()->getUID();

        // A failure here must never break Nextcloud's user deletion.
        try {
            $this->cleanupService->deleteUserData($userId);
        } catch (\Throwable $e) {
            $this->logger->error('MovieDB: cleanup of deleted user failed', [
                'exception' => $e,
                'userId' => $userId,
            ]);
        }
    }
}
