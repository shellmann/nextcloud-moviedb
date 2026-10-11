<?php

declare(strict_types=1);

namespace OCA\MovieDB\AppInfo;

use OCA\MovieDB\Listener\CspListener;
use OCA\MovieDB\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'moviedb';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register CSP policy to allow TMDB images
        $context->registerEventListener(
            AddContentSecurityPolicyEvent::class,
            CspListener::class
        );

        // Remove MovieDB data when a Nextcloud user is deleted
        $context->registerEventListener(
            UserDeletedEvent::class,
            UserDeletedListener::class
        );
    }

    public function boot(IBootContext $context): void {
        // Default platforms are created during migration (postSchemaChange).
        // No runtime initialization needed here.
    }
}
