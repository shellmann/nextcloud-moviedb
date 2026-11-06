<?php

declare(strict_types=1);

namespace OCA\MovieDB\Notification;

use OCA\MovieDB\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the release notifications (see ReleaseNotificationService) in the
 * language of the recipient.
 */
class Notifier implements INotifier {
    public const SUBJECT_EPISODES = 'episodes_aired';
    public const SUBJECT_WATCHLIST = 'watchlist_released';
    public const OBJECT_SERIES = 'series';
    public const OBJECT_WATCHLIST = 'watchlist';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }

    #[\Override]
    public function getID(): string {
        return Application::APP_ID;
    }

    #[\Override]
    public function getName(): string {
        return $this->l10nFactory->get(Application::APP_ID)->t('MovieDB');
    }

    #[\Override]
    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException('Unknown app');
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $p = $notification->getSubjectParameters();
        $base = $this->urlGenerator->linkToRouteAbsolute('moviedb.page.index');
        $query = '?library=' . (int)($p['libraryId'] ?? 0);

        switch ($notification->getSubject()) {
            case self::SUBJECT_EPISODES:
                $count = (int)($p['count'] ?? 1);
                $code = sprintf('S%02dE%02d', (int)($p['season'] ?? 0), (int)($p['episode'] ?? 0));
                $notification->setParsedSubject($count > 1
                    ? $l->t('%1$d new episodes of %2$s', [$count, (string)$p['title']])
                    : $l->t('New episode of %1$s: %2$s', [(string)$p['title'], $code]));
                $notification->setLink($base . '#/tv/' . (int)($p['seriesId'] ?? 0) . $query);
                break;

            case self::SUBJECT_WATCHLIST:
                $notification->setParsedSubject($l->t('%s is out now', [(string)$p['title']]));
                $notification->setLink($base . '#/watchlist' . $query);
                break;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }

        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

        return $notification;
    }
}
