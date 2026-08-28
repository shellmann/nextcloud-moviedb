# Running Tests for MovieDB Nextcloud App

This guide explains how to run both PHP unit tests and JavaScript tests for the MovieDB Nextcloud app.

## Quick Start

```bash
# Run JavaScript/Vue tests (works anywhere)
npm test

# Run PHP unit tests (requires Nextcloud environment - see below)
composer test
```

---

## JavaScript/Vue Tests ✅ (Ready to Run Anywhere)

### Prerequisites
- Node.js and npm installed
- Dependencies installed: `npm install`

### Running JavaScript Tests

The frontend tests use **Vitest** and **jsdom** to test Vue components and Pinia stores.

```bash
# Run all JavaScript tests once
npm test

# Run tests in watch mode (auto-rerun on file changes)
npm run test:watch

# Run tests with coverage report
npm run test:coverage
```

### Test Files
Located in `tests/js/`:
- `stores/movies.spec.js` - Tests for movies Pinia store (24 tests)
- `stores/series.spec.js` - Tests for series Pinia store (19 tests)
- `stores/watchlist.spec.js` - Tests for watchlist store incl. TV support (30 tests)
- `stores/watches.spec.js` - Tests for movie-watches store (9 tests)
- `stores/episodeWatches.spec.js` - Tests for episode-watches store (8 tests)
- `components/MovieCard.spec.js` - Tests for MovieCard component (9 tests)
- `components/TmdbSearchSection.spec.js` - Tests for TMDB search + type toggle (8 tests)
- `utils/formatters.spec.js` - Tests for utility functions (8 tests)
- `setup.js` - Test environment configuration

### Example Output
```
✓ tests/js/stores/movies.spec.js (24)
✓ tests/js/stores/series.spec.js (19)
✓ tests/js/stores/watchlist.spec.js (30)
✓ tests/js/stores/watches.spec.js (9)
✓ tests/js/stores/episodeWatches.spec.js (8)
✓ tests/js/components/MovieCard.spec.js (9)
✓ tests/js/components/TmdbSearchSection.spec.js (8)
✓ tests/js/utils/formatters.spec.js (8)

Test Files  8 passed (8)
Tests  115 passed (115)
```

---

## PHP Unit Tests ⚠️ (Requires Nextcloud Environment)

### Important: PHP Tests Must Run Inside Nextcloud

Like all Nextcloud apps, PHP tests require the Nextcloud framework and must be run from within a Nextcloud installation. This is the **standard pattern** used by all official Nextcloud apps (Mail, Notes, Deck, etc.).

### Why Nextcloud Environment is Required

PHP tests need:
- Nextcloud core framework (`OCP\*` interfaces)
- Database abstraction layer (QBMapper, IDBConnection)
- Dependency injection container
- Test utilities and mocking helpers

These are provided by Nextcloud core, not bundled with the app.

---

## Option 1: Local Nextcloud Development Instance (Recommended for Development)

### Setup

1. **Install Nextcloud locally** (if not already installed)
   ```bash
   # Download Nextcloud
   wget https://download.nextcloud.com/server/releases/latest.tar.bz2
   tar -xjf latest.tar.bz2

   # Or use existing MAMP/XAMPP/Docker installation
   ```

2. **Copy your app to Nextcloud's custom_apps directory**
   ```bash
   # From your moviedb directory
   cd /path/to/nextcloud-moviedb

   # Copy to Nextcloud (adjust path to your Nextcloud installation)
   cp -r . /path/to/nextcloud/custom_apps/moviedb/

   # Set proper permissions
   chown -R www-data:www-data /path/to/nextcloud/custom_apps/moviedb
   ```

3. **Enable the app in Nextcloud**
   ```bash
   cd /path/to/nextcloud
   sudo -u www-data php occ app:enable moviedb
   ```

### Running Tests

```bash
# Navigate to the app directory inside Nextcloud
cd /path/to/nextcloud/custom_apps/moviedb

# Install dependencies (if not already done)
composer install

# Run all PHP tests
./vendor/bin/phpunit --testdox

# Or use composer script
composer test
```

