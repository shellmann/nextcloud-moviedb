<?php

declare(strict_types=1);

namespace OCA\DAV\CalDAV\AppCalendar;

use OCP\Calendar\ICalendar;

/** Stand-in for core's AppCalendar: UpcomingCalendar recognises its caller by class name. */
class AppCalendar {
    public function getChildren(ICalendar $calendar, string $pattern = '', array $props = [], array $options = []): array {
        return $calendar->search($pattern, $props, $options);
    }
}
