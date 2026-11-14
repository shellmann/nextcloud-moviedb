# Testing MovieDB

| What | Command | Needs | Runs in CI |
|------|---------|-------|------------|
| JavaScript/Vue tests | `npm test` | Node.js | every PR and push to `main` (`ci.yml`) |
| PHP unit tests | `composer test` | PHP 8.3+, Composer | every PR and push to `main` (`ci.yml`) |
| Upgrade check + API smoke test | `tests/upgrade/check-upgrade.sh latest pgsql` | Docker, jq | every PR and push to `main` (`upgrade-check.yml`) |
| Lint, translations, build | `npm run lint`, `npm run check:translations`, `npm run build` | Node.js | every PR and push to `main` (`ci.yml`) |

None of them needs a Nextcloud installation on your machine.

---

## JavaScript/Vue tests

The frontend tests use **Vitest** with **jsdom** and live in `tests/js/`
(`components/`, `views/`, `stores/`, `services/`, `styles/`, `utils/`; `setup.js` configures
the environment).

```bash
npm install
npm test                  # run once
npm run test:watch        # re-run on file changes
npm run test:coverage     # coverage report
npx vitest run tests/js/stores/movies.spec.js   # a single file
```

`tests/js/utils/releaseConsistency.spec.js` also checks that `appinfo/info.xml`,
`package.json`, `CHANGELOG.md`, the What's new entry and the supported-versions
table in `SECURITY.md` agree on the version, so a release PR fails if one of
them was missed.

---

## PHP unit tests

The PHP tests live in `tests/php/Unit/` and run **standalone**:
`tests/php/bootstrap.php` loads `tests/php/NextcloudStubs.php`, which stubs the
`OCP\*` classes the app uses, so no Nextcloud core is needed.

```bash
composer install
composer test                                     # all tests
./vendor/bin/phpunit --testdox                    # readable output
./vendor/bin/phpunit --filter MovieServiceTest    # one class
./vendor/bin/phpunit --filter testCreateWithFullData   # one method
```

If a test needs a Nextcloud class or interface that is not stubbed yet, add a
minimal stub to `NextcloudStubs.php`.

Migrations (`lib/Migration/`) have no unit tests and are excluded from coverage
in `phpunit.xml`; they are pure database I/O and are covered by the upgrade check
below.

---

## Upgrade check and API smoke test

`tests/upgrade/check-upgrade.sh <from-ref|latest> <sqlite|mysql|pgsql>` does what
an App Store user's update does, on a real database:

1. Starts `nextcloud:35` (or the image in `NC_IMAGE`, e.g. `NC_IMAGE=nextcloud:33`)
   with SQLite, MariaDB 10.11 or PostgreSQL 16.
2. Installs the old release `<from-ref>` (`latest` = the newest `v*` tag) and
   seeds a few rows.