### Expected Output
```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Authenticated Controller (OCA\MovieDB\Tests\Unit\Controller\AuthenticatedController)
 ✔ Require auth returns null when authenticated
 ✔ Require auth returns error when not authenticated
 ✔ User id is set when authenticated
 ✔ User id is null when not authenticated

Movie Service (OCA\MovieDB\Tests\Unit\Service\MovieService)
 ✔ Find
 ✔ Find throws does not exist exception
 ✔ Find all
 ✔ Count
 ... (16 tests total)

Watchlist Service (OCA\MovieDB\Tests\Unit\Service\WatchlistService)
 ... (incl. media-type persistence + type-aware existsByTmdbId)

Series Service (OCA\MovieDB\Tests\Unit\Service\SeriesService)
 ... (createFromTmdb, seasons/episodes import)

Watchlist Controller (OCA\MovieDB\Tests\Unit\Controller\WatchlistController)
 ✔ Move series imports show and returns series
 ✔ Move movie creates movie and returns movie

Platform Service (OCA\MovieDB\Tests\Unit\Service\PlatformService)
 ... (8 tests)

Tests: 88, Assertions: 232+
```

---

## Option 2: Docker Container (Recommended for CI/CD)

### Using Docker Compose

Create `docker-compose.test.yml` in your project root:

```yaml
version: '3'

services:
  nextcloud:
    image: nextcloud:latest
    volumes:
      # Mount app into Nextcloud's custom_apps
      - ./:/var/www/html/custom_apps/moviedb
    environment:
      - SQLITE_DATABASE=nextcloud
      - NEXTCLOUD_ADMIN_USER=admin
      - NEXTCLOUD_ADMIN_PASSWORD=admin
    command: >
      bash -c "
        # Wait for Nextcloud to initialize
        while [ ! -f /var/www/html/occ ]; do sleep 1; done;

        # Install app dependencies
        cd /var/www/html/custom_apps/moviedb && composer install;

        # Enable app
        php /var/www/html/occ app:enable moviedb;

        # Run tests
        cd /var/www/html/custom_apps/moviedb && ./vendor/bin/phpunit --testdox
      "
```

Run tests:
```bash
docker-compose -f docker-compose.test.yml up
```

### Using Existing Nextcloud Container

If you already have Nextcloud running in Docker:

```bash
# Copy app into container
docker cp . nextcloud-container:/var/www/html/custom_apps/moviedb

# Enter container
docker exec -it nextcloud-container bash

# Inside container:
cd /var/www/html/custom_apps/moviedb
composer install
php /var/www/html/occ app:enable moviedb
./vendor/bin/phpunit --testdox
```

---

## Option 3: GitHub Actions / CI Pipeline

For automated testing in CI/CD:

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  javascript:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '26'
      - run: npm ci
      - run: npm test

  php:
    runs-on: ubuntu-latest

    services:
      nextcloud:
        image: nextcloud:latest
        env:
          SQLITE_DATABASE: nextcloud
          NEXTCLOUD_ADMIN_USER: admin
          NEXTCLOUD_ADMIN_PASSWORD: admin

    steps:
      - uses: actions/checkout@v3

      - name: Copy app to Nextcloud
        run: |
          docker cp . nextcloud:/var/www/html/custom_apps/moviedb

      - name: Install dependencies and run tests
        run: |
          docker exec nextcloud bash -c "
            cd /var/www/html/custom_apps/moviedb &&
            composer install &&
            php /var/www/html/occ app:enable moviedb &&
            ./vendor/bin/phpunit --testdox
          "
```

---

## Nextcloud Version Compatibility Testing

Before bumping `max-version` in `appinfo/info.xml` (and before an App Store
release), smoke-test the app against the target Nextcloud version in a throwaway
Docker container. This catches API removals between major NC versions (e.g. a
legacy `\OC::$server->...` method being deleted) that unit tests cannot.

### Prerequisites

Any Docker runtime works. On macOS without Docker Desktop, Colima is a
lightweight option:

```bash
brew install docker colima
colima start
docker ps   # verify the daemon is reachable
```

### Spin up a target NC version and install the app

Replace `34` with the Nextcloud major version you want to test.

```bash
# 1. Start Nextcloud (SQLite, no external DB needed for a smoke test)
docker run -d --name nc-test -p 8888:80 nextcloud:34

# 2. Wait ~25s for the entrypoint, then install with an admin user
docker exec -u www-data nc-test php occ maintenance:install \
  --admin-user=admin --admin-pass=admin_test_pw

# 3. Trust the local domain
docker exec -u www-data nc-test php occ config:system:set \
  trusted_domains 1 --value="localhost:8888"

# 4. Build + package the app (contents at the tarball root)
npm run build
tar -czf /tmp/moviedb.tar.gz \
  --exclude='node_modules' --exclude='.git' --exclude='package-lock.json' \
  --exclude='tests' --exclude='*.map' --exclude='.env' --exclude='.env.*' \
  -C /path/to/nextcloud-moviedb .

