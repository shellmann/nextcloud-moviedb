<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\QuizService;
use OCA\MovieDB\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Random\Engine\Mt19937;
use Random\Randomizer;

class QuizServiceTest extends TestCase {

    private MovieMapper&MockObject $movieMapper;
    private SeriesMapper&MockObject $seriesMapper;
    private MovieWatchMapper&MockObject $watchMapper;
    private QuizService $service;

    private const LIB = 3;
    private const USER = 'alice';

    protected function setUp(): void {
        parent::setUp();
        $this->movieMapper = $this->createMock(MovieMapper::class);
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->service = new QuizService($this->movieMapper, $this->seriesMapper, $this->watchMapper);
    }

    private function rng(int $seed = 42): Randomizer {
        return new Randomizer(new Mt19937($seed));
    }

    /** A movie with every attribute the quiz can use unless overridden. */
    private function movie(int $id, string $title, array $o = []): Movie {
        $m = new Movie();
        $m->setId($id);
        $m->setTitle($title);
        $m->setReleaseYear($o['year'] ?? 1990 + $id);
        $m->setPosterPath(array_key_exists('poster', $o) ? $o['poster'] : "/p$id.jpg");
        $m->setDirector(array_key_exists('director', $o) ? $o['director'] : "Director $id");
        $m->setCastData($o['cast'] ?? [['name' => "Actor {$id}a"], ['name' => "Actor {$id}b"]]);
        return $m;
    }

    private function bareMovie(int $id, string $title): Movie {
        return $this->movie($id, $title, ['year' => 0, 'poster' => null, 'director' => '', 'cast' => []]);
    }

    private function series(int $id, string $title, ?int $seasons, int $year = 2010): Series {
        $s = new Series();
        $s->setId($id);
        $s->setTitle($title);
        $s->setFirstAirYear($year);
        $s->setNumberOfSeasons($seasons);
        $s->setDirector('Some Creator');
        return $s;
    }

    private function watch(?int $movieId, ?int $seriesId, ?string $date, ?int $rating = null, ?string $review = null, int $id = 1): MovieWatch {
        $w = new MovieWatch();
        $w->setId($id);
        $w->setMovieId($movieId);
        $w->setSeriesId($seriesId);
        $w->setWatchedAt($date);
        $w->setRating($rating);
        $w->setReview($review);
        return $w;
    }

    private function load(array $movies, array $series = [], array $watches = []): void {
        $this->movieMapper->method('findAllByLibrary')->with(self::LIB)->willReturn($movies);
        $this->seriesMapper->method('findAllByLibrary')->with(self::LIB)->willReturn($series);
        $this->watchMapper->method('findAllByLibraryAndUser')->with(self::LIB, self::USER)->willReturn($watches);
    }

    private function tenMovies(): array {
        return array_map(fn (int $i) => $this->movie($i, "Movie $i"), range(1, 10));
    }

    public function testEmptyLibraryHasNoQuestions(): void {
        $this->load([]);

        $this->assertSame(['maxQuestions' => 0, 'titleCount' => 0], $this->service->meta(self::LIB, self::USER));
        $this->assertSame([], $this->service->generate(self::LIB, self::USER, 5, $this->rng()));
    }

