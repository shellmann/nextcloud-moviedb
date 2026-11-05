#!/usr/bin/env bash
#
# Upgrade-path check: installs an OLD MovieDB release on Nextcloud 35, seeds a
# few rows, swaps in the code of this checkout (HEAD), runs `occ upgrade` and
# then `occ db:schema:check`. Fails on any `moviedb` finding or lost rows.
# Finally it calls the app's API as the admin (seed watches, read the dashboard
# stats) and fails on a non-2xx answer or a moviedb/SQL error in nextcloud.log.
#
# Why: every other test starts from a fresh install, which already has the
# corrected schema. Only an upgrade shows drift left behind by older releases
# (see Version000011, "nullable" findings after the NC 35 upgrade). The API
# smoke test runs real queries on each database: a PostgreSQL-only SQL error in
# the "Watches by Year" stats (#104) shipped because no job did.
#
# Usage: tests/upgrade/check-upgrade.sh <from-ref|latest> <sqlite|mysql|pgsql>
#   from-ref  git tag of the release to start from (e.g. v1.5.2), or "latest"
#             for the newest v* tag
# Needs docker, jq and a git checkout with tags (fetch-depth: 0).
set -euo pipefail

FROM_REF=${1:?from-ref (tag or "latest")}
DB=${2:?db (sqlite, mysql or pgsql)}
NC_IMAGE=${NC_IMAGE:-nextcloud:35}
MARIADB_IMAGE=${MARIADB_IMAGE:-mariadb:10.11}
# Nextcloud AIO ships PostgreSQL 16.
POSTGRES_IMAGE=${POSTGRES_IMAGE:-postgres:16}

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

if [ "$FROM_REF" = "latest" ]; then
	FROM_REF=$(git tag --list 'v*' --sort=-v:refname | head -n1)
fi
git rev-parse --verify --quiet "$FROM_REF^{commit}" >/dev/null || { echo "Unknown ref: $FROM_REF" >&2; exit 2; }

SUFFIX=$$
NET=moviedb-upgrade-$SUFFIX
NC=moviedb-nc-$SUFFIX
DBC=moviedb-db-$SUFFIX
ADMIN_PASSWORD=$(openssl rand -hex 16)
DB_PASSWORD=$(openssl rand -hex 16)

cleanup() {
	docker rm -f "$NC" "$DBC" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

occ() { docker exec -u www-data "$NC" php occ "$@"; }
fail() { echo "::error::$*" >&2; exit 1; }

echo "== MovieDB upgrade check: $FROM_REF -> HEAD on $NC_IMAGE ($DB)"
docker network create "$NET" >/dev/null

wait_for_db() { # usage: wait_for_db <readiness command run in the database container>
	for _ in $(seq 1 60); do
		docker exec "$DBC" "$@" >/dev/null 2>&1 && return 0
		sleep 2
	done
	docker logs "$DBC" 2>&1 | tail -30
	fail "The $DB container ($DBC) was not ready after 120 s"
}

NC_ENV=(-e NEXTCLOUD_ADMIN_USER=admin -e "NEXTCLOUD_ADMIN_PASSWORD=$ADMIN_PASSWORD")
if [ "$DB" = "mysql" ]; then
	docker run -d --name "$DBC" --network "$NET" \
		-e "MARIADB_ROOT_PASSWORD=$DB_PASSWORD" -e MARIADB_DATABASE=nextcloud "$MARIADB_IMAGE" >/dev/null
	wait_for_db healthcheck.sh --connect --innodb_initialized
	NC_ENV+=(-e "MYSQL_HOST=$DBC" -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=root -e "MYSQL_PASSWORD=$DB_PASSWORD")
elif [ "$DB" = "pgsql" ]; then
	docker run -d --name "$DBC" --network "$NET" \
		-e POSTGRES_USER=postgres -e "POSTGRES_PASSWORD=$DB_PASSWORD" -e POSTGRES_DB=nextcloud "$POSTGRES_IMAGE" >/dev/null
	# The image restarts the server once after its init scripts; wait until it answers over TCP.
	wait_for_db pg_isready -h 127.0.0.1 -U postgres -d nextcloud
	NC_ENV+=(-e "POSTGRES_HOST=$DBC" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=postgres -e "POSTGRES_PASSWORD=$DB_PASSWORD")
elif [ "$DB" = "sqlite" ]; then
	NC_ENV+=(-e SQLITE_DATABASE=nextcloud)
else
	fail "Unknown db: $DB"
fi

docker run -d --name "$NC" --network "$NET" "${NC_ENV[@]}" "$NC_IMAGE" >/dev/null

echo "== Waiting for the Nextcloud install"
installed=0
for _ in $(seq 1 150); do
	if occ status 2>/dev/null | grep -q "installed: true"; then installed=1; break; fi
	sleep 4
done
[ "$installed" = 1 ] || { docker logs "$NC" | tail -30; fail "Nextcloud did not install in time"; }
# "installed" shows up while the entrypoint may still write to the database (SQLite then reports "database is locked").
sleep 10

echo "== Installing MovieDB $FROM_REF"
git archive --prefix=moviedb/ "$FROM_REF" | docker exec -i "$NC" tar -x -C /var/www/html/custom_apps
docker exec "$NC" chown -R www-data:www-data /var/www/html/custom_apps/moviedb
occ app:enable moviedb

# Seed through PDO so the script does not depend on any client binary in the image.
run_sql() { # usage: run_sql <sql> -> prints the first column of every row
	docker exec -i -e DB_KIND="$DB" -e DB_HOST="$DBC" -e DB_PASS="$DB_PASSWORD" -e SQL="$1" "$NC" php -r '
		try {
			$pdo = match (getenv("DB_KIND")) {
				"sqlite" => new PDO("sqlite:/var/www/html/data/nextcloud.db"),
				"pgsql" => new PDO("pgsql:host=" . getenv("DB_HOST") . ";dbname=nextcloud", "postgres", getenv("DB_PASS")),
				default => new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=nextcloud", "root", getenv("DB_PASS")),
			};
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $pdo->query(getenv("SQL"));
			if ($stmt !== false && $stmt->columnCount() > 0) {
				foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) { echo $row[0], PHP_EOL; }
			}
		} catch (Throwable $e) {
			fwrite(STDERR, "SQL failed: " . $e->getMessage() . PHP_EOL . getenv("SQL") . PHP_EOL);
			exit(1);
		}
	'
}