# 5. Deploy into the container
docker cp /tmp/moviedb.tar.gz nc-test:/tmp/moviedb.tar.gz
docker exec nc-test bash -c 'cd /var/www/html/custom_apps/ && \
  rm -rf moviedb && mkdir moviedb && \
  tar -xzf /tmp/moviedb.tar.gz -C moviedb && \
  chown -R www-data:www-data moviedb'

# 6. Enable it — this runs migrations and the min/max-version gate
docker exec -u www-data nc-test php occ app:enable moviedb
```

### Smoke test

1. Open `http://localhost:8888` (login `admin` / `admin_test_pw`).
2. Go to the MovieDB app. If a page throws **Internal Server Error**, inspect
   the log for the real cause:
   ```bash
   docker exec nc-test tail -20 /var/www/html/data/nextcloud.log
   ```
3. Configure a TMDB API key in Settings, then search + add a movie to exercise
   the TMDB proxy, DB writes, and CSP image domain.
4. Check the browser console for JS errors.

### Iterating on a PHP-only fix

You do not need to rebuild the whole tarball for PHP changes — copy the file
and clear caches:

```bash
docker cp lib/Controller/PageController.php \
  nc-test:/var/www/html/custom_apps/moviedb/lib/Controller/PageController.php
docker exec nc-test chown -R www-data:www-data /var/www/html/custom_apps/moviedb
docker exec -u www-data nc-test php occ maintenance:repair
```

### Cleanup

```bash
docker stop nc-test && docker rm nc-test
colima stop   # optional, frees the VM
```

> Gotcha: after swapping app files under a running server, PHP opcache can
> segfault Apache workers (`exit signal Segmentation fault (11)`). If a live
> instance misbehaves right after a deploy, restart the container (or reload
> PHP-FPM/Apache) to clear opcache.

---

## Fresh-Install Migration Testing (MANDATORY, separate from upgrade)

**A migration can pass on upgrade and still break a fresh install — test both.**
Nextcloud runs migrations by two different code paths:

- **Fresh install** (`Installer::installApp`, `previousVersion === ''`) →
  `MigrationService::migrate('latest', schemaOnly=true)` →
  `migrateSchemaOnly()`, which **accumulates every pending migration's
  `changeSchema` into one schema and applies it in a single batch, with NO
  `preSchemaChange`/`postSchemaChange` hooks between steps.**
- **Upgrade** (`occ upgrade`, non-empty previous version) → per-step
  `executeStep()` running pre → changeSchema → post for each migration in
  isolation.

