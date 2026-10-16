<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;

/**
 * Episode progress and watch status for many series at once: progress bars on
 * list cards and the dashboard "continue watching" row. Counts come from
 * grouped queries (EpisodeMapper::progressByLibrary) and follow the same rule
 * as SeriesService::findWithProgress, so list and detail always agree. The
 * status filter of the list applies the same rules in SQL
 * (SeriesMapper::applyWatchStatus), so it paginates for any library size.
 *
 * Episode progress and the stop-watching flag belong to the library, so all
 * members of a shared library see the same status.
 */
class SeriesProgressService {
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_STOPPED = 'stopped';
    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_FINISHED, self::STATUS_STOPPED];

    public const CONTINUE_LIMIT_DEFAULT = 10;
    public const CONTINUE_LIMIT_MAX = 20;

    public function __construct(
        private SeriesMapper $seriesMapper,
        private EpisodeMapper $episodeMapper,
    ) {
    }

    /**
     * Stopped wins over everything; a show is finished once every aired regular
     * episode is watched. Everything else (also unstarted and not-yet-aired shows)
     * is in progress, so a finished show whose new season airs is in progress again.
     */
    public static function watchStatus(bool $stopped, int $watched, int $aired): string {
        if ($stopped) {
            return self::STATUS_STOPPED;
        }
        if ($aired > 0 && $watched >= $aired) {
            return self::STATUS_FINISHED;
        }
        return self::STATUS_IN_PROGRESS;
    }

    /**
     * Serialize $series and add watchedEpisodeCount, airedEpisodeCount, progress
     * and watchStatus to each row, from a single progress query.
     *
     * @param Series[] $series
     * @return array<int, array<string, mixed>>
     */
    public function attachProgress(array $series, int $libraryId): array {
        if ($series === []) {
            return [];
        }
        // Only the rows on screen: at most one page, so the IN list stays small.
        $ids = array_map(static fn (Series $s): int => $s->getId(), $series);
        $progress = $this->episodeMapper->progressByLibrary($libraryId, $this->today(), $ids);

        return array_map(function (Series $s) use ($progress): array {
            return $this->withProgress($s, $progress[$s->getId()] ?? null);
        }, array_values($series));
    }

    /**
     * Shows to continue: in progress with at least one aired, unwatched episode.
     * Started shows come first, most recent activity first; unstarted shows
     * follow, newest addition first. Each row carries its nextEpisode.
     *
     * @return array<int, array<string, mixed>>
     */
    public function continueWatching(int $libraryId, int $limit): array {
        // A missing or non-positive limit means the default, not "one row".
        $limit = $limit > 0 ? min($limit, self::CONTINUE_LIMIT_MAX) : self::CONTINUE_LIMIT_DEFAULT;
        $today = $this->today();
        $progress = $this->episodeMapper->progressByLibrary($libraryId, $today);

        $started = [];
        $unstarted = [];
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $s) {
            $p = $progress[$s->getId()] ?? null;
            if ($p === null || $p['aired'] <= $p['watched'] || $s->getStoppedWatching()) {
                continue;
            }
            if ($p['watched'] > 0) {
                $started[] = [$s, $p];
            } else {
                $unstarted[] = [$s, $p];
            }
        }

        // Newest first; the id breaks ties so the order is stable.
        usort($started, static fn (array $a, array $b): int => [(string)$b[1]['lastWatchedAt'], $b[0]->getId()] <=> [(string)$a[1]['lastWatchedAt'], $a[0]->getId()]);
        usort($unstarted, static fn (array $a, array $b): int => [$b[0]->getCreatedAt(), $b[0]->getId()] <=> [$a[0]->getCreatedAt(), $a[0]->getId()]);

        $rows = [];
        foreach (array_slice([...$started, ...$unstarted], 0, $limit) as [$s, $p]) {
            $row = $this->withProgress($s, $p);
            $next = $this->episodeMapper->findNextUnwatched($s->getId(), $today);
            $row['nextEpisode'] = $next?->jsonSerialize();
            $row['lastActivityAt'] = $p['lastWatchedAt'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array{watched: int, aired: int, lastWatchedAt: ?string}|null $p
     * @return array<string, mixed>
     */
    private function withProgress(Series $s, ?array $p): array {
        $watched = $p['watched'] ?? 0;
        $aired = $p['aired'] ?? 0;
        $data = $s->jsonSerialize();
        $data['watchedEpisodeCount'] = $watched;
        $data['airedEpisodeCount'] = $aired;
        $data['progress'] = $aired > 0 ? (int)round(($watched / $aired) * 100) : 0;
        $data['watchStatus'] = self::watchStatus((bool)$s->getStoppedWatching(), $watched, $aired);
        return $data;
    }

    private function today(): string {
        return (new DateTime())->format('Y-m-d');
    }
}
