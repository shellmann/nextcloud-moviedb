# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Fixed
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
