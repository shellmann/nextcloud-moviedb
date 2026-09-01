# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-08-31

### Added
- **Shared Libraries**: create named libraries and share your movie/TV collection
  with other Nextcloud users — assign viewer or editor roles, manage members,
  and switch between libraries from a single account. Everything about a title
  (catalog, watchlist, episode progress, ratings, platform, review) is shared
  across all library members; `user_id` on catalog rows becomes legacy attribution
  only — library membership is the sole access gate
- New DB tables `moviedb_libraries` and `moviedb_library_members`; all catalog
  tables gain a `library_id NOT NULL` column (Migrations V5 → V7)
- V5 backfills every user's existing data into an auto-created personal library;
  V6 enforces the NOT NULL constraint; V7 heals any duplicate-membership edge cases
  left by a check-then-insert race in V5
- **Self-leave**: members added to a shared library can leave it without needing
  the owner to remove them (Leave button + confirmation dialog in the Libraries view)
- `GET /api/libraries/sharees?search=...` — user-search endpoint backed by
  `OCP\Collaboration\Collaborators\ISearch`, respecting Nextcloud's
  `shareapi_restrict_user_enumeration` and group-restriction admin settings
- Viewer notice banners on Add Movie, Add TV Show, and Add to Watchlist pages
  when the active library is view-only

### Changed
- All catalog endpoints (`/api/movies`, `/api/series`, `/api/watchlist`, watches,
  stats) now accept an optional `libraryId` query/body param and scope reads/writes
  to that library; omitting it falls back to the user's personal library
- `LibraryService.resolveLibraryId` (writes) throws on a denied explicit ID
  instead of silently falling back to personal, preventing cross-library write
  confusion
- Member management restricted to owner only (editors can no longer add/remove
  members); viewers see the members list read-only
- Library delete cascade wrapped in a DB transaction (episodes → watches →
  watchlist → series → movies → members → library)
- Rename preserves the caller's owner role in the response (annotate() helper
  centralises role annotation for all library-returning endpoints)
- `GET /api/libraries/sharees` requires at least 1 character to prevent
  zero-query full user enumeration
- Dashboard "Recently Watched" now interleaves movies and TV shows sorted by
  watch date instead of showing movies-only followed by TV-only

### Fixed
- Library rename bug: editing the name field immediately after saving no longer
  reverts to the pre-save name due to a stale local binding

### Tests
- 121 PHP tests, 136 JS tests, 0 lint errors
- New: `LibraryServiceTest` (21 PHP tests covering create, rename, member CRUD,
  self-leave, canAccess/canEdit, annotate)
- New: `libraries.spec.js` (28 JS tests covering store state, getters, fetch,
  create/rename/remove, leaveLibrary, member actions)
- All pre-existing tests updated for v1.4 API signatures (`libraryId` param added
  to MovieService, WatchlistService, SeriesService, MovieWatchService, StatsService)

## [1.3.1] - 2026-08-29

### Fixed
- `PlatformService::findAllForUser` now checks `hasDefaults()` before calling
  `createDefaults()`, avoiding an unnecessary DB write on every PHP process start
- `SeriesServiceTest`: test mocks corrected from non-existent `findByTmdbId` to
  `findByTmdbIdAndSeries`; static `$defaultsSeeded` flag reset between test cases
  so platform-seeding tests run in isolation

### Changed
- App Store description updated to reflect TV show tracking and rewatch support
  added in 1.2.0 and 1.3.0

## [1.3.0] - 2026-08-28

