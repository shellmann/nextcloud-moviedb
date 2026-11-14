# Contributing to MovieDB

Thank you for your interest in contributing to MovieDB! This document provides guidelines and instructions for contributing.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By taking part, you agree to uphold it. Please report unacceptable behavior as described there.

## Where to Start

- **Questions and ideas**: ask in [Discussions](https://github.com/shellmann/nextcloud-moviedb/discussions)
- **Bugs and feature requests**: open an issue with one of the [issue forms](https://github.com/shellmann/nextcloud-moviedb/issues/new/choose)
- **Security vulnerabilities**: never in a public issue, see [SECURITY.md](SECURITY.md)
- **First contribution?** Issues labeled [`good first issue`](https://github.com/shellmann/nextcloud-moviedb/labels/good%20first%20issue) are a good place to begin. Comment on the issue before you start, so nobody works on the same thing twice

## Getting Started

### Prerequisites
- PHP 8.3 or higher
- Node.js 26+ and npm (see `.nvmrc`; CI runs on Node 26)
- Composer
- Git
- Docker and jq, only for the upgrade check and for trying the app in Nextcloud

### Development Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/shellmann/nextcloud-moviedb.git
   cd nextcloud-moviedb
   ```

2. **Install dependencies**
   ```bash
   # PHP dependencies
   composer install

   # JavaScript dependencies
   npm install
   ```

3. **Build the app**
   ```bash
   # Development build with watch mode
   npm run watch

   # Production build
   npm run build
   ```

## Development Workflow

### Running Tests

```bash
npm test                 # JavaScript/Vue tests
composer test            # PHP unit tests, no Nextcloud installation needed
tests/upgrade/check-upgrade.sh latest pgsql   # upgrade + API check in Docker, for migration or query changes
```

[TESTING.md](TESTING.md) explains each of them and how to try the app in a Nextcloud container.

### Code Quality

Before submitting a pull request, ensure your code passes all checks:

```bash
npm run lint                 # ESLint (npm run lint:fix to fix)
npm run check:translations   # every string translated
find lib/ -name "*.php" -print0 | xargs -0 -n1 php -l   # PHP syntax
npm run build
```

CI runs all of these, plus the upgrade check on SQLite, MariaDB and PostgreSQL (Nextcloud 35, and 33 as the oldest supported version), on every pull request.

### Code Style

- **PHP**: Follow PSR-12 coding standards
  - Use strict typing: `declare(strict_types=1);`
  - Document classes and methods with PHPDoc
  - Use typed properties and return types

- **JavaScript/Vue**: Follow the ESLint configuration
  - Use ES6+ features
  - Prefer const over let
  - Use meaningful variable names
  - Add JSDoc comments for complex functions

- **Commits**: Write clear, descriptive commit messages
  - Use present tense ("Add feature" not "Added feature")
  - Reference issues when applicable (#123)
  - Keep commits focused and atomic

## Making Changes

### Creating a Branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/your-bug-fix
```

### Submitting a Pull Request

1. **Update your branch** with the latest main
   ```bash
   git fetch origin
   git rebase origin/main
   ```

2. **Push your changes**
   ```bash
   git push origin feature/your-feature-name
   ```

3. **Create Pull Request** on GitHub
   - Fill in the pull request template with a clear description of the changes
   - Reference any related issues
   - Ensure all CI checks pass

### Pull Request Guidelines

- ✅ Include tests for new features
- ✅ Update documentation if needed
- ✅ Ensure all tests pass
- ✅ Follow the existing code style
- ✅ Keep changes focused and minimal
- ✅ Add an entry under `[Unreleased]` in CHANGELOG.md for user-facing changes

## Reporting Issues

Please use the [issue forms](https://github.com/shellmann/nextcloud-moviedb/issues/new/choose); they ask for everything listed below.

### Bug Reports

When reporting bugs, please include:
- MovieDB version
- Nextcloud version
- PHP version
- Database (MySQL/MariaDB, PostgreSQL or SQLite)
- Browser and version (for frontend issues)
- Steps to reproduce
- Expected vs actual behavior
- Screenshots if applicable
- Console errors (browser or server logs)

### Feature Requests

For feature requests, please describe:
- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered
- Why this would be useful to other users

## Translation

The app is translated into German, Spanish, French, Italian and Dutch. **Every new or changed `t('moviedb', '...')` string needs a translation in every `l10n/*.json` file**, otherwise it shows up in English. [TRANSLATIONS.md](TRANSLATIONS.md) has the workflow, how to add a language, and the `npm run check:translations` audit that CI runs.

## Development Tips

- Use `npm run watch` during development for automatic rebuilds
- Check the browser console for frontend errors
- Check `nextcloud.log` for backend errors
- Use Vue DevTools for debugging state management
- Try changes in a throwaway Nextcloud container, for any supported Nextcloud version (see [TESTING.md](TESTING.md#manual-testing-in-a-nextcloud-container))

## Questions?

Feel free to ask in [Discussions](https://github.com/shellmann/nextcloud-moviedb/discussions) about anything related to contributing!

## License

By contributing, you agree that your contributions will be licensed under the AGPL-3.0-or-later license.
