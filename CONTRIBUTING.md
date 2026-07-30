# Contributing to MovieDB

Thank you for your interest in contributing to MovieDB! This document provides guidelines and instructions for contributing.

## Code of Conduct

Please be respectful and constructive in all interactions. We aim to maintain a welcoming and inclusive environment for all contributors.

## Getting Started

### Prerequisites
- Nextcloud 32+ development environment
- PHP 8.0 or higher
- Node.js 26+ and npm (see `.nvmrc`; CI runs on Node 26)
- Composer
- Git

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
# Run all tests
npm test

# Run tests in watch mode
npm run test:watch

# Generate coverage report
npm run test:coverage
```

### Code Quality

Before submitting a pull request, ensure your code passes all checks:

```bash
# JavaScript/Vue linting
npm run lint

# Auto-fix linting issues
npm run lint:fix

# PHP syntax check
find lib/ -name "*.php" -print0 | xargs -0 -n1 php -l

# Build verification
npm run build
```

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
   - Provide a clear description of the changes
   - Reference any related issues
   - Ensure all CI checks pass

### Pull Request Guidelines

- ✅ Include tests for new features
- ✅ Update documentation if needed
- ✅ Ensure all tests pass
- ✅ Follow the existing code style
- ✅ Keep changes focused and minimal
- ✅ Add entries to CHANGELOG.md for significant changes

## Reporting Issues

### Bug Reports

When reporting bugs, please include:
- Nextcloud version
- PHP version
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

**IMPORTANT:** Whenever you add or change a user-facing string wrapped in
`t('moviedb', '...')`, you MUST add its translation to **every** locale JSON in
`l10n/` (not just your own). Missing keys fall back to raw English and look
broken. See `TRANSLATIONS.md` for the full workflow and an audit script that
reports any missing translations per locale.

To contribute translations:

1. Edit the JSON file for your language in `l10n/`
2. Run `npm run l10n` to regenerate the compiled files
3. Run the audit in `TRANSLATIONS.md` to confirm no keys are missing
4. Test your translations in the app
5. Submit a pull request

Available languages:
- German (de.json)
- Spanish (es.json)
- French (fr.json)
- Italian (it.json)
- Dutch (nl.json)

To add a new language, create a new JSON file following the existing structure.

## Development Tips

- Use `npm run watch` during development for automatic rebuilds
- Check the browser console for frontend errors
- Check `nextcloud.log` for backend errors
- Use Vue DevTools for debugging state management
- Test with different Nextcloud versions if possible

## Questions?

Feel free to open an issue for any questions about contributing!

## License

By contributing, you agree that your contributions will be licensed under the AGPL-3.0-or-later license.