    public function testCountIsClampedToMaxQuestionsAndEveryTitleIsUsedOnce(): void {
        $this->load($this->tenMovies());

        $questions = $this->service->generate(self::LIB, self::USER, 999, $this->rng());

        $this->assertCount(10, $questions);
        $ids = array_map(fn ($q) => $q['subject']['id'], $questions);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testCountBelowOneIsRaisedToOne(): void {
        $this->load($this->tenMovies());

        $this->assertCount(1, $this->service->generate(self::LIB, self::USER, 0, $this->rng()));
    }

    public function testRequestedCountIsHonouredWhenSmallerThanMax(): void {
        $this->load($this->tenMovies());

        $this->assertCount(4, $this->service->generate(self::LIB, self::USER, 4, $this->rng()));
    }

    public function testTitlesWithoutAnyUsableDataDoNotCount(): void {
        $this->load([$this->movie(1, 'Good'), $this->bareMovie(2, 'Bare'), $this->bareMovie(3, 'Empty')]);

        $meta = $this->service->meta(self::LIB, self::USER);

        $this->assertSame(3, $meta['titleCount']);
        $this->assertSame(1, $meta['maxQuestions']);
    }

    public function testMaxQuestionsIsCappedAtOneHundred(): void {
        $this->load(array_map(fn (int $i) => $this->movie($i, "Movie $i"), range(1, 120)));

        $this->assertSame(100, $this->service->meta(self::LIB, self::USER)['maxQuestions']);
        $this->assertCount(100, $this->service->generate(self::LIB, self::USER, 500, $this->rng()));
    }

    public function testChoiceQuestionsHaveFourUniqueOptionsIncludingTheAnswer(): void {
        $this->load($this->tenMovies());

        $questions = $this->service->generate(self::LIB, self::USER, 10, $this->rng());

        $choice = array_filter($questions, fn ($q) => $q['mode'] === 'choice');
        $this->assertNotEmpty($choice);
        foreach ($choice as $q) {
            $this->assertCount(4, $q['options']);
            $labels = array_map(fn ($o) => mb_strtolower($o['label']), $q['options']);
            $this->assertSame($labels, array_values(array_unique($labels)));
            $keys = array_column($q['options'], 'key');
            $this->assertSame(['0', '1', '2', '3'], $keys);
            $this->assertContains($q['answer'], $keys);
        }
    }

    public function testYearQuestionsCarryARangeThatContainsTheAnswer(): void {
        $this->load($this->tenMovies());

        $questions = $this->service->generate(self::LIB, self::USER, 10, $this->rng());

        $years = array_filter($questions, fn ($q) => $q['mode'] === 'year');
        $this->assertNotEmpty($years);
        foreach ($years as $q) {
            $this->assertNull($q['options']);
            $this->assertGreaterThanOrEqual($q['min'], $q['answer']);
            $this->assertLessThanOrEqual($q['max'], $q['answer']);
        }
    }

    public function testRoundIsVariedAcrossTypes(): void {
        $this->load($this->tenMovies());

        $questions = $this->service->generate(self::LIB, self::USER, 8, $this->rng());

        // release_year, director, actor and poster are eligible: 8 questions
        // spread by "least used first" means two of each.
        $counts = array_count_values(array_column($questions, 'type'));
        $this->assertCount(4, $counts);
        $this->assertSame([2, 2, 2, 2], array_values($counts));
    }

    public function testSameSeedGivesSameRound(): void {
        $this->load($this->tenMovies());

        $this->assertEquals(
            $this->service->generate(self::LIB, self::USER, 6, $this->rng(7)),
            $this->service->generate(self::LIB, self::USER, 6, $this->rng(7))
        );
    }

    public function testWatchYearNeedsOwnWatchesInTwoDifferentYears(): void {
        $a = $this->bareMovie(1, 'A');
        $b = $this->bareMovie(2, 'B');

        // Same year, different days: only "who first" is possible.
        $this->load([$a, $b], [], [
            $this->watch(1, null, '2025-01-10', null, null, 1),
            $this->watch(2, null, '2025-06-01', null, null, 2),
        ]);
        $types = array_column($this->service->generate(self::LIB, self::USER, 2, $this->rng()), 'type');
        $this->assertSame([QuizService::TYPE_WATCHED_FIRST, QuizService::TYPE_WATCHED_FIRST], $types);
    }

    public function testWatchYearIsOfferedWhenWatchesSpanTwoYears(): void {
        $this->load([$this->bareMovie(1, 'A'), $this->bareMovie(2, 'B')], [], [
            $this->watch(1, null, '2024-01-10', null, null, 1),
            $this->watch(2, null, '2025-06-01', null, null, 2),
        ]);

        $questions = $this->service->generate(self::LIB, self::USER, 2, $this->rng());

        $types = array_column($questions, 'type');
        sort($types);
        $this->assertSame([QuizService::TYPE_WATCH_YEAR, QuizService::TYPE_WATCHED_FIRST], $types);
        foreach ($questions as $q) {
            if ($q['type'] === QuizService::TYPE_WATCH_YEAR) {
                $this->assertSame($q['subject']['title'] === 'A' ? 2024 : 2025, $q['answer']);
                $this->assertSame(2023, $q['min']);
            }
        }
    }

    public function testWatchedFirstAnswerIsTheEarlierTitle(): void {
        $this->load([$this->bareMovie(1, 'Early'), $this->bareMovie(2, 'Late')], [], [
            $this->watch(1, null, '2024-01-10', null, null, 1),
            $this->watch(2, null, '2024-06-01', null, null, 2),
        ]);

        $q = $this->service->generate(self::LIB, self::USER, 1, $this->rng())[0];

        $this->assertSame(QuizService::TYPE_WATCHED_FIRST, $q['type']);
        $this->assertCount(2, $q['options']);
        $answerLabel = array_values(array_filter($q['options'], fn ($o) => $o['key'] === $q['answer']))[0]['label'];
        $this->assertSame('Early', $answerLabel);
    }

    public function testRewatchUsesTheFirstWatchDate(): void {
        $this->load([$this->bareMovie(1, 'A'), $this->bareMovie(2, 'B')], [], [
            $this->watch(1, null, '2023-05-01', null, null, 1),
            $this->watch(1, null, '2025-05-01', null, null, 2),
            $this->watch(2, null, '2024-05-01', null, null, 3),
        ]);

        $questions = $this->service->generate(self::LIB, self::USER, 2, $this->rng());

        foreach ($questions as $q) {
            if ($q['type'] === QuizService::TYPE_WATCH_YEAR && $q['subject']['title'] === 'A') {
                $this->assertSame(2023, $q['answer']);
            }
        }
        $first = array_values(array_filter($questions, fn ($q) => $q['type'] === QuizService::TYPE_WATCHED_FIRST))[0];
        $label = array_values(array_filter($first['options'], fn ($o) => $o['key'] === $first['answer']))[0]['label'];
        $this->assertSame('A', $label);
    }

    public function testWatchesAreLoadedForTheAskingUserOnly(): void {
        // The mapper is the single source of watches and is scoped to the user;
        // the service must never fall back to the library-wide finder.
        $this->movieMapper->method('findAllByLibrary')->willReturn([]);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([]);
        $this->watchMapper->expects($this->once())->method('findAllByLibraryAndUser')->with(self::LIB, self::USER)->willReturn([]);
        $this->watchMapper->expects($this->never())->method('findAllByLibrary');

        $this->service->meta(self::LIB, self::USER);
    }

    public function testRatingQuestionUsesTheLatestRating(): void {
        $this->load([$this->bareMovie(1, 'A')], [], [
            $this->watch(1, null, '2023-01-01', 4, null, 1),
            $this->watch(1, null, '2024-01-01', 9, null, 2),
        ]);

        $q = $this->service->generate(self::LIB, self::USER, 1, $this->rng())[0];

        $this->assertSame(QuizService::TYPE_RATING, $q['type']);
        $this->assertSame('number', $q['mode']);
        $this->assertSame(9, $q['answer']);
        $this->assertSame([1, 10], [$q['min'], $q['max']]);
    }

    public function testSeriesGetSeasonsQuestionButNoDirectorQuestion(): void {
        $series = [$this->series(1, 'Dark', 3)];
        $this->load([], $series);

        $q = $this->service->generate(self::LIB, self::USER, 1, $this->rng())[0];

        // Release year or seasons only (a series has no director, 1 title has no distractors).
        $this->assertContains($q['type'], [QuizService::TYPE_RELEASE_YEAR, QuizService::TYPE_SEASONS]);
        $this->assertSame('series', $q['subject']['mediaType']);
    }

    public function testSeasonsQuestionRange(): void {
        $this->load([], [$this->series(1, 'Long Runner', 25, 0)]);

        $q = $this->service->generate(self::LIB, self::USER, 1, $this->rng())[0];

        $this->assertSame(QuizService::TYPE_SEASONS, $q['type']);
        $this->assertSame(25, $q['answer']);
        $this->assertSame(1, $q['min']);
        $this->assertSame(30, $q['max']);
    }

    public function testReviewIsMaskedAndOnlyOfferedWithEnoughOtherTitles(): void {
        $review = 'Blade Runner is a slow burn but the visuals of blade runner are stunning and unforgettable.';
        $this->load(
            [$this->bareMovie(1, 'Blade Runner'), $this->bareMovie(2, 'B'), $this->bareMovie(3, 'C'), $this->bareMovie(4, 'D')],
            [],
            [$this->watch(1, null, null, null, $review, 1)]
        );

        $q = $this->service->generate(self::LIB, self::USER, 5, $this->rng())[0];

        $this->assertSame(QuizService::TYPE_REVIEW, $q['type']);
        $this->assertStringNotContainsStringIgnoringCase('blade runner', $q['extra']->review);
        $this->assertStringContainsString('…', $q['extra']->review);
        $labels = array_column($q['options'], 'label');
        $this->assertContains('Blade Runner', $labels);
        $this->assertCount(4, $labels);
    }

    public function testReviewIsNotOfferedWithTooFewOtherTitles(): void {
        $this->load(
            [$this->bareMovie(1, 'Blade Runner'), $this->bareMovie(2, 'B')],
            [],
            [$this->watch(1, null, null, null, 'A long enough review about the film itself.', 1)]
        );

        $this->assertSame(0, $this->service->meta(self::LIB, self::USER)['maxQuestions']);
    }

    public function testReviewThatIsMostlyTheTitleIsNotOffered(): void {
        $this->load(
            [$this->bareMovie(1, 'Alien'), $this->bareMovie(2, 'B'), $this->bareMovie(3, 'C'), $this->bareMovie(4, 'D')],
            [],
            [$this->watch(1, null, null, null, 'Alien Alien Alien', 1)]
        );

        $this->assertSame(0, $this->service->meta(self::LIB, self::USER)['maxQuestions']);
    }

    public function testLongReviewIsCutAtAWordBoundary(): void {
        $review = trim(str_repeat('wonderful ', 60));
        $this->load(
            [$this->bareMovie(1, 'Alien'), $this->bareMovie(2, 'B'), $this->bareMovie(3, 'C'), $this->bareMovie(4, 'D')],
            [],
            [$this->watch(1, null, null, null, $review, 1)]
        );

        $q = $this->service->generate(self::LIB, self::USER, 1, $this->rng())[0];

        $text = $q['extra']->review;
        $this->assertLessThanOrEqual(281, mb_strlen($text));
        $this->assertStringEndsWith('wonderful…', $text);
    }

    public function testActorQuestionAsksForACastMemberAndOffersOutsiders(): void {
        $this->load($this->tenMovies());

        $questions = $this->service->generate(self::LIB, self::USER, 10, $this->rng());

        $actor = array_values(array_filter($questions, fn ($q) => $q['type'] === QuizService::TYPE_ACTOR));
        $this->assertNotEmpty($actor);
        foreach ($actor as $q) {
            $id = $q['subject']['id'];
            $correct = array_values(array_filter($q['options'], fn ($o) => $o['key'] === $q['answer']))[0]['label'];
            $this->assertStringStartsWith("Actor {$id}", $correct);
            foreach ($q['options'] as $o) {
                if ($o['key'] !== $q['answer']) {
                    $this->assertStringStartsNotWith("Actor {$id}", $o['label']);
                }
            }
        }
    }

    public function testTitlesDifferingOnlyInCaseCountOnceForDistractors(): void {
        $movies = [
            $this->movie(1, 'Same', ['year' => 0, 'director' => '', 'cast' => []]),
            $this->movie(2, 'same', ['year' => 0, 'director' => '', 'cast' => []]),
            $this->movie(3, 'Other', ['year' => 0, 'director' => '', 'cast' => []]),
        ];
        $this->load($movies);

        // "Same"/"same" collapse into one distinct title: 2 distinct titles, no 3 distractors.
        $this->assertSame(0, $this->service->meta(self::LIB, self::USER)['maxQuestions']);
    }
}
