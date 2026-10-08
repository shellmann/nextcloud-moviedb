<?php

declare(strict_types=1);

namespace OCA\MovieDB\Settings;

use OCA\MovieDB\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The "MovieDB" section in Administration settings.
 */
class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'MovieDB';
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
