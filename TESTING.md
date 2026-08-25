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
- `stores/movies.spec.js` - Tests for movies Pinia store (20 tests)
- `components/MovieCard.spec.js` - Tests for MovieCard component (9 tests)
- `utils/formatters.spec.js` - Tests for utility functions (8 tests)
- `setup.js` - Test environment configuration

### Example Output
```
✓ tests/js/stores/movies.spec.js (20)
✓ tests/js/components/MovieCard.spec.js (9)
✓ tests/js/utils/formatters.spec.js (8)

Test Files  3 passed (3)
Tests  37 passed (37)
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
 ... (11 tests)

Platform Service (OCA\MovieDB\Tests\Unit\Service\PlatformService)
 ... (8 tests)

Tests: 39, Assertions: 80+
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
foreach(["oc_moviedb_movie_watches","oc_moviedb_movies","oc_moviedb_watchlist","oc_moviedb_platforms"] as $t){ $db->exec("DROP TABLE IF EXISTS $t"); }
$db->exec("DELETE FROM oc_appconfig WHERE appid=\"moviedb\"");
$db->exec("DELETE FROM oc_migrations WHERE app=\"moviedb\"");
'
# Deploy the NEW build, then a fresh enable — MUST NOT error ("no such column"):
docker exec -u www-data nc-test php occ app:enable moviedb

# Seed one movie WITH the retained legacy columns populated (worst case for
# hydration) + a watch row, then exercise every mapper read path in NC context:
#   MovieMapper::findAll (default + each sort + platform/genre filters), find,
#   findByTmdbId, and every MovieWatchMapper aggregate (getTotalRuntime,
#   getAverageRating, getCountByPlatform, getCountByYear, findLatestPerMovie).
# All must return without throwing BadFunctionCallException / undefined-method.
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
data landed correctly. For v1.2.0 the migration is **additive** — it backfills
the new `moviedb_movie_watches` table from the legacy per-movie watch columns
and **retains** those columns (the drop is deferred to a later release; see the
fresh-install section for why). A backfill count mismatch throws and aborts the
whole app update, leaving the source columns intact — so a successful upgrade
already means the verification passed.

### 1. Install the OLD released version

Build the old tag in a throwaway git worktree so the branch stays untouched:

```bash
git worktree add /tmp/moviedb-old v1.1.2   # the last released tag
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
insert rows that hit each path. For the v1.2.0 rewatch migration the branches
were: **full** watch data, **partial** (some fields null), and **none** (no
watch data → must produce no watch row). The table prefix is `oc_`, the SQLite
DB is `owncloud.db`, and **`sqlite3` CLI is not installed in the container** —
use PHP PDO via `docker exec`:

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
echo "seeded\n";
'
```

Note the **expected backfill count** (here: 2 movies have watch data → 2 watch
rows; the third must produce none).

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

Verify, in one script: the new table exists; new columns added (`media_type`);
the legacy columns are **retained** (v1.2.0 does not drop them); backfill row
count matches the expected number; each seeded value is preserved (including
nulls staying null); the "no data" row produced no watch row; and no source rows
were lost. Example for v1.2.0:

```bash
docker exec nc-upgrade-test php -r '
$db = new PDO("sqlite:/var/www/html/data/owncloud.db");
$cols = $db->query("PRAGMA table_info(oc_moviedb_movies)")->fetchAll(PDO::FETCH_COLUMN,1);
echo (in_array("media_type",$cols)?"PASS":"FAIL")." media_type added\n";
$legacy = array_intersect(["date_watched","rating","review","platform_id","language_watched"],$cols);
echo (count($legacy)===5?"PASS":"FAIL")." legacy columns retained (".count($legacy)."/5)\n";
$wc = $db->query("SELECT COUNT(*) FROM oc_moviedb_movie_watches")->fetchColumn();
echo ($wc==2?"PASS":"FAIL")." watch rows == 2 (got $wc)\n";
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
| **Frontend (JS/Vue)** | ~30% | 70% | 🟡 In Progress |
| **Backend - Services** | 100% | 100% | ✅ Complete |
| **Backend - Controllers** | 10% | 80% | 🟡 Planned |
| **Backend - Mappers** | 0% | 50% | ⚪ Not Started |

### Test File Count
- **JavaScript**: 3 files, 37 tests ✅
- **PHP**: 4 files, 39 tests ✅
- **Total**: 7 files, 76 tests

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
