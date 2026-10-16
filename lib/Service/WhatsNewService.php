<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Db\LibraryMapper;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;

/**
 * Which MovieDB version a user last saw the "What's new" dialog for. The
 * frontend shows the release notes of every version after that one, once.
 */
class WhatsNewService {
    /**
     * The release before the dialog existed. Users who used MovieDB before have
     * no stored version yet and start from here, so they see the first notes.
     */
    public const BASELINE_VERSION = '1.8.0';

    public const CONFIG_KEY = 'last_seen_version';

    public function __construct(
        private IUserConfig $userConfig,
        private IAppManager $appManager,
        private LibraryMapper $libraryMapper,
    ) {
    }

    /**
     * @return array{currentVersion: string, lastSeenVersion: string}
     */
    public function state(string $userId): array {
        $current = $this->appManager->getAppVersion(Application::APP_ID);
        $lastSeen = $this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_KEY, '');

        if ($lastSeen === '') {
            // Decide once and store it, so the library lookup never runs again.
            // Used MovieDB before the dialog existed → start from the baseline.
            // First visit ever → nothing to catch up on; stored now, before the
            // first API call creates the personal library and the user would
            // look like an existing one next time.
            $lastSeen = $this->libraryMapper->findAccessible($userId) !== [] ? self::BASELINE_VERSION : $current;
            $this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_KEY, $lastSeen);
        }

        return ['currentVersion' => $current, 'lastSeenVersion' => $lastSeen];
    }

    public static function isValidVersion(string $version): bool {
        return strlen($version) <= 32 && preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) === 1;
    }
}