The batched fresh-install path is why v1.2.0 could **not** drop the legacy watch
columns in the same release that created the watches table: on SQLite, a column
drop is implemented by rebuilding the table and copying data by explicit column
list; when a CREATE and a DROP of the same column land in one accumulated schema,
the generated copy SQL references the dropped column → `no such column`, aborting
the install. (Reproduces identically on NC 34 and NC 35 — it is version
independent.) The fix was to make v1.2.0 **additive-only** (retain the columns,
defer the drop to a later release's per-step upgrade). A drop must therefore only
ever be shipped in a release **separate from** the release that created the data,
and its fresh-install path must be tested explicitly.

Fresh install also exercises `SELECT` hydration against the *final* schema. Any
column left in a table that has no matching entity property will make
`QBMapper` → `Entity::fromRow` throw `BadFunctionCallException` (it calls a
setter for every selected column). Mappers must therefore `SELECT` an explicit
entity-backed column list, never `SELECT *`, once dead/legacy columns are
retained. **This class of bug does not surface in unit tests or in the upgrade
test — only a real fresh enable + a movie-list read triggers it.**

### Fresh-install test procedure

```bash
# On a clean NC container (see the compatibility section), drop all app tables +
# clear its appconfig/migrations so app:enable takes the fresh (batched) path:
docker exec nc-test php -r '
$db=new PDO("sqlite:/var/www/html/data/owncloud.db");
foreach(["oc_moviedb_episodes","oc_moviedb_series","oc_moviedb_movie_watches","oc_moviedb_movies","oc_moviedb_watchlist","oc_moviedb_platforms"] as $t){ $db->exec("DROP TABLE IF EXISTS $t"); }
$db->exec("DELETE FROM oc_appconfig WHERE appid=\"moviedb\"");
$db->exec("DELETE FROM oc_migrations WHERE app=\"moviedb\"");
'
# Deploy the NEW build, then a fresh enable — MUST NOT error ("no such column"):
docker exec -u www-data nc-test php occ app:enable moviedb

# Seed one movie WITH the retained legacy columns populated (worst case for
# hydration) + a watch row, one series + episodes, and one watchlist row of each
# media_type, then exercise every mapper read path in NC context:
#   MovieMapper::findAll (default + each sort + platform/genre filters), find,
#   findByTmdbId, every MovieWatchMapper aggregate (getTotalRuntime,
#   getAverageRating, getCountByPlatform, getCountByYear, findLatestPerMovie),
#   SeriesMapper::findAll/find, EpisodeMapper reads, and
#   WatchlistMapper::findAll (incl. the media_type filter) / findByTmdbId.
# All must return without throwing BadFunctionCallException — in particular the
# new moviedb_watchlist.media_type column must hydrate onto WatchlistItem.
```

Run the reads inside a bootstrapped script (`require '/var/www/html/lib/base.php'`,
`\OCP\Server::get(MovieMapper::class)`), not just via the UI — it pinpoints the
failing method. This is what caught three live-only bugs in v1.2.0 that unit
tests missed: `SELECT *` hydration on retained columns, a non-existent
`getDatabasePlatform()->getTableQuoteCharacter()` / `IDBConnection::getTablePrefix()`
call in the latest-watch join, and MySQL-only backtick identifiers in the stats
aggregates.

### Test on Postgres too — not just SQLite

SQLite is permissive; Postgres is strict and catches a whole class of bugs the
SQLite container silently passes:

- **Identifier quoting**: Postgres quotes with `"`, MySQL with `` ` ``. Any
  hand-written backtick in `createFunction` runs on SQLite/MySQL but errors on
  Postgres. Use `$qb->func()->*` / `expr()` / `castColumn()` instead of raw SQL.
- **Typed columns**: a `Types::JSON` column (e.g. `genre_ids`) maps to Postgres
  `json`, which has **no `LIKE` operator** (`operator does not exist: json ~~`).
  Cast with `$qb->expr()->castColumn('m.genre_ids', IQueryBuilder::PARAM_STR)`
  before `like()`. SQLite/MySQL store JSON as text and mask this.
- **DDL-in-transaction**: Postgres wraps DDL in a transaction, so a migration
  that throws rolls back cleanly (nothing half-applied); MySQL auto-commits DDL.
  Testing the upgrade on Postgres is the real proof the backfill+verify ordering
  is safe.

Bring up a Postgres-backed stack on a shared Docker network:

```bash
docker network create moviedb-pg-net
docker run -d --name nc-pg-db --network moviedb-pg-net \
  -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD=nc_test_pw postgres:16
sleep 8
docker run -d --name nc-pg --network moviedb-pg-net -p 8890:80 nextcloud:34
sleep 30
docker exec -u www-data nc-pg php occ maintenance:install \
  --database=pgsql --database-name=nextcloud --database-user=nextcloud \
  --database-pass=nc_test_pw --database-host=nc-pg-db \
  --admin-user=admin --admin-pass=admin_test_pw
```

> **Gotcha (harness only):** Nextcloud creates its own DB role (e.g. `oc_admin`)
> and runs migrations as that role. If you hand-seed the old-version tables via
> `psql -U nextcloud` (the superuser), those tables are owned by `nextcloud` and
> the migration's `ALTER TABLE` fails with `must be owner of table`. Reassign
> before upgrading: `ALTER TABLE oc_moviedb_movies OWNER TO oc_admin;`. This is a
> test-seeding artifact, not an app bug — a real 1.1.2 install already owns its
> tables as `oc_admin`.

---

## Upgrade Migration Testing (backfill migrations)

**Mandatory** whenever a release migrates existing data into a new shape. The
unit suite cannot cover migration backfill (migrations are pure DB I/O, excluded
from PHPUnit), so this container test is the only proof the upgrade is safe.
`occ upgrade` runs unattended in maintenance mode — there is no rollback window
for App Store users.

The goal: install the **previously released** build, seed realistic data that
exercises every backfill branch, upgrade to the **new** build, then assert the
data landed correctly. Upgrading across the **v1.1.1 → 1.3.0** chain runs, in
order, the V2 rewatch migration (backfills `moviedb_movie_watches` from the
legacy per-movie watch columns and **retains** those columns) then the V3 series
migration (adds `moviedb_series` + `moviedb_episodes`, makes the watches table's
`movie_id` nullable + adds `episode_id`/`series_id`, and adds
`moviedb_watchlist.media_type` defaulting existing rows to `'movie'`). A backfill
count mismatch throws and aborts the whole app update, leaving the source columns
intact — so a successful upgrade already means the verification passed.

### 1. Install the OLD released version

Build the old tag in a throwaway git worktree so the branch stays untouched.
Use the **last release before the change under test** — for the 1.3.0 chain that
is **v1.1.1** (the core migration ask), though **v1.2.0** is also a valid start
if you only want to exercise the V3 step:

```bash
git worktree add /tmp/moviedb-old v1.1.1   # oldest supported upgrade origin
(cd /tmp/moviedb-old && npm ci && npm run build)
```

Spin up NC and deploy the old build (same steps as the compatibility section —
here we used port `8899`, container `nc-upgrade-test`, `nextcloud:34`):

```bash
docker run -d --name nc-upgrade-test -p 8899:80 nextcloud:34
sleep 25
docker exec -u www-data nc-upgrade-test php occ maintenance:install \
  --admin-user=admin --admin-pass=admin_test_pw
tar -czf /tmp/moviedb-old.tar.gz --exclude='node_modules' --exclude='.git' \
  --exclude='package-lock.json' --exclude='tests' --exclude='*.map' \
  -C /tmp/moviedb-old .
docker cp /tmp/moviedb-old.tar.gz nc-upgrade-test:/tmp/old.tar.gz
docker exec nc-upgrade-test bash -c 'cd /var/www/html/custom_apps/ && \
  rm -rf moviedb && mkdir moviedb && tar -xzf /tmp/old.tar.gz -C moviedb && \
  chown -R www-data:www-data moviedb'
docker exec -u www-data nc-upgrade-test php occ app:enable moviedb
```

### 2. Seed data covering every backfill branch

Confirm the OLD schema first (`PRAGMA table_info(oc_moviedb_movies)`), then
insert rows that hit each path. For the V2 rewatch migration the branches
were: **full** watch data, **partial** (some fields null), and **none** (no
watch data → must produce no watch row). Also seed **watchlist** rows so the V3
`media_type` add is proven to backfill existing rows to `'movie'`. The table
prefix is `oc_`, the SQLite DB is `owncloud.db`, and **`sqlite3` CLI is not
installed in the container** — use PHP PDO via `docker exec`:

```bash
docker exec nc-upgrade-test php -r '
$db = new PDO("sqlite:/var/www/html/data/owncloud.db");
$db->exec("INSERT INTO oc_moviedb_movies
  (user_id,tmdb_id,title,date_watched,rating,review,platform_id,language_watched)
  VALUES (\"admin\",1,\"Full\",\"2024-01-15\",9,\"Mind-blowing\",1,\"en\")");
$db->exec("INSERT INTO oc_moviedb_movies
  (user_id,tmdb_id,title,rating,review) VALUES (\"admin\",2,\"Partial\",8,\"Layered\")");
$db->exec("INSERT INTO oc_moviedb_movies
  (user_id,tmdb_id,title) VALUES (\"admin\",3,\"None\")");
$db->exec("INSERT INTO oc_moviedb_watchlist
  (user_id,tmdb_id,title,priority,added_at) VALUES (\"admin\",100,\"Wishlist Movie\",5,\"2024-02-01 10:00:00\")");
echo "seeded\n";
'
```

Note the **expected counts**: 2 movies have watch data → 2 watch rows (the third
produces none), and 1 watchlist row that must survive and gain `media_type='movie'`.

### 3. Deploy the NEW build and upgrade

```bash
npm run build   # on the release branch
tar -czf /tmp/moviedb-new.tar.gz --exclude='node_modules' --exclude='.git' \
  --exclude='package-lock.json' --exclude='tests' --exclude='*.map' -C . .
docker cp /tmp/moviedb-new.tar.gz nc-upgrade-test:/tmp/new.tar.gz
docker exec nc-upgrade-test bash -c 'cd /var/www/html/custom_apps/moviedb && \
  rm -rf * && tar -xzf /tmp/new.tar.gz && chown -R www-data:www-data .'
docker exec -u www-data nc-upgrade-test php occ upgrade
docker exec -u www-data nc-upgrade-test php occ app:list | grep moviedb  # confirm new version
```

`occ upgrade` runs the migration (backfill + verification). A verification
failure throws and aborts the whole app update, leaving source columns intact —
so a successful upgrade already means the backfill count matched.

### 4. Assert the result (PDO, not sqlite3)

Verify, in one script: the watches table exists with the expected backfill count;
`moviedb_movies.media_type` added; the legacy movie columns are **retained**
(never dropped in this chain); the V3 tables (`moviedb_series`,
`moviedb_episodes`) exist; the watches table gained `episode_id`/`series_id` and
`movie_id` is nullable; **`moviedb_watchlist.media_type` exists and every migrated
row = `'movie'`**; each seeded value is preserved (nulls stay null); the "no data"
row produced no watch row; and no source rows were lost. Example for the
v1.1.1 → 1.3.0 chain:

```bash
docker exec nc-upgrade-test php -r '
$db = new PDO("sqlite:/var/www/html/data/owncloud.db");
$mcols = $db->query("PRAGMA table_info(oc_moviedb_movies)")->fetchAll(PDO::FETCH_COLUMN,1);
echo (in_array("media_type",$mcols)?"PASS":"FAIL")." movies.media_type added\n";
$legacy = array_intersect(["date_watched","rating","review","platform_id","language_watched"],$mcols);
echo (count($legacy)===5?"PASS":"FAIL")." legacy movie columns retained (".count($legacy)."/5)\n";
$wc = $db->query("SELECT COUNT(*) FROM oc_moviedb_movie_watches")->fetchColumn();
echo ($wc==2?"PASS":"FAIL")." watch rows == 2 (got $wc)\n";
$tables = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\"")->fetchAll(PDO::FETCH_COLUMN);
echo (in_array("oc_moviedb_series",$tables)?"PASS":"FAIL")." series table exists\n";
echo (in_array("oc_moviedb_episodes",$tables)?"PASS":"FAIL")." episodes table exists\n";
$wcols = $db->query("PRAGMA table_info(oc_moviedb_movie_watches)")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($wcols,"name");
echo (in_array("episode_id",$names)&&in_array("series_id",$names)?"PASS":"FAIL")." watches has episode_id+series_id\n";
$movieIdCol = array_values(array_filter($wcols,fn($c)=>$c["name"]==="movie_id"))[0];
echo ($movieIdCol["notnull"]==0?"PASS":"FAIL")." watches.movie_id nullable\n";
$plcols = $db->query("PRAGMA table_info(oc_moviedb_watchlist)")->fetchAll(PDO::FETCH_COLUMN,1);
echo (in_array("media_type",$plcols)?"PASS":"FAIL")." watchlist.media_type added\n";
$bad = $db->query("SELECT COUNT(*) FROM oc_moviedb_watchlist WHERE media_type != \"movie\"")->fetchColumn();
$wl = $db->query("SELECT COUNT(*) FROM oc_moviedb_watchlist")->fetchColumn();
echo ($wl==1 && $bad==0?"PASS":"FAIL")." watchlist rows survived + all media_type=movie ($wl row, $bad wrong)\n";
foreach($db->query("SELECT * FROM oc_moviedb_movie_watches ORDER BY movie_id") as $r){
  echo "  ".json_encode(array_intersect_key($r,array_flip([\"movie_id\",\"rating\",\"review\",\"platform_id\",\"watched_at\",\"language_watched\"])))."\n";
}
'
```

All assertions must print `PASS`. Then run the **fresh-install read-path test**
above against this same upgraded DB to confirm hydration works with the retained
columns present. Repeat on MySQL/Postgres if feasible.

### Cleanup

```bash
docker stop nc-upgrade-test && docker rm nc-upgrade-test
git worktree remove /tmp/moviedb-old
rm -f /tmp/moviedb-old.tar.gz /tmp/moviedb-new.tar.gz
```

---

## Test Commands Reference

### JavaScript Tests
```bash
npm test                          # Run all JS tests
npm run test:watch                # Watch mode
npm run test:coverage             # Generate coverage report
npm run test -- --reporter=verbose  # Verbose output
```

### PHP Tests (in Nextcloud environment)
```bash
composer test                     # Run all PHP tests
./vendor/bin/phpunit              # Same as above
./vendor/bin/phpunit --testdox    # Human-readable output
./vendor/bin/phpunit --filter MovieService  # Run specific test
./vendor/bin/phpunit tests/php/Unit/Service/  # Run service tests only
./vendor/bin/phpunit --coverage-html coverage/  # HTML coverage report (requires Xdebug)
```

---

## Development Workflow

### Recommended Setup for Active Development

1. **Have both environments ready:**
   - Standalone directory for code editing
   - Nextcloud instance with symlink to your code

2. **Create a symlink instead of copying:**
   ```bash
   # In Nextcloud's custom_apps directory
   ln -s /path/to/your/dev/nextcloud-moviedb /path/to/nextcloud/custom_apps/moviedb

   # Now changes in your dev directory are immediately reflected
   ```

3. **Development workflow:**
   ```bash
   # Terminal 1: Edit code in your dev directory
   cd /path/to/your/dev/nextcloud-moviedb

   # Terminal 2: Watch JS tests
   npm run test:watch

   # Terminal 3: Run PHP tests when needed
   cd /path/to/nextcloud/custom_apps/moviedb
   ./vendor/bin/phpunit --testdox
   ```

### Before Committing
```bash
# Run all checks
npm run lint                      # JavaScript linting
find lib/ -name "*.php" -print0 | xargs -0 -n1 php -l  # PHP syntax
npm test                          # JS tests
composer test                     # PHP tests (in Nextcloud)
```

---

## Test Coverage Goals

| Component | Current | Target | Status |
|-----------|---------|--------|--------|
| **Frontend (JS/Vue)** | ~45% | 70% | 🟡 In Progress |
| **Backend - Services** | 100% | 100% | ✅ Complete |
| **Backend - Controllers** | ~40% | 80% | 🟡 In Progress |
| **Backend - Mappers** | ~15% | 50% | 🟡 In Progress |

### Test File Count
- **JavaScript**: 8 files, 115 tests ✅
- **PHP**: 8 files, 88 tests ✅
- **Total**: 16 files, 203 tests

---

## Troubleshooting

### "Class OCP\* not found"
**Cause**: PHP tests not running inside Nextcloud environment.
**Solution**: Follow Option 1 or 2 above to run tests in Nextcloud context.

### "Cannot find module '@nextcloud/vue'"
**Cause**: Missing JavaScript dependencies.
**Solution**: Run `npm install`.

### "PHPUnit not found"
**Cause**: Missing PHP dependencies.
**Solution**: Run `composer install` inside the Nextcloud app directory.

### "App not found" when running tests
**Cause**: App not enabled in Nextcloud.
**Solution**: Run `php occ app:enable moviedb` from Nextcloud root.

### Tests pass locally but fail in CI
**Cause**: Different environment configuration.
**Solution**: Ensure CI uses same Nextcloud version and PHP version as your local setup.

### Permission denied errors
**Cause**: Wrong file ownership in Nextcloud.
**Solution**:
```bash
chown -R www-data:www-data /path/to/nextcloud/custom_apps/moviedb
```

---

## Tips for Fast Test Iteration

### 1. Use Watch Mode for Frontend
```bash
npm run test:watch
# Automatically reruns tests when you save files
```

### 2. Run Only Changed Tests
```bash
# PHP - run specific test class
./vendor/bin/phpunit --filter MovieServiceTest

# PHP - run specific test method
./vendor/bin/phpunit --filter testCreateWithFullData
```

### 3. Use Symlinks for PHP Development
Don't copy files back and forth - use symlinks so your dev directory is directly accessible in Nextcloud.

### 4. Skip Slow Tests During Development
```bash
# Run only fast unit tests, skip integration tests
./vendor/bin/phpunit tests/php/Unit/
```

---

## Additional Resources

- [Nextcloud App Development Docs](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Nextcloud App Testing Guide](https://docs.nextcloud.com/server/latest/developer_manual/app_development/testing.html)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Vitest Documentation](https://vitest.dev/)
- [Testing Vue 3 Applications](https://vuejs.org/guide/scaling-up/testing.html)

---

## Real-World Example: Other Nextcloud Apps

These popular apps use the same testing pattern:
- [Nextcloud Mail](https://github.com/nextcloud/mail) - See `tests/bootstrap.php`
- [Nextcloud Notes](https://github.com/nextcloud/notes) - See `tests/` directory
- [Nextcloud Deck](https://github.com/nextcloud/deck) - See test setup

Study their `tests/bootstrap.php` files to see how they load Nextcloud core.

---

## Summary: How to Run Tests

| Test Type | Command | Where to Run | Time |
|-----------|---------|--------------|------|
| **JavaScript** | `npm test` | Anywhere | ~1s |
| **PHP** | `composer test` | In Nextcloud | ~5s |
| **All Tests** | `npm test && composer test` | See above | ~6s |

**✨ Quick Tip:** Set up a symlink between your dev directory and Nextcloud's custom_apps for seamless testing!

---

## Post-Deploy Browser Smoke Check

After every deploy to a real Nextcloud instance, verify these five things manually
before calling a release good. They cover the two bugs that slipped into v1.2.0.

| # | Check | What to look for |
|---|-------|-----------------|
| 1 | **Dashboard stats non-zero** | Open the Dashboard tab — all counters (movies, runtime, avg rating, by year/platform) must show real numbers, not zeroes/errors. A 500 here is the filter-alias bug (`m.` prefix missing from `countAll`). |
| 2 | **Genre filter changes the list** | On the movie list, pick any genre from the filter bar — the grid must update and the count must drop below the unfiltered total. "No change" means `applyFilters` is still broken. |
| 3 | **Search changes the list** | Type a movie title fragment in the search box — only matching movies should appear. An unchanged list (or 500) is the same alias bug. |
| 4 | **Edit form shows pre-populated watch data** | Click Edit on a movie that has a review and rating — the review text, star rating, platform, and watch date must all pre-fill. An empty form means `MovieController::show` is returning the bare entity instead of calling `findWithLatestWatch`. |
| 5 | **Stats API returns 200** | Open the browser DevTools Network tab, reload the Dashboard — check that the `/api/stats` request returns HTTP 200, not 500. |

### How to run it

1. Open the app in the browser (`https://<your-nc>/apps/moviedb`).
2. Work through the table above top to bottom (takes ~2 minutes).
3. Only mark the release green after all five pass.

These checks complement, not replace, the automated test suite — the unit tests
verify logic; this checklist verifies the deployed integration with a real DB and
real Nextcloud middleware.

---

## TV Show / Series Smoke Check (v1.3.0)

Series live in `oc_moviedb_series` + `oc_moviedb_episodes` and share the watches
table (`oc_moviedb_movie_watches`, with nullable `movie_id` + `episode_id`/
`series_id`). Work through this after the movie checks.

| # | Check | What to look for |
|---|-------|-----------------|
| 1 | **Add a show** | Go to `/tv/add`, use the Movies/TV toggle, search a multi-season show (e.g. *Game of Thrones*). It imports with all seasons + episodes, **including specials** (season 0). No 500. |
| 2 | **Derived progress + "Up next"** | Open the show at `/tv/:id`. Progress shows 0% right after import; the "Up next" hint points at S1E1. |
| 3 | **Mark episode watched** | Mark one episode watched — progress ticks up, "Up next" advances. Marking the **same** episode again is idempotent (no duplicate watch row). |
| 4 | **Mark season / whole series watched** | Mark a full season, then the whole series — progress reaches 100%, all episodes flagged. |
| 5 | **Per-episode rewatch** | Re-mark an already-watched episode via the rewatch action — a new watch row is added, progress stays 100%. |
| 6 | **Delete series cascade** | Delete the show — its episodes and watch rows are removed (no orphans in `oc_moviedb_episodes` / watches). |
| 7 | **Dashboard TV tiles** | Dashboard shows non-zero *TV shows* and *episodes watched* tiles. No 500 on `/api/stats`. |

---

## Watchlist (Movies + TV) Smoke Check (v1.3.0)

The watchlist is unified: `oc_moviedb_watchlist.media_type` is `'movie'` or
`'series'`. A show entry represents the whole show; marking it watched **imports**
the series and removes the watchlist row.

| # | Check | What to look for |
|---|-------|-----------------|
| 1 | **Add a movie to the watchlist** | From the watchlist Add screen, keep the toggle on Movies, add a film. It appears with a **"Movie"** badge. |
| 2 | **Add a show to the watchlist** | Flip the toggle to TV, add a show. It appears with a **"TV"** badge. |
| 3 | **Type filter** | The type filter (All / Movies / TV Shows) narrows the list correctly; count drops when filtered. |
| 4 | **Mark a movie watched** | "Mark as Watched" on the movie opens the platform/date/rating dialog, then moves it to watched movies; the watchlist row is gone. |
| 5 | **Mark a show watched** | "Add to TV Shows" on the show **imports the series** (no rating/date dialog), routes to `/tv/:id` at 0% progress, and the watchlist row is gone. |
| 6 | **Counts stay type-agnostic** | The Dashboard "In Watchlist" tile and the nav counter bubble count movies + shows **together** and don't error before/after either mark-watched. |
