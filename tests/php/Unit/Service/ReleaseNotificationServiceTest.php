<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\Config\IUserConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ReleaseNotificationServiceTest extends TestCase {
    private EpisodeMapper&MockObject $episodes;
    private WatchlistMapper&MockObject $watchlist;
    private SeriesMapper&MockObject $series;
    private LibraryMapper&MockObject $libraries;
    private LibraryMemberMapper&MockObject $members;
    private IUserConfig&MockObject $config;
    private IManager&MockObject $manager;
    private ReleaseNotificationService $service;
    /** @var array<int, array{user: ?string, subject: ?string, params: array, object: ?array}> */
    private array $sent = [];

    protected function setUp(): void {
        parent::setUp();
        $this->episodes = $this->createMock(EpisodeMapper::class);
        $this->watchlist = $this->createMock(WatchlistMapper::class);
        $this->series = $this->createMock(SeriesMapper::class);
        $this->libraries = $this->createMock(LibraryMapper::class);
        $this->members = $this->createMock(LibraryMemberMapper::class);
        $this->config = $this->createMock(IUserConfig::class);
        $this->config->method('getValueString')->willReturn('1');
        $this->manager = $this->createMock(IManager::class);
        $this->manager->method('createNotification')->willReturnCallback(fn () => $this->fakeNotification());
        $this->manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->sent[] = $n->state;
        });

        $lib = new Library();
        $lib->setId(1);
        $lib->setOwner('alice');
        $this->libraries->method('find')->willReturn($lib);
        $member = new LibraryMember();
        $member->setUserId('bob');
        $this->members->method('findByLibrary')->willReturn([$member]);

        $this->service = new ReleaseNotificationService(
            $this->episodes, $this->watchlist, $this->series, $this->libraries, $this->members,
            $this->config, $this->manager, $this->createMock(LoggerInterface::class)
        );
    }

    private function fakeNotification(): INotification {
        return new class () implements INotification {
            public array $state = ['user' => null, 'subject' => null, 'params' => [], 'object' => null];

            public function setApp(string $app): INotification { return $this; }
            public function setDateTime(\DateTime $dateTime): INotification { return $this; }
            public function setUser(string $user): INotification { $this->state['user'] = $user; return $this; }
            public function setObject(string $type, string $id): INotification { $this->state['object'] = [$type, $id]; return $this; }
            public function setSubject(string $subject, array $parameters = []): INotification {
                $this->state['subject'] = $subject;
                $this->state['params'] = $parameters;
                return $this;
            }
            public function setParsedSubject(string $subject): INotification { return $this; }
            public function setLink(string $link): INotification { return $this; }
            public function setIcon(string $icon): INotification { return $this; }
            public function getApp(): string { return 'moviedb'; }
            public function getUser(): string { return (string)$this->state['user']; }
            public function getObjectType(): string { return (string)($this->state['object'][0] ?? ''); }
            public function getObjectId(): string { return (string)($this->state['object'][1] ?? ''); }
            public function getSubject(): string { return (string)$this->state['subject']; }
            public function getSubjectParameters(): array { return $this->state['params']; }
        };
    }

    private function row(int $id, int $episode, string $air = '2026-11-06'): array {
        return ['id' => $id, 'series_id' => 5, 'season_number' => 3, 'episode_number' => $episode, 'air_date' => $air, 'series_title' => 'Severance', 'library_id' => 1];
    }

    public function testNotifiesOwnerAndMemberOnceAndStampsTheEpisodes(): void {
        $this->episodes->method('findToNotify')->with('2026-11-04', '2026-11-06')->willReturn([$this->row(1, 1)]);
        $this->episodes->expects($this->once())->method('markNotified')->with([1], $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} /'));

        $count = $this->service->notifyAiredEpisodes('2026-11-06');

        $this->assertSame(2, $count);
        $this->assertSame(['alice', 'bob'], array_column($this->sent, 'user'));
        $this->assertSame('episodes_aired', $this->sent[0]['subject']);
        $this->assertSame(['series', '5'], $this->sent[0]['object']);
        $this->assertSame(1, $this->sent[0]['params']['count']);
    }

    public function testEpisodesOfOneShowAndDayAreOneNotificationWithACount(): void {
        $this->episodes->method('findToNotify')->willReturn([$this->row(1, 1), $this->row(2, 2), $this->row(3, 3)]);
        $this->episodes->expects($this->once())->method('markNotified')->with([1, 2, 3], $this->anything());

        $this->assertSame(2, $this->service->notifyAiredEpisodes('2026-11-06'));
        $this->assertSame(3, $this->sent[0]['params']['count']);
        $this->assertSame(3, $this->sent[0]['params']['episode']);
    }

    public function testDifferentDaysOfTheSameShowAreSeparate(): void {
        $this->episodes->method('findToNotify')->willReturn([$this->row(1, 1, '2026-11-05'), $this->row(2, 2, '2026-11-06')]);

        $this->assertSame(4, $this->service->notifyAiredEpisodes('2026-11-06'));
    }

    public function testNothingToDoSendsAndStampsNothing(): void {
        $this->episodes->method('findToNotify')->willReturn([]);
        $this->episodes->expects($this->never())->method('markNotified');

        $this->assertSame(0, $this->service->notifyAiredEpisodes('2026-11-06'));
    }

    public function testUsersWhoOptedOutGetNothingButTheEpisodesAreStillStamped(): void {
        $config = $this->createMock(IUserConfig::class);
        $config->method('getValueString')->willReturnCallback(fn (string $u) => $u === 'bob' ? '0' : '1');
        $service = new ReleaseNotificationService(
            $this->episodes, $this->watchlist, $this->series, $this->libraries, $this->members,
            $config, $this->manager, $this->createMock(LoggerInterface::class)
        );
        $this->episodes->method('findToNotify')->willReturn([$this->row(1, 1)]);
        $this->episodes->expects($this->once())->method('markNotified');

        $this->assertSame(1, $service->notifyAiredEpisodes('2026-11-06'));
        $this->assertSame(['alice'], array_column($this->sent, 'user'));
    }

    public function testAFailingNotifyDoesNotStopTheOthers(): void {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturnCallback(fn () => $this->fakeNotification());
        $manager->method('notify')->willThrowException(new \RuntimeException('boom'));
        $service = new ReleaseNotificationService(
            $this->episodes, $this->watchlist, $this->series, $this->libraries, $this->members,
            $this->config, $manager, $this->createMock(LoggerInterface::class)
        );
        $this->episodes->method('findToNotify')->willReturn([$this->row(1, 1)]);

        $this->assertSame(0, $service->notifyAiredEpisodes('2026-11-06'));
    }

    public function testReleasedWatchlistItemsGoToEveryMemberAndAreStamped(): void {
        $item = new WatchlistItem();
        $item->setId(9);
        $item->setLibraryId(1);
        $item->setUserId('alice');
        $item->setTitle('Dune Part Three');
        $item->setMediaType('movie');
        $this->watchlist->method('findToNotify')->with('2026-11-04', '2026-11-06')->willReturn([$item]);
        $this->watchlist->expects($this->once())->method('markReleaseNotified')->with([9], $this->anything());

        $this->assertSame(2, $this->service->notifyReleasedWatchlist('2026-11-06'));
        $this->assertSame(['watchlist', '9'], $this->sent[0]['object']);
        $this->assertSame('Dune Part Three', $this->sent[0]['params']['title']);
    }

    public function testAWatchlistItemWithoutLibraryOnlyNotifiesItsCreator(): void {
        $item = new WatchlistItem();
        $item->setId(9);
        $item->setUserId('carol');
        $item->setTitle('Old');
        $this->watchlist->method('findToNotify')->willReturn([$item]);

        $this->assertSame(1, $this->service->notifyReleasedWatchlist('2026-11-06'));
        $this->assertSame(['carol'], array_column($this->sent, 'user'));
    }

    public function testDismissingARemovedShowMarksItsNotificationsProcessed(): void {
        $this->manager->expects($this->once())->method('markProcessed');

        $this->service->dismissSeries(5);
    }

    public function testDismissingALibraryCoversItsShowsAndWatchlistItems(): void {
        $s = new Series();
        $s->setId(5);
        $i = new WatchlistItem();
        $i->setId(9);
        $this->series->method('findAllByLibrary')->willReturn([$s]);
        $this->watchlist->method('findAllByLibrary')->willReturn([$i]);
        $this->manager->expects($this->exactly(2))->method('markProcessed');

        $this->service->dismissLibrary(1, 'bob');
    }

    public function testDismissNeverThrows(): void {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willThrowException(new \RuntimeException('boom'));
        $service = new ReleaseNotificationService(
            $this->episodes, $this->watchlist, $this->series, $this->libraries, $this->members,
            $this->config, $manager, $this->createMock(LoggerInterface::class)
        );

        $service->dismissWatchlistItem(1);
        $this->addToAssertionCount(1);
    }
}