echo "== Seeding rows"
run_sql "INSERT INTO oc_moviedb_libraries (owner, name, is_personal, created_at) VALUES ('admin', 'Upgrade check', TRUE, '2026-01-01 00:00:00')"
run_sql "INSERT INTO oc_moviedb_watchlist (user_id, library_id, title, tmdb_id, added_at, priority) VALUES ('admin', (SELECT MIN(id) FROM oc_moviedb_libraries), 'Upgrade seed', 1, '2026-01-01 00:00:00', 0)"
# SQLite accepted "" (TMDB's date for unreleased titles) in DATE columns; Version000012 repairs such rows
# once, so this only applies when the old release predates that migration.
REPAIR_CHECK=false
OLD_MIGRATIONS=$(git ls-tree --name-only "$FROM_REF" lib/Migration/)
if [ "$DB" = "sqlite" ] && [[ "$OLD_MIGRATIONS" != *"/Version000012"* ]]; then
	REPAIR_CHECK=true
fi
if [ "$REPAIR_CHECK" = true ]; then
	run_sql "INSERT INTO oc_moviedb_watchlist (user_id, library_id, title, tmdb_id, added_at, priority, release_date) VALUES ('admin', (SELECT MIN(id) FROM oc_moviedb_libraries), 'Upgrade seed empty date', 2, '2026-01-01 00:00:00', 0, '')"
fi

echo "== Swapping in this checkout and upgrading"
git archive --prefix=moviedb/ HEAD | docker exec -i "$NC" sh -c 'rm -rf /var/www/html/custom_apps/moviedb && tar -x -C /var/www/html/custom_apps'
# The version has to change for `occ upgrade` to run the app's migrations (HEAD can equal the latest tag).
docker exec "$NC" sed -i 's|<version>[^<]*</version>|<version>99.0.0</version>|' /var/www/html/custom_apps/moviedb/appinfo/info.xml
docker exec "$NC" chown -R www-data:www-data /var/www/html/custom_apps/moviedb
# A busy SQLite file can still make the first attempt fail with "database is locked"; the upgrade is safe to repeat.
for attempt in 1 2 3; do
	if UPGRADE_OUT=$(occ upgrade 2>&1); then
		echo "$UPGRADE_OUT"
		break
	fi
	echo "$UPGRADE_OUT"
	if [ "$attempt" = 3 ] || ! echo "$UPGRADE_OUT" | grep -qi "database is locked"; then
		fail "occ upgrade failed"
	fi
	echo "== database is locked, retrying the upgrade in 10 s"
	sleep 10
done

echo "== occ db:schema:check"
set +e
CHECK=$(occ db:schema:check 2>&1)
CHECK_RC=$?
set -e
echo "$CHECK"
if echo "$CHECK" | grep -qi "not defined"; then
	fail "occ db:schema:check is not available on $NC_IMAGE"