3. Swaps in the code of `HEAD` and runs `occ upgrade`, then
   `occ db:schema:check` (Nextcloud 35 and later; older versions don't have it).
   It fails on any `moviedb` schema finding or lost rows.
4. Calls the app's API as the admin: it adds watches with `POST /api/movies`,
   then reads every dashboard stats endpoint and the list endpoints.
5. Runs the hourly `ReleaseNotificationJob`, a CalDAV `REPORT` on the
   "MovieDB – Upcoming" calendar and the core upcoming-events API
   (`/ocs/v2.php/apps/dav/api/v1/events/upcoming`), which guards the dual-mode
   `UpcomingCalendar::search()`.

It fails on a non-2xx answer, wrong year counts, or a moviedb/SQL error in
`nextcloud.log`.

```bash
tests/upgrade/check-upgrade.sh latest pgsql
tests/upgrade/check-upgrade.sh v1.5.2 mysql
NC_IMAGE=nextcloud:33 tests/upgrade/check-upgrade.sh latest sqlite
```

It uses `git archive HEAD`, so **commit first**: uncommitted changes are not
tested. It needs Docker, jq and the git tags (`git fetch --tags`).

In CI, `upgrade-check.yml` runs it for `v1.5.2` and `latest` on all three
databases, plus `latest` on SQLite and PostgreSQL with Nextcloud 33, the oldest
supported version. The eight jobs are named `Upgrade from <ref> (<db>)` and
`Upgrade from latest (<db>) on Nextcloud 33`, and `main` requires them to pass
(a renamed or added job must go into the "Protect main" ruleset as well).

**When a release migrates existing data** (a backfill, a type change, a repair),
extend the script in the same PR: add seed rows that hit every branch of the
migration under `== Seeding rows`, and assertions under `== Checking the data`.
`occ upgrade` runs unattended in maintenance mode, so there is no rollback
window for App Store users.

**When you add or change a query in a mapper**, make sure the API smoke test
calls an endpoint that runs it.

### Why PostgreSQL matters

SQLite is permissive; PostgreSQL is strict and catches bugs the other two pass:

- **Functions and types are checked when the query is planned**: e.g.
  `SUBSTR(watched_at, 1, 4)` on a `DATE` column errors on PostgreSQL (#104) but
  works on SQLite and MySQL. Use `$qb->func()->*`, `expr()` or a
  provider-specific expression.
- **Identifier quoting**: PostgreSQL quotes with `"`, MySQL with `` ` ``. A
  hand-written backtick in `createFunction` breaks PostgreSQL.
- **Typed columns**: a `Types::JSON` column (e.g. `genre_ids`) is `json` on
  PostgreSQL, which has **no `LIKE` operator**. Cast with
  `$qb->expr()->castColumn('m.genre_ids', IQueryBuilder::PARAM_STR)` first.
- **Booleans**: write `TRUE`/`FALSE` in raw SQL, not `1`/`0`.
- **No comparing across types**: `created_at < air_date` (a `DATETIME` and a
  `STRING` column) fails with `operator does not exist` on PostgreSQL. Compare
  such values in PHP, as `ReleaseNotificationService` does.
- **DDL in transactions**: PostgreSQL rolls back a failed migration cleanly;
  MySQL auto-commits DDL and can leave it half-applied.

---

## Manual testing in a Nextcloud container

Use this before bumping `max-version` in `appinfo/info.xml`, to reproduce a bug,
or to click through a UI change. Any Docker runtime works; on macOS without
Docker Desktop, `brew install docker colima && colima start` is enough.

### Start Nextcloud and install the app

Replace `35` with the Nextcloud major version you want to test.

```bash
# 1. Start Nextcloud and install it with SQLite
docker run -d --name nc-test -p 8888:80 nextcloud:35
sleep 25
docker exec -u www-data nc-test php occ maintenance:install \
  --database=sqlite --database-name=nextcloud \
  --admin-user=admin --admin-pass=admin_test_pw
docker exec -u www-data nc-test php occ config:system:set \
  trusted_domains 1 --value="localhost:8888"

# 2. Build and package the app (contents at the tarball root)
npm run build
tar -czf /tmp/moviedb.tar.gz \
  --exclude='node_modules' --exclude='vendor' --exclude='.git' --exclude='package-lock.json' \
  --exclude='tests' --exclude='*.map' --exclude='.env' --exclude='.env.*' .

# 3. Deploy it into the container and enable it
docker cp /tmp/moviedb.tar.gz nc-test:/tmp/moviedb.tar.gz
docker exec nc-test bash -c 'cd /var/www/html/custom_apps/ && \
  rm -rf moviedb && mkdir moviedb && \
  tar -xzf /tmp/moviedb.tar.gz -C moviedb && \
  chown -R www-data:www-data moviedb'
docker exec -u www-data nc-test php occ app:enable moviedb
```

Open `http://localhost:8888` (login `admin` / `admin_test_pw`), set a TMDB API
key in the MovieDB settings and add a movie. If a page shows **Internal Server
Error**, the log has the cause:

```bash
docker exec nc-test tail -20 /var/www/html/data/nextcloud.log
```

For a PHP-only change, copy the file instead of rebuilding:

```bash
docker cp lib/Controller/PageController.php \
  nc-test:/var/www/html/custom_apps/moviedb/lib/Controller/PageController.php
docker exec nc-test chown -R www-data:www-data /var/www/html/custom_apps/moviedb
docker exec -u www-data nc-test php occ maintenance:repair
```

> After swapping app files under a running server, PHP opcache can segfault
> Apache workers (`exit signal Segmentation fault (11)`). Restart the container
> if the instance misbehaves right after a deploy.

Clean up with `docker rm -f nc-test` (and `colima stop`).

### Fresh-install migration testing

**A migration can pass on upgrade and still break a fresh install.** Nextcloud
runs migrations by two different code paths:

- **Fresh install** (`previousVersion === ''`) →
  `MigrationService::migrate('latest', schemaOnly=true)`, which **accumulates
  every pending migration's `changeSchema` into one schema and applies it in a
  single batch, with no `preSchemaChange`/`postSchemaChange` hooks between
  steps.**
- **Upgrade** (`occ upgrade`) → each migration runs pre → changeSchema → post
  on its own.

That is why a column must never be dropped in the same release that created it:
on SQLite a column drop rebuilds the table and copies data by an explicit column
list, and when a CREATE and a DROP of the same column land in one batch, the copy
SQL references the dropped column (`no such column`) and the install aborts. Ship
a drop in a **later** release than the one that created the data.

Fresh installs also hydrate against the *final* schema: any column without a
matching entity property makes `Entity::fromRow` throw
`BadFunctionCallException`. Mappers therefore select an explicit,
entity-backed column list, never `SELECT *`.

No CI job installs the app fresh, so test a migration change by hand on a clean
container (see above): drop the app's tables and migration records, then enable
the new build.

```bash
docker exec nc-test php -r '
$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
foreach ($db->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name LIKE \"oc_moviedb_%\"")->fetchAll(PDO::FETCH_COLUMN) as $t) { $db->exec("DROP TABLE $t"); }
$db->exec("DELETE FROM oc_appconfig WHERE appid=\"moviedb\"");
$db->exec("DELETE FROM oc_migrations WHERE app=\"moviedb\"");
'
# Deploy the new build (step 3 above), then:
docker exec -u www-data nc-test php occ app:enable moviedb   # must not fail
```

Then add a movie, a TV show and a watchlist entry and open every page.

To run the same on PostgreSQL, start `postgres:16` on a shared Docker network and
pass `--database=pgsql --database-host=<container> --database-name=nextcloud
--database-user=… --database-pass=…` to `maintenance:install`;
`tests/upgrade/check-upgrade.sh` shows the full setup.

---

## Manual UI check before a release

The automated tests do not click through the UI. Before a release, on a test
instance with a few movies, shows and watchlist entries:

| # | Area | Check |
|---|------|-------|
| 1 | Dashboard | All tiles and charts show numbers, the movies/TV filter on the charts works, and no request in the browser's Network tab returns 500. |
| 2 | Movies | Search, the genre and platform filters and sorting change the list. Editing a movie pre-fills rating, review, platform, language and date. |
| 3 | TV shows | Adding a show imports all seasons. Ticking an episode, "Mark season watched" and "Mark series watched" update the progress and "Up next". "Update from TMDB" works. |
| 4 | Watchlist | Movies and shows can be added, the type filter works, "Mark as Watched" moves a movie to the library and "Add to TV Shows" imports a show. The random picker opens. |
| 5 | Quiz, libraries, import/export | A quiz round finishes and appears in the history. A shared library can be switched to. An export re-imports into an empty library. |
| 6 | Theme and language | Text stays readable in dark mode, and a non-English language shows no English strings. |
| 7 | Notifications and calendar | With an episode airing today, running the `ReleaseNotificationJob` (`occ background-job:execute <id> --force-execute`) notifies the owner and members once; the link opens the show in the right library. "MovieDB – Upcoming" shows in the Calendar app, read-only. Both switches in Settings work. |
| 8 | Keyboard and screen width | New controls are reachable with Tab and named for screen readers; Settings and the TV show page fit 320 px without horizontal scrolling. |
| 9 | Browser console | No JavaScript errors. |

---

## Before you push

```bash
npm run lint
npm run check:translations
npm test
composer test
npm run build
tests/upgrade/check-upgrade.sh latest pgsql   # for changes to migrations or queries
```

## Further reading

- [Nextcloud app development](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Vitest](https://vitest.dev/) and [PHPUnit](https://phpunit.de/documentation.html)
