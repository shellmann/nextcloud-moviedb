<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Notification;

use OCA\MovieDB\Notification\Notifier;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;

class NotifierTest extends TestCase {
    private Notifier $notifier;

    protected function setUp(): void {
        parent::setUp();
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $t, array $p = []) => vsprintf($t, $p));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/moviedb/');
        $url->method('imagePath')->willReturn('/apps/moviedb/img/app-dark.svg');
        $url->method('getAbsoluteURL')->willReturnCallback(fn (string $p) => 'https://nc.example' . $p);
        $this->notifier = new Notifier($factory, $url);
    }

    private function notification(string $app, string $subject, array $params): INotification {
        $n = $this->createMock(INotification::class);
        $n->method('getApp')->willReturn($app);
        $n->method('getSubject')->willReturn($subject);
        $n->method('getSubjectParameters')->willReturn($params);
        return $n;
    }

    public function testRendersASingleEpisode(): void {
        $n = $this->notification('moviedb', 'episodes_aired', ['seriesId' => 5, 'libraryId' => 2, 'title' => 'Severance', 'season' => 3, 'episode' => 2, 'count' => 1]);
        $n->expects($this->once())->method('setParsedSubject')->with('New episode of Severance: S03E02');
        $n->expects($this->once())->method('setLink')->with('https://nc.example/apps/moviedb/#/tv/5?library=2');
        $n->expects($this->once())->method('setIcon')->with('https://nc.example/apps/moviedb/img/app-dark.svg');

        $this->assertSame($n, $this->notifier->prepare($n, 'en'));
    }

    public function testRendersSeveralEpisodesOfOneDay(): void {
        $n = $this->notification('moviedb', 'episodes_aired', ['seriesId' => 5, 'libraryId' => 2, 'title' => 'Severance', 'season' => 3, 'episode' => 8, 'count' => 8]);
        $n->expects($this->once())->method('setParsedSubject')->with('8 new episodes of Severance');

        $this->notifier->prepare($n, 'en');
    }

    public function testRendersAReleasedWatchlistTitle(): void {
        $n = $this->notification('moviedb', 'watchlist_released', ['itemId' => 9, 'libraryId' => 1, 'title' => 'Dune Part Three', 'mediaType' => 'movie']);
        $n->expects($this->once())->method('setParsedSubject')->with('Dune Part Three is out now');
        $n->expects($this->once())->method('setLink')->with('https://nc.example/apps/moviedb/#/watchlist?library=1');

        $this->notifier->prepare($n, 'en');
    }

    public function testIgnoresOtherAppsAndUnknownSubjects(): void {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($this->notification('files', 'x', []), 'en');
    }

    public function testUnknownSubjectOfOurAppIsRejected(): void {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($this->notification('moviedb', 'nope', []), 'en');
    }
}
