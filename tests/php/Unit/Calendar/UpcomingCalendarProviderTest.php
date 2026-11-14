<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Calendar;

use OCA\MovieDB\Calendar\UpcomingCalendarProvider;
use OCA\MovieDB\Calendar\UpcomingEventSource;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

class UpcomingCalendarProviderTest extends TestCase {
    private UpcomingEventSource&Stub $source;
    private IUserConfig&Stub $config;
    private IFactory&Stub $factory;
    private ?UpcomingCalendarProvider $provider = null;

    protected function setUp(): void {
        parent::setUp();
        $this->source = $this->createStub(UpcomingEventSource::class);
        $this->config = $this->createStub(IUserConfig::class);
        $this->factory = $this->createStub(IFactory::class);
        $this->factory->method('get')->willReturn($this->createStub(IL10N::class));
    }

    private function provider(): UpcomingCalendarProvider {
        return $this->provider ??= new UpcomingCalendarProvider($this->source, $this->config, $this->factory);
    }

    public function testOnlyUserPrincipalsGetTheCalendar(): void {
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider()->getCalendars('principals/groups/staff'));
        $this->assertSame([], $this->provider()->getCalendars('principals/users/'));
    }

    public function testAnotherCalendarUriGetsNothing(): void {
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider()->getCalendars('principals/users/alice', ['personal']));
    }

    public function testReturnsOneCalendarForTheUserAndBuildsEventsOnlyOnFirstSearch(): void {
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->config->method('getValueString')->willReturn('1');
        // A plain listing must not run the event queries; the first search() runs them once.
        $this->source->expects($this->once())->method('eventsFor')->with('alice')->willReturn([]);

        $first = $this->provider()->getCalendars('principals/users/alice');
        $second = $this->provider()->getCalendars('principals/users/alice', ['moviedb-upcoming']);

        $this->assertCount(1, $first);
        $this->assertSame('app-generated--dav-wrapper--moviedb-upcoming', $first[0]->getUri());
        $this->assertSame($first[0], $second[0]);

        $first[0]->search('');
        $first[0]->search('anything');
    }

    public function testListingTheCalendarDoesNotBuildAnyEvents(): void {
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->config->method('getValueString')->willReturn('1');
        $this->source->expects($this->never())->method('eventsFor');

        $this->provider()->getCalendars('principals/users/alice');
    }

    public function testTheUserSettingHidesTheCalendar(): void {
        $this->config = $this->createMock(IUserConfig::class);
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->config->method('getValueString')->with('alice', 'moviedb', 'show_calendar', '1')->willReturn('0');
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider()->getCalendars('principals/users/alice'));
    }
}
