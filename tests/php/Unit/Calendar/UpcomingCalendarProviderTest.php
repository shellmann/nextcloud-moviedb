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

class UpcomingCalendarProviderTest extends TestCase {
    private UpcomingEventSource&MockObject $source;
    private IUserConfig&MockObject $config;
    private UpcomingCalendarProvider $provider;

    protected function setUp(): void {
        parent::setUp();
        $this->source = $this->createMock(UpcomingEventSource::class);
        $this->config = $this->createMock(IUserConfig::class);
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($this->createMock(IL10N::class));
        $this->provider = new UpcomingCalendarProvider($this->source, $this->config, $factory);
    }

    public function testOnlyUserPrincipalsGetTheCalendar(): void {
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider->getCalendars('principals/groups/staff'));
        $this->assertSame([], $this->provider->getCalendars('principals/users/'));
    }

    public function testAnotherCalendarUriGetsNothing(): void {
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider->getCalendars('principals/users/alice', ['personal']));
    }

    public function testReturnsOneCalendarForTheUserAndBuildsEventsOnlyOnFirstSearch(): void {
        $this->config->method('getValueString')->willReturn('1');
        // A plain listing must not run the event queries; the first search() runs them once.
        $this->source->expects($this->once())->method('eventsFor')->with('alice')->willReturn([]);

        $first = $this->provider->getCalendars('principals/users/alice');
        $second = $this->provider->getCalendars('principals/users/alice', ['moviedb-upcoming']);

        $this->assertCount(1, $first);
        $this->assertSame('moviedb-upcoming', $first[0]->getUri());
        $this->assertSame($first[0], $second[0]);

        $first[0]->search('');
        $first[0]->search('anything');
    }

    public function testListingTheCalendarDoesNotBuildAnyEvents(): void {
        $this->config->method('getValueString')->willReturn('1');
        $this->source->expects($this->never())->method('eventsFor');

        $this->provider->getCalendars('principals/users/alice');
    }

    public function testTheUserSettingHidesTheCalendar(): void {
        $this->config->method('getValueString')->with('alice', 'moviedb', 'show_calendar', '1')->willReturn('0');
        $this->source->expects($this->never())->method('eventsFor');

        $this->assertSame([], $this->provider->getCalendars('principals/users/alice'));
    }
}
