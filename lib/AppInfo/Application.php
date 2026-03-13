<?php

declare(strict_types=1);

namespace OCA\MovieDB\AppInfo;

use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Listener\CspListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

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
    }

    public function boot(IBootContext $context): void {
        // Initialize default platforms if they don't exist
        $server = $context->getServerContainer();

        try {
            /** @var PlatformMapper $platformMapper */
            $platformMapper = $server->get(PlatformMapper::class);

            if (!$platformMapper->hasDefaults()) {
                $platformMapper->createDefaults();
            }
        } catch (\Exception $e) {
            // Silently fail if database isn't ready yet (e.g., during installation)
        }
    }
}
