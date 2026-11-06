<?php

declare(strict_types=1);

namespace OCA\MovieDB\Calendar;

use OCA\MovieDB\AppInfo\Application;
use OCP\Calendar\ICalendarProvider;
use OCP\Config\IUserConfig;
use OCP\L10N\IFactory;

/**
 * Publishes the read-only "MovieDB – Upcoming" calendar (one per user, all
 * libraries) in the Calendar app and over CalDAV.
 */
class UpcomingCalendarProvider implements ICalendarProvider {
    private const PRINCIPAL_PREFIX = 'principals/users/';

    /** @var array<string, UpcomingCalendar> built events, per user and request */
    private array $cache = [];

    public function __construct(
        private UpcomingEventSource $source,
        private IUserConfig $userConfig,
        private IFactory $l10nFactory,
    ) {
    }

    #[\Override]
    public function getCalendars(string $principalUri, array $calendarUris = []): array {
        if (!str_starts_with($principalUri, self::PRINCIPAL_PREFIX)) {
            return [];
        }
        if ($calendarUris !== [] && !in_array(UpcomingCalendar::URI, $calendarUris, true)) {
            return [];
        }
        $userId = substr($principalUri, strlen(self::PRINCIPAL_PREFIX));
        if ($userId === '' || $this->userConfig->getValueString($userId, Application::APP_ID, 'show_calendar', '1') === '0') {
            return [];
        }

        if (!isset($this->cache[$userId])) {
            $l = $this->l10nFactory->get(Application::APP_ID);
            // Lazy: a plain calendar listing (PROPFIND on the home, Calendar app start) must not
            // run the event queries; they run on the first search().
            $this->cache[$userId] = new UpcomingCalendar(fn (): array => $this->source->eventsFor($userId, $l), $l);
        }

        return [$this->cache[$userId]];
    }
}
