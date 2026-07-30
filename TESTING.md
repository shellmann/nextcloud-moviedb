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