fi
if echo "$CHECK" | grep -i "moviedb"; then
	fail "occ db:schema:check reports differences for moviedb tables (see above), exit code $CHECK_RC"
fi

echo "== Checking the data"
[ "$(run_sql "SELECT COUNT(*) FROM oc_moviedb_watchlist WHERE title = 'Upgrade seed'")" = "1" ] || fail "The seeded watchlist row is gone"
[ "$(run_sql "SELECT COUNT(*) FROM oc_moviedb_libraries WHERE name = 'Upgrade check'")" -ge 1 ] || fail "The seeded library row is gone"
if [ "$REPAIR_CHECK" = true ]; then
	[ "$(run_sql "SELECT COUNT(*) FROM oc_moviedb_watchlist WHERE release_date = ''")" = "0" ] || fail "An empty release date was not repaired"
	[ "$(run_sql "SELECT COUNT(*) FROM oc_moviedb_watchlist WHERE title = 'Upgrade seed empty date' AND release_date IS NULL")" = "1" ] || fail "The row with an empty release date is gone or was not set to NULL"
fi
run_sql "SELECT release_date_changed_at FROM oc_moviedb_watchlist" >/dev/null || fail "oc_moviedb_watchlist.release_date_changed_at is missing"

echo "== API smoke test"
# Only log lines written from here on count; install and upgrade may log on their own.
LOG=/var/www/html/data/nextcloud.log
LOG_START=$(docker exec "$NC" sh -c "[ -f $LOG ] && wc -l < $LOG || echo 0")
API_BODY=
api() { # usage: api <method> <path> [json body] -> sets API_BODY, fails on a non-2xx answer
	local method=$1 path=$2 data=${3:-} out code
	local args=(-sS -w '\n%{http_code}' -X "$method" -u "admin:$ADMIN_PASSWORD"
		-H 'OCS-APIRequest: true' -H 'Accept: application/json')
	if [ -n "$data" ]; then
		args+=(-H 'Content-Type: application/json' --data "$data")
	fi
	out=$(docker exec "$NC" curl "${args[@]}" "http://localhost/index.php/apps/moviedb$path") \
		|| fail "$method $path: curl failed"
	code=${out##*$'\n'}
	API_BODY=${out%$'\n'*}
	echo "$method $path -> $code"
	case "$code" in
		2??) ;;
		*) echo "$API_BODY" | head -c 2000; echo; fail "$method $path answered HTTP $code" ;;
	esac
}

api POST /api/movies '{"title":"Smoke seed A","dateWatched":"2024-03-15","rating":8}'
api POST /api/movies '{"title":"Smoke seed B","dateWatched":"2024-11-02"}'
api POST /api/movies '{"title":"Smoke seed C","dateWatched":"2025-07-01","rating":6}'

# Every endpoint the dashboard loads; /api/stats/years backs "Watches by Year".
api GET /api/stats
api GET /api/stats/years
YEARS=$API_BODY
api GET '/api/stats/years?mediaType=movie'
api GET '/api/stats/years?mediaType=series'
api GET /api/stats/platforms
api GET /api/stats/genres
api GET /api/stats/recent
api GET /api/stats/top-rated

# The list endpoints run the other hand-written SQL: correlated subqueries for the latest
# rating and language (MovieMapper), derived-table joins and CASE ordering (SeriesMapper).
# PostgreSQL checks types when it plans a query, so this catches errors even on empty tables.
api GET /api/movies
api GET /api/series
api GET /api/series/continue
api GET /api/series/upcoming
api GET /api/watchlist
api GET /api/quiz/meta
api GET /api/libraries

echo "/api/stats/years: $YEARS"
echo "$YEARS" | jq -e '.years["2024"] == 2 and .years["2025"] == 1' >/dev/null \
	|| fail "/api/stats/years does not count the seeded watches (2024: 2, 2025: 1)"

NEW_LOG=$(docker exec "$NC" sh -c "[ -f $LOG ] && tail -n +$((LOG_START + 1)) $LOG || true")
# Error (3) and fatal (4) entries that mention the app or the database layer (DBAL exceptions name Doctrine).
LOG_ERRORS=$(echo "$NEW_LOG" | grep -E '"level":[34]' | grep -iE 'moviedb|SQLSTATE|Doctrine|DBAL' || true)
if [ -n "$LOG_ERRORS" ]; then
	echo "$LOG_ERRORS" | jq -r '"\(.level) \(.app): \(.message)\n  \(.exception.Message // "" )"' 2>/dev/null || echo "$LOG_ERRORS"
	fail "nextcloud.log has moviedb or SQL errors from the API smoke test (see above)"
fi

echo "== OK: $FROM_REF -> HEAD ($DB)"
