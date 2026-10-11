<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use Random\Randomizer;

/**
 * Builds quiz questions about one user's view of one library.
 *
 * Everything is derived from data already stored (no TMDB calls). Watch-related
 * questions use only the asking user's OWN watches, never other members'.
 * Each title is the subject of at most one question per round.
 */
class QuizService {
    public const MAX_QUESTIONS = 100;

    public const TYPE_RELEASE_YEAR = 'release_year';
    public const TYPE_WATCH_YEAR = 'watch_year';
    public const TYPE_DIRECTOR = 'director';
    public const TYPE_ACTOR = 'actor';
    public const TYPE_WATCHED_FIRST = 'watched_first';
    public const TYPE_POSTER = 'poster';
    public const TYPE_RATING = 'rating';
    public const TYPE_REVIEW = 'review';
    public const TYPE_SEASONS = 'seasons';

    public const TYPES = [
        self::TYPE_RELEASE_YEAR,
        self::TYPE_WATCH_YEAR,
        self::TYPE_DIRECTOR,
        self::TYPE_ACTOR,
        self::TYPE_WATCHED_FIRST,
        self::TYPE_POSTER,
        self::TYPE_RATING,
        self::TYPE_REVIEW,
        self::TYPE_SEASONS,
    ];

    private const MIN_REVIEW_LENGTH = 20;
    private const MAX_REVIEW_LENGTH = 280;
    private const DISTRACTORS = 3;

    public function __construct(
        private MovieMapper $movieMapper,
        private SeriesMapper $seriesMapper,
        private MovieWatchMapper $watchMapper,
    ) {
    }

