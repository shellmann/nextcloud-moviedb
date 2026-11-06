<?php

declare(strict_types=1);

namespace OCA\MovieDB\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use OCP\IL10N;
use Sabre\VObject\Component\VCalendar;

/**
 * Read-only "MovieDB – Upcoming" calendar of one user.
 *
 * Core has two consumers of ICalendar::search() that want different result
 * shapes, verified on NC 33, 34, 35 and upstream master:
 *  - OCA\DAV\CalDAV\AppCalendar\AppCalendar (CalDAV, the Calendar app, synced
 *    devices) wraps the results in `new VCalendar($children)`: it needs Sabre
 *    components.
 *  - OC\Calendar\Manager::searchForPrincipal() (the dashboard "Upcoming
 *    events" via UpcomingEventsService) writes `$o['calendar-key']` into each
 *    result and the service reads `$event['objects'][0]['DTSTART'][0]`: it
 *    needs the documented arrays.
 * So search() returns components when AppCalendar is the caller and the
 * documented arrays otherwise.
 */
class UpcomingCalendar implements ICalendar {
    public const URI = 'moviedb-upcoming';
    private const DAV_CALLER = 'OCA\\DAV\\CalDAV\\AppCalendar\\AppCalendar';

    /**
     * @param list<array{uid: string, summary: string, date: string, description: string, url: string, lastModified: string}> $events
     */
    public function __construct(
        private array $events,
        private IL10N $l,
    ) {
    }

    #[\Override]
    public function getKey(): string {
        return self::URI;
    }

    #[\Override]
    public function getUri(): string {
        return self::URI;
    }

    #[\Override]
    public function getDisplayName(): ?string {
        return $this->l->t('MovieDB – Upcoming');
    }

    #[\Override]
    public function getDisplayColor(): ?string {
        return '#01b4e4';
    }

    #[\Override]
    public function getPermissions(): int {
        return Constants::PERMISSION_READ;
    }

    #[\Override]
    public function isDeleted(): bool {
        return false;
    }

    #[\Override]
    public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
        $types = $options['types'] ?? [];
        if ($types !== [] && !in_array('VEVENT', $types, true)) {
            return [];
        }

        $matches = array_values(array_filter(
            $this->events,
            fn (array $e): bool => $this->matches($e, $pattern, $searchProperties, $options)
        ));
        $matches = array_slice($matches, $offset ?? 0, $limit);

        return $this->calledByDav()
            ? array_map(fn (array $e) => $this->component($e), $matches)
            : array_map(fn (array $e) => $this->row($e), $matches);
    }

    private function matches(array $e, string $pattern, array $properties, array $options): bool {
        if (isset($options['uid']) && $options['uid'] !== $e['uid']) {
            return false;
        }
        if (isset($options['uri']) && $options['uri'] !== $e['uid'] . '.ics') {
            return false;
        }
        if (isset($options['timerange'])) {
            $start = $this->midnight($e['date']);
            $end = $start->add(new DateInterval('P1D'));
            $rangeStart = $options['timerange']['start'] ?? null;
            $rangeEnd = $options['timerange']['end'] ?? null;
            if ($rangeEnd instanceof DateTimeInterface && $start >= $rangeEnd) {
                return false;
            }
            if ($rangeStart instanceof DateTimeInterface && $end <= $rangeStart) {
                return false;
            }
        }
        if ($pattern === '') {
            return true;
        }

        $needle = mb_strtolower($pattern);
        $properties = $properties === [] ? ['SUMMARY'] : $properties;
        foreach ($properties as $property) {
            $hit = match ($property) {
                // Core looks single events up by file name, then by UID: exact, never a substring.
                'X-FILENAME' => $pattern === $e['uid'] . '.ics',
                'UID' => $pattern === $e['uid'],
                'SUMMARY' => str_contains(mb_strtolower($e['summary']), $needle),
                'DESCRIPTION' => str_contains(mb_strtolower($e['description']), $needle),
                default => false,
            };
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    private function calledByDav(): bool {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (($frame['class'] ?? null) === self::DAV_CALLER) {
                return true;
            }
        }
        return false;
    }

    private function midnight(string $date): DateTimeImmutable {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function modified(array $e): DateTimeImmutable {
        try {
            return new DateTimeImmutable($e['lastModified'] ?: $e['date'], new DateTimeZone('UTC'));
        } catch (\Exception) {
            return $this->midnight($e['date']);
        }
    }

    private function component(array $e): MovieDbEvent {
        $calendar = new VCalendar();
        $start = $this->midnight($e['date']);
        $event = new MovieDbEvent($calendar, 'VEVENT', [
            'UID' => $e['uid'],
            'SUMMARY' => $e['summary'],
            'DESCRIPTION' => $e['description'],
            'URL' => $e['url'],
            'TRANSP' => 'TRANSPARENT',
        ], false); // no defaults: they would add a DTSTAMP of "now" and make the event change on every request
        $event->add('DTSTART', $start, ['VALUE' => 'DATE']);
        $event->add('DTEND', $start->add(new DateInterval('P1D')), ['VALUE' => 'DATE']);
        $event->add('DTSTAMP', $this->modified($e));
        $event->add('LAST-MODIFIED', $this->modified($e));
        $calendar->add($event);

        return $event;
    }

    private function row(array $e): array {
        $start = $this->midnight($e['date']);
        return [
            'id' => $e['uid'],
            'type' => 'VEVENT',
            'uid' => $e['uid'],
            'uri' => $e['uid'] . '.ics',
            'objects' => [[
                'UID' => [$e['uid'], []],
                'SUMMARY' => [$e['summary'], []],
                'DESCRIPTION' => [$e['description'], []],
                'URL' => [$e['url'], []],
                'DTSTART' => [$start, ['VALUE' => 'DATE']],
                'DTEND' => [$start->add(new DateInterval('P1D')), ['VALUE' => 'DATE']],
            ]],
        ];
    }
}