### Added
- **Episode-level TV show support** (#15): track series, seasons, and
  individual episodes. Series get their own `/tv` section, list view,
  and detail page separate from movies
- New `moviedb_series` and `moviedb_episodes` tables; series metadata and
  every episode (including specials) are imported from TMDB on add, one
  API call per season
- **Derived progress**: season and series completion are computed from each
  episode's watched flag — never stored as a season/series-level percentage.
  Unaired episodes (air date in the future or unknown) are excluded from the
  denominator; specials (season 0) are stored and shown but excluded from progress
- **"Up next"** card surfaces the first aired, unwatched episode
- **Mark whole season / whole series watched** fan-out — idempotent, so
  re-clicking never re-flips; only aired, non-special episodes are affected
- **Series-owned watch metadata**: a TV show carries its own rating, platform,
  language watched, and watch date — set on the Add TV Show and Edit TV Show
  forms, exactly like a movie — stored as a single series-level row in
  `moviedb_movie_watches` (nullable `series_id`/`episode_id`). Individual
  episodes are a plain watched/unwatched checkbox with no per-episode metadata
- TMDB Movies/TV toggle in the search UI; localized TV genre list
- **Watchlist now supports TV shows** as well as movies: a unified
  watchlist with a Movies/TV type toggle when adding, a Movie/TV badge on
  each entry, and an All/Movies/TV Shows type filter. Marking a show as
  watched imports the whole series (all seasons/episodes) and routes to
  its `/tv` detail page; marking a movie behaves as before
- Dashboard tiles for TV shows watched and episodes watched; total
  runtime now includes episode runtime
- Translations for all new strings (de, es, fr, it, nl)

### Changed
- Migration `Version000003Date20260828` adds the series/episode tables
  (including a `watched` boolean on `moviedb_episodes`), relaxes
  `moviedb_movie_watches.movie_id` to nullable, and adds a
  `media_type` column to `moviedb_watchlist` (default `movie`; existing
  rows backfill to `movie`) — all add-only or NOT-NULL relaxation, safe
  for the SQLite fresh-install batch; no columns dropped
- `media_type` is now populated on movies (default `movie`) for
  forward-compatibility
- Year and platform charts remain movies-only in this release
- Dashboard **Avg Rating** and **Top Rated** now include TV shows alongside
  movies — a show's rating lives on its single series-level watch row, so it
  counts toward the average and can appear in the Top Rated row. Movie and
  series cards now carry a small Movie/TV type badge

### Fixed
- Default streaming platforms are now seeded lazily on first read
  (`PlatformService::findAllForUser`) instead of relying solely on the
  migration's `postSchemaChange` hook, which Nextcloud does not reliably fire
  during `occ app:enable`. Fresh installs no longer show an empty platform
  picker; the seed is idempotent so it never duplicates existing defaults
- **Recently watched TV shows** now appear on the dashboard alongside movies
  (the `recent` stats endpoint returns series with at least one watched
  episode, most-recently-watched first)
- **Episode watched state** is stored as a boolean `watched` column on
  `moviedb_episodes` (toggled by the per-episode checkbox and the season/series
  "mark all watched" actions), and total runtime counts each watched episode's
  runtime. This replaces the earlier per-episode "log watch" dialog: episodes no
  longer carry their own date/platform/language/rating — that metadata belongs to
  the show as a whole (see Added)
- Series import no longer fails when a show has a specials season:
  `moviedb_episodes.season_number`/`episode_number` now carry a DB default of
  `0` so episodes in season 0 (which match the entity's default and are thus
  omitted from the QBMapper INSERT) persist instead of hitting a NOT NULL
  constraint. Affected both direct series add and "mark watched" on a series
  watchlist item

## [1.2.0] - 2026-08-25

### Added
- **Rewatch support**: new `moviedb_movie_watches` table stores one row
  per viewing (date, rating, review, platform, language). Each movie
  can now have a full watch history (#19)
- **Log again** button on the movie detail page with a star-rating
  selector for quick rewatch logging
- **Watch history** section on movie detail listing all watches with
  per-entry delete (hidden when only one entry exists)
- Watchlist "mark as watched" on an already-watched movie now appends
  a new watch entry instead of creating a duplicate movie record

### Changed
- Migration `Version000002Date20260824` backfills all existing
  per-movie watch data into the new watches table with a
  verify-before-drop guard; legacy columns are retained in 1.2.0 for
  safe downgrade and will be dropped in a future release
- Star-rating selector replaces free-text number input in the log-watch
  dialog
- Removed redundant date/language summary box from the movie detail
  header

### Fixed
- Edit form: rating/review/date changes now persist correctly even for
  movies without a prior watch row (previously silently dropped)

### Tests
- 73 JS tests, 69 PHP tests, 0 lint errors

## [1.1.2] - 2026-08-24

### Fixed
- Prevent adding the same movie twice: `POST /api/movies` now returns
  `409 Conflict` with the existing movie's id when a duplicate `tmdbId`
  is submitted, matching the existing watchlist behavior (#17)
- Watchlist store now shows a specific "already in your watchlist"
  message on 409 instead of the generic "failed to add" toast

### Added
- Friendly duplicate-movie dialog in the Add Movie view offering
  "View existing entry" (routes to the existing movie's detail page)
  or "Cancel", instead of a plain error toast
- New i18n strings for the duplicate-movie dialog in all 5 locales
  (de, es, fr, it, nl)

## [1.1.1] - 2026-07-31

### Fixed
- Favorites filter not sending param to API
- Watchlist "Date Added" sort sending wrong column
  (`created_at` → `added_at`)
- Stale filter/sort state persisting across navigation
- `PlatformMapper::find()` authorization bypass (fixed in earlier
  1.1.0-era work, hardened here)

### Added
- Accessibility: proper heading hierarchy, aria-labels on icon-only
  Edit/Delete/sort buttons, `aria-labelledby` on MovieCard
- Mobile: 2-column movie grid on small screens, compact filters
  (platform + genre side-by-side), responsive movie-detail layout
- UX: movie count in "My Movies" heading, contextual empty state for
  the favorites filter, longer "Pick Random" highlight (2s → 4s),
  theme-aware priority badge colors
- App version shown in Settings (injected at build time via webpack
  DefinePlugin)
- Watchlist store test suite (23 new tests) and favorites filter
  tests (4 new)
- Translations for the new strings across all 5 locales

## [1.1.0] - 2026-07-30

### Added
- Genre filter dropdown and sort-direction toggle on the movie list
- Favorites toggle on the movie list
- Watchlist sorting (Priority / Date Added / Title) and a "Pick Random" button
- Genre tags on movie cards and watchlist items
- Clickable dashboard stat cards
- Full TMDB detail fetch (runtime, director, cast, backdrop) when marking a
  watchlist item as watched
- `GENRE_OPTIONS` constant with the 18 TMDB genres
- `TRANSLATIONS.md` documenting the l10n workflow and a missing-key audit script
- CI workflows now run on Node 26 (`.nvmrc`, `engines.node >=26`)
- `terser-webpack-plugin` as an explicit build dependency

### Changed
- Committed `package-lock.json` (pinned to the public npm registry) for
  reproducible `npm ci` builds in CI
- Bumped CI Node version from 20 to 26 to match the dev/deploy runtime
- Declared Nextcloud compatibility as 32–34 (tested on Nextcloud 34)
- Migrated configuration access from the deprecated `IConfig` to `IUserConfig`
  and `IAppConfig`; the TMDB API key is now stored as a sensitive value

### Fixed
- Nextcloud 34 compatibility: replaced removed `\OC::$server->getURLGenerator()`
  and nonce-manager legacy calls in the page template with dependency injection
  and `\OCP\Server::get()`
- SSRF vulnerability in the TMDB image proxy (auth, path validation, no redirects)
- Genre filter false positives caused by naive `LIKE '%N%'` matching
- Redundant per-request database query removed from app boot
- Duplicate toast notifications across several views
- `PlatformMapper::find()` authorization bypass
- Added missing translations for 12 strings across all 5 locales
- `xmllint` availability in the info.xml lint workflow

## [1.0.0] - 2026-03-13

### Added
- Movie tracking with TMDB integration
- Personal ratings (1-10) and review functionality
- Watchlist for movies to watch later
- Multi-language support (German, Spanish, French, Italian, Dutch)
- Statistics and filtering by genre, platform, year
- Custom platform management
- TMDB API integration with automatic metadata fetching
- Modern Vue 3 + Pinia architecture
- Comprehensive test coverage with Vitest
- CI/CD workflows for code quality validation

### Features
- Track watched movies with platform, language, and date
- Automatic movie posters, cast, and plot information from TMDB
- Personal notes and reviews for each movie
- Filter and sort by multiple criteria
- Dashboard with viewing statistics
- Support for Nextcloud 32+
- PHP 8.0+ compatibility
- Responsive design for mobile and desktop
- Dark mode support via Nextcloud theming

### Technical
- Vue 3 with Composition API
- Pinia for state management
- Nextcloud Vue 9 components
- ESLint and code quality checks
- Automated testing with 37 test cases
- Full internationalization infrastructure
