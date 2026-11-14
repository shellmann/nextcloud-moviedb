<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DAV\CalDAV\AppCalendar\AppCalendar;
use OCA\MovieDB\Calendar\MovieDbEvent;
use OCA\MovieDB\Calendar\UpcomingCalendar;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\Constants;
use OCP\IL10N;
use Sabre\VObject\Component\VCalendar;

require_once __DIR__ . '/FakeAppCalendar.php';

class UpcomingCalendarTest extends TestCase {
    private UpcomingCalendar $calendar;

    protected function setUp(): void {
        parent::setUp();
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $t) => 'T:' . $t);
        $this->calendar = new UpcomingCalendar([
            $this->event('moviedb-ep-1-s3e1', 'Severance S03E01 · One', '2026-11-09'),
            $this->event('moviedb-ep-1-s3e10', 'Severance S03E10 · Ten', '2026-11-16'),
            $this->event('moviedb-wl-movie-5', 'Dune 3 (release)', '2026-11-23'),
        ], $l);
    }

    private function event(string $uid, string $summary, string $date): array {
        return ['uid' => $uid, 'summary' => $summary, 'date' => $date, 'description' => 'Personal',
            'url' => 'https://nc.example/apps/moviedb/#/tv/1?library=1', 'lastModified' => '2026-11-05 03:00:00'];
    }

    private function utc(string $d): DateTimeImmutable {
        return new DateTimeImmutable($d, new DateTimeZone('UTC'));
    }

    public function testIdentityAndReadOnlyPermissions(): void {
        $this->assertSame('moviedb-upcoming', $this->calendar->getKey());
        $this->assertSame('T:MovieDB – Upcoming', $this->calendar->getDisplayName());
        $this->assertSame(Constants::PERMISSION_READ, $this->calendar->getPermissions());
        $this->assertFalse($this->calendar->isDeleted());
    }

    public function testTheDavWrapperGetsThePlainUriAndEveryoneElseTheWrappedCollectionName(): void {
        // The dashboard's "Upcoming events" builds /remote.php/dav/calendars/<user>/<uri>/ links from
        // getUri(); only the wrapped name exists as a collection.
        $this->assertSame('app-generated--dav-wrapper--moviedb-upcoming', $this->calendar->getUri());
        $this->assertSame('moviedb-upcoming', (new \OCA\DAV\CalDAV\AppCalendar\AppCalendar())->uriOf($this->calendar));
    }

    public function testASearchWithATimerangeOnlyBuildsThatWindow(): void {
        $l = $this->createStub(IL10N::class);
        $windows = [];
        $calendar = new UpcomingCalendar(function (?string $from, ?string $until) use (&$windows): array {
            $windows[] = [$from, $until];
            return [];
        }, $l);

        $calendar->search('', [], ['timerange' => ['start' => $this->utc('2026-11-10'), 'end' => $this->utc('2026-11-12')]]);
        $calendar->search('', [], ['timerange' => ['start' => $this->utc('2026-11-10'), 'end' => $this->utc('2026-11-12')]]);
        $calendar->search('');

        $this->assertSame([['2026-11-09', '2026-11-13'], [null, null]], $windows);
    }

    public function testOtherCallersGetTheDocumentedArrays(): void {
        $rows = $this->calendar->search('');

        $this->assertCount(3, $rows);
        $this->assertSame('VEVENT', $rows[0]['type']);
        $this->assertSame('moviedb-ep-1-s3e1.ics', $rows[0]['uri']);
        $object = $rows[0]['objects'][0];
        $this->assertSame('moviedb-ep-1-s3e1', $object['UID'][0]);
        $this->assertSame('Severance S03E01 · One', $object['SUMMARY'][0]);
        // What UpcomingEventsService reads: $event['objects'][0]['DTSTART'][0]->getTimestamp()
        $this->assertSame($this->utc('2026-11-09')->getTimestamp(), $object['DTSTART'][0]->getTimestamp());
        $this->assertSame(['VALUE' => 'DATE'], $object['DTSTART'][1]);
    }

    public function testTheDavCalendarGetsSabreComponentsThatAnswerUid(): void {
        $children = (new AppCalendar())->getChildren($this->calendar);

        $this->assertCount(3, $children);
        $this->assertInstanceOf(MovieDbEvent::class, $children[0]);
        $this->assertSame('moviedb-ep-1-s3e1', (string)$children[0]['UID']);
        $vcalendar = new VCalendar($children);
        $ics = $vcalendar->serialize();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261109', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261110', $ics);
        $this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
        $this->assertStringContainsString('https://nc.example/apps/moviedb/#/tv/1?library=1', $ics);
    }

    public function testDavTimestampsAreStableBetweenCalls(): void {
        $a = (new AppCalendar())->getChildren($this->calendar)[0]->serialize();
        $b = (new AppCalendar())->getChildren($this->calendar)[0]->serialize();

        $this->assertSame($a, $b);
        $this->assertStringContainsString('LAST-MODIFIED:20261105T030000Z', $a);
        $this->assertStringContainsString('DTSTAMP:20261105T030000Z', $a);
        $this->assertSame(1, substr_count($a, 'DTSTAMP'));
    }

    public function testLookupByFileNameOrUidIsExactNotASubstring(): void {
        $byFile = $this->calendar->search('moviedb-ep-1-s3e1.ics', ['X-FILENAME']);
        $byUid = $this->calendar->search('moviedb-ep-1-s3e1', ['UID']);

        $this->assertCount(1, $byFile);
        $this->assertSame('moviedb-ep-1-s3e1', $byFile[0]['uid']);
        $this->assertCount(1, $byUid);
        $this->assertSame([], $this->calendar->search('moviedb-ep-1-s3e', ['UID']));
    }

    public function testSummarySearchIsCaseInsensitiveSubstring(): void {
        $this->assertCount(2, $this->calendar->search('severance'));
        $this->assertCount(1, $this->calendar->search('dune', ['SUMMARY']));
    }

    public function testTimerangeKeepsOnlyOverlappingDays(): void {
        $options = ['timerange' => ['start' => $this->utc('2026-11-10'), 'end' => $this->utc('2026-11-17')]];

        $uids = array_column($this->calendar->search('', [], $options), 'uid');

        $this->assertSame(['moviedb-ep-1-s3e10'], $uids);
    }

    public function testAnEventEndingExactlyAtTheRangeStartIsOutside(): void {
        $options = ['timerange' => ['start' => $this->utc('2026-11-10')]];

        $this->assertNotContains('moviedb-ep-1-s3e1', array_column($this->calendar->search('', [], $options), 'uid'));
    }

    public function testTypesAndUidAndUriOptions(): void {
        $this->assertSame([], $this->calendar->search('', [], ['types' => ['VTODO']]));
        $this->assertCount(3, $this->calendar->search('', [], ['types' => ['VEVENT']]));
        $this->assertCount(1, $this->calendar->search('', [], ['uid' => 'moviedb-wl-movie-5']));
        $this->assertCount(1, $this->calendar->search('', [], ['uri' => 'moviedb-wl-movie-5.ics']));
    }

    public function testLimitAndOffset(): void {
        $rows = $this->calendar->search('', [], [], 1, 1);

        $this->assertCount(1, $rows);
        $this->assertSame('moviedb-ep-1-s3e10', $rows[0]['uid']);
    }
}