    /**
     * @return array{maxQuestions: int, titleCount: int}
     */
    public function meta(int $libraryId, string $userId): array {
        $ctx = $this->buildContext($libraryId, $userId);
        $eligible = $this->eligibleSubjects($ctx);

        return [
            'maxQuestions' => min(count($eligible), self::MAX_QUESTIONS),
            'titleCount' => count($ctx['titles']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function generate(int $libraryId, string $userId, int $count, ?Randomizer $rng = null): array {
        $rng ??= new Randomizer();

        $ctx = $this->buildContext($libraryId, $userId);
        $eligible = $this->eligibleSubjects($ctx);
        $max = min(count($eligible), self::MAX_QUESTIONS);
        if ($max === 0) {
            return [];
        }
        $count = max(1, min($count, $max));

        $keys = $rng->shuffleArray(array_keys($eligible));
        $keys = array_slice($keys, 0, $count);

        $used = array_fill_keys(self::TYPES, 0);
        $questions = [];
        foreach ($keys as $key) {
            $types = $eligible[$key];
            // Prefer the type used least so far, so a round is varied.
            $least = min(array_map(static fn (string $t): int => $used[$t], $types));
            $candidates = array_values(array_filter($types, static fn (string $t): bool => $used[$t] === $least));
            $type = $candidates[$rng->getInt(0, count($candidates) - 1)];
            $used[$type]++;

            $questions[] = $this->buildQuestion($type, $ctx['titles'][$key], $ctx, $rng);
        }

        return $questions;
    }

    // ── Context ───────────────────────────────────────────────────────────────

    /**
     * One pass over the library: normalised titles plus the pools the
     * distractors and eligibility rules need.
     *
     * @return array<string, mixed>
     */
    private function buildContext(int $libraryId, string $userId): array {
        $watchesByKey = [];
        foreach ($this->watchMapper->findAllByLibraryAndUser($libraryId, $userId) as $watch) {
            if ($watch->getMovieId() !== null) {
                $watchesByKey['movie:' . $watch->getMovieId()][] = $watch;
            } elseif ($watch->getSeriesId() !== null) {
                $watchesByKey['series:' . $watch->getSeriesId()][] = $watch;
            }
        }

        $titles = [];
        foreach ($this->movieMapper->findAllByLibrary($libraryId) as $movie) {
            $key = 'movie:' . $movie->getId();
            $titles[$key] = $this->movieTitle($movie, $watchesByKey[$key] ?? []);
        }
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $series) {
            $key = 'series:' . $series->getId();
            $titles[$key] = $this->seriesTitle($series, $watchesByKey[$key] ?? []);
        }

        $directors = [];
        $castNames = [];
        $titleNames = [];
        $watchDates = [];
        $years = [];
        $ownYears = [];
        foreach ($titles as $t) {
            if ($t['director'] !== '') {
                $directors[mb_strtolower($t['director'])] = $t['director'];
            }
            foreach ($t['cast'] as $name) {
                $castNames[mb_strtolower($name)] = $name;
            }
            if ($t['title'] !== '') {
                $titleNames[mb_strtolower($t['title'])] = $t['title'];
            }
            if ($t['firstWatch'] !== null) {
                $watchDates[$t['firstWatch']] = true;
                $ownYears[(int)substr($t['firstWatch'], 0, 4)] = true;
            }
            if ($t['year'] > 0) {
                $years[] = $t['year'];
            }
        }

        $currentYear = (int)date('Y');
        $ownYearList = array_keys($ownYears);

        return [
            'titles' => $titles,
            'directors' => $directors,
            'castNames' => $castNames,
            'titleNames' => $titleNames,
            'distinctWatchDates' => count($watchDates),
            'distinctWatchYears' => count($ownYears),
            'releaseMin' => $years === [] ? $currentYear : min($years),
            'releaseMax' => $years === [] ? $currentYear : max($years),
            'watchMin' => $ownYearList === [] ? $currentYear : min($ownYearList),
            'watchMax' => $ownYearList === [] ? $currentYear : max($ownYearList),
            'currentYear' => $currentYear,
        ];
    }

    /**
     * @param MovieWatch[] $watches
     * @return array<string, mixed>
     */
    private function movieTitle(Movie $movie, array $watches): array {
        $year = (int)$movie->getReleaseYear();
        if ($year <= 0 && $movie->getReleaseDate() !== null && ctype_digit(substr($movie->getReleaseDate(), 0, 4))) {
            $year = (int)substr($movie->getReleaseDate(), 0, 4);
        }

        return $this->titleRecord(
            'movie',
            (int)$movie->getId(),
            (string)$movie->getTitle(),
            $movie->getOriginalTitle(),
            $movie->getPosterPath(),
            $year,
            trim((string)$movie->getDirector()),
            $movie->getCastData(),
            null,
            $watches
        );
    }

    /**
     * @param MovieWatch[] $watches
     * @return array<string, mixed>
     */
    private function seriesTitle(Series $series, array $watches): array {
        return $this->titleRecord(
            'series',
            (int)$series->getId(),
            (string)$series->getTitle(),
            $series->getOriginalTitle(),
            $series->getPosterPath(),
            (int)$series->getFirstAirYear(),
            // Series "director" holds the creators, not a director: no director questions.
            '',
            $series->getCastData(),
            $series->getNumberOfSeasons(),
            $watches
        );
    }

    /**
     * @param MovieWatch[] $watches
     * @return array<string, mixed>
     */
    private function titleRecord(
        string $mediaType,
        int $id,
        string $title,
        ?string $originalTitle,
        ?string $posterPath,
        int $year,
        string $director,
        mixed $castData,
        ?int $seasons,
        array $watches,
    ): array {
        $cast = [];
        if (is_array($castData)) {
            foreach ($castData as $member) {
                $name = is_array($member) ? trim((string)($member['name'] ?? '')) : '';
                if ($name !== '') {
                    $cast[mb_strtolower($name)] = $name;
                }
            }
        }

        $firstWatch = null;
        $rating = null;
        $review = null;
        // Newest watch first (undated last) for "latest rating / review".
        usort($watches, static function (MovieWatch $a, MovieWatch $b): int {
            return [$b->getWatchedAt() ?? '', $b->getId()] <=> [$a->getWatchedAt() ?? '', $a->getId()];
        });
        foreach ($watches as $watch) {
            $date = $watch->getWatchedAt();
            if ($date !== null && $date !== '' && ($firstWatch === null || $date < $firstWatch)) {
                $firstWatch = $date;
            }
            $r = $watch->getRating();
            if ($rating === null && $r !== null && $r >= 1 && $r <= 10) {
                $rating = $r;
            }
            $text = trim((string)$watch->getReview());
            if ($review === null && $text !== '') {
                $review = $text;
            }
        }

        return [
            'id' => $id,
            'mediaType' => $mediaType,
            'title' => trim($title),
            'originalTitle' => trim((string)$originalTitle),
            'posterPath' => $posterPath !== null && $posterPath !== '' ? $posterPath : null,
            'year' => $year,
            'director' => $director,
            'cast' => array_values($cast),
            'seasons' => $seasons !== null && $seasons >= 1 ? $seasons : null,
            'firstWatch' => $firstWatch !== null ? substr($firstWatch, 0, 10) : null,
            'rating' => $rating,
            'review' => $review,
        ];
    }

    // ── Eligibility ───────────────────────────────────────────────────────────

    /**
     * Titles that can be the subject of at least one question, with their
     * eligible question types.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, list<string>> title key => types
     */
    private function eligibleSubjects(array $ctx): array {
        $result = [];
        foreach ($ctx['titles'] as $key => $t) {
            $types = $this->eligibleTypes($t, $ctx);
            if ($types !== []) {
                $result[$key] = $types;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $t
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function eligibleTypes(array $t, array $ctx): array {
        $types = [];
        $otherTitles = count($ctx['titleNames']) - ($t['title'] !== '' ? 1 : 0);

        if ($t['year'] > 0) {
            $types[] = self::TYPE_RELEASE_YEAR;
        }
        if ($t['firstWatch'] !== null && $ctx['distinctWatchYears'] >= 2) {
            $types[] = self::TYPE_WATCH_YEAR;
        }
        if ($t['mediaType'] === 'movie' && $t['director'] !== '') {
            $others = count($ctx['directors']) - 1;
            if ($others >= self::DISTRACTORS) {
                $types[] = self::TYPE_DIRECTOR;
            }
        }
        if ($t['cast'] !== []) {
            $own = count(array_unique(array_map('mb_strtolower', $t['cast'])));
            if (count($ctx['castNames']) - $own >= self::DISTRACTORS) {
                $types[] = self::TYPE_ACTOR;
            }
        }
        if ($t['firstWatch'] !== null && $ctx['distinctWatchDates'] >= 2) {
            $types[] = self::TYPE_WATCHED_FIRST;
        }
        if ($t['posterPath'] !== null && $t['title'] !== '' && $otherTitles >= self::DISTRACTORS) {
            $types[] = self::TYPE_POSTER;
        }
        if ($t['rating'] !== null) {
            $types[] = self::TYPE_RATING;
        }
        if ($t['review'] !== null && $t['title'] !== '' && $otherTitles >= self::DISTRACTORS
            && $this->maskedReview($t) !== null) {
            $types[] = self::TYPE_REVIEW;
        }
        if ($t['mediaType'] === 'series' && $t['seasons'] !== null) {
            $types[] = self::TYPE_SEASONS;
        }

        return $types;
    }

    // ── Question building ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $t
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function buildQuestion(string $type, array $t, array $ctx, Randomizer $rng): array {
        $options = null;
        $answer = null;
        $min = null;
        $max = null;
        $extra = [];
        $mode = 'choice';

        switch ($type) {
            case self::TYPE_RELEASE_YEAR:
                $mode = 'year';
                $answer = $t['year'];
                $min = max(1888, $ctx['releaseMin'] - 5);
                $max = max($ctx['currentYear'] + 1, $ctx['releaseMax']);
                break;

            case self::TYPE_WATCH_YEAR:
                $mode = 'year';
                $answer = (int)substr($t['firstWatch'], 0, 4);
                $min = $ctx['watchMin'] - 1;
                $max = max($ctx['currentYear'], $ctx['watchMax']);
                break;

            case self::TYPE_RATING:
                $mode = 'number';
                $answer = $t['rating'];
                $min = 1;
                $max = 10;
                break;

            case self::TYPE_SEASONS:
                $mode = 'number';
                $answer = $t['seasons'];
                $min = 1;
                $max = max(20, $t['seasons'] + 5);
                break;

            case self::TYPE_DIRECTOR:
                [$options, $answer] = $this->choiceOptions(
                    $t['director'],
                    array_values($ctx['directors']),
                    $rng
                );
                break;

            case self::TYPE_ACTOR:
                $ownLower = array_map('mb_strtolower', $t['cast']);
                $correct = $t['cast'][$rng->getInt(0, min(5, count($t['cast'])) - 1)];
                $pool = array_values(array_filter(
                    $ctx['castNames'],
                    static fn (string $name): bool => !in_array(mb_strtolower($name), $ownLower, true)
                ));
                [$options, $answer] = $this->choiceOptions($correct, $pool, $rng);
                break;

            case self::TYPE_POSTER:
                [$options, $answer] = $this->choiceOptions(
                    $t['title'],
                    array_values($ctx['titleNames']),
                    $rng
                );
                break;

            case self::TYPE_REVIEW:
                [$options, $answer] = $this->choiceOptions(
                    $t['title'],
                    array_values($ctx['titleNames']),
                    $rng
                );
                $extra['review'] = $this->maskedReview($t);
                break;

            case self::TYPE_WATCHED_FIRST:
                [$options, $answer] = $this->watchedFirstOptions($t, $ctx, $rng);
                break;
        }

        return [
            'type' => $type,
            'mode' => $mode,
            'subject' => [
                'id' => $t['id'],
                'mediaType' => $t['mediaType'],
                'title' => $t['title'],
                'posterPath' => $t['posterPath'],
                'year' => $t['year'] > 0 ? $t['year'] : null,
            ],
            'options' => $options,
            'answer' => $answer,
            'min' => $min,
            'max' => $max,
            'extra' => (object)$extra,
        ];
    }

    /**
     * The correct label plus three distinct distractors, shuffled, keyed "0".."3".
     *
     * @param list<string> $pool candidate distractor labels
     * @return array{0: list<array{key: string, label: string}>, 1: string} options and the correct key
     */
    private function choiceOptions(string $correct, array $pool, Randomizer $rng): array {
        $seen = [mb_strtolower($correct) => true];
        $distractors = [];
        foreach ($rng->shuffleArray($pool) as $label) {
            $lower = mb_strtolower($label);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $distractors[] = $label;
            if (count($distractors) === self::DISTRACTORS) {
                break;
            }
        }

        $labels = $rng->shuffleArray([$correct, ...$distractors]);
        $options = [];
        $answer = '0';
        foreach ($labels as $i => $label) {
            $options[] = ['key' => (string)$i, 'label' => $label];
            if ($label === $correct) {
                $answer = (string)$i;
            }
        }

        return [$options, $answer];
    }

    /**
     * Two titles with different first-watch dates; the answer is the earlier one.
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed> $ctx
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function watchedFirstOptions(array $t, array $ctx, Randomizer $rng): array {
        $others = [];
        foreach ($ctx['titles'] as $other) {
            if ($other['firstWatch'] !== null && $other['firstWatch'] !== $t['firstWatch']
                && !($other['mediaType'] === $t['mediaType'] && $other['id'] === $t['id'])) {
                $others[] = $other;
            }
        }
        $other = $others[$rng->getInt(0, count($others) - 1)];

        $pair = $rng->shuffleArray([$t, $other]);
        $options = [];
        $answer = '0';
        $earliest = $t['firstWatch'] < $other['firstWatch'] ? $t : $other;
        foreach ($pair as $i => $candidate) {
            $options[] = [
                'key' => (string)$i,
                'label' => $candidate['title'],
                'posterPath' => $candidate['posterPath'],
            ];
            if ($candidate['mediaType'] === $earliest['mediaType'] && $candidate['id'] === $earliest['id']) {
                $answer = (string)$i;
            }
        }

        return [$options, $answer];
    }

    /**
     * The user's latest review with the title (and original title) hidden, cut
     * to a readable length. Null if too little text is left to be a fair question.
     *
     * @param array<string, mixed> $t
     */
    private function maskedReview(array $t): ?string {
        $names = array_values(array_unique(array_filter([$t['title'], $t['originalTitle']], static fn (string $n): bool => $n !== '')));
        if ($names === [] || $t['review'] === null) {
            return null;
        }

        $pattern = '/' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names)) . '/iu';
        $masked = @preg_replace($pattern, '…', $t['review']);
        if (!is_string($masked)) {
            return null;
        }
        $masked = trim($masked);
        if (mb_strlen($masked) < self::MIN_REVIEW_LENGTH) {
            return null;
        }

        if (mb_strlen($masked) > self::MAX_REVIEW_LENGTH) {
            $cut = mb_substr($masked, 0, self::MAX_REVIEW_LENGTH);
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space > 0) {
                $cut = mb_substr($cut, 0, $space);
            }
            $masked = rtrim($cut) . '…';
        }

        return $masked;
    }
}
