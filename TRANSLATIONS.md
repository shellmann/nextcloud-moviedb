# Translations (l10n)

This app is fully localized. **Whenever you add or change a user-facing string,
you MUST also add its translation to every locale.** Missing translations fall
back to the raw English source string, which looks broken in non-English UIs.

## How localization works

- Frontend strings are wrapped with `t('moviedb', 'Some string')`
  (from `@nextcloud/l10n`). Placeholders use `{name}` syntax, e.g.
  `t('moviedb', 'Page {page} of {total}', { page, total })`.
- The **source of truth** is the per-locale JSON files in `l10n/`:
  `de.json`, `es.json`, `fr.json`, `it.json`, `nl.json`.
  Each has a `{ "translations": { "English source": "Translated" } }` object.
- The `.js` files (`de.js`, etc.) are **generated** from the JSON by
  `l10n/build-l10n.js`. Never edit the `.js` files by hand.
- `npm run l10n` regenerates the `.js` files. It also runs automatically as the
  first step of `npm run build`.

## Supported locales

`de` (German), `es` (Spanish), `fr` (French), `it` (Italian), `nl` (Dutch).

If you add a new locale, create `l10n/<code>.json` with the same keys.

## Workflow when adding/changing strings

1. Add or edit the `t('moviedb', '...')` call in `src/`.
2. Add the exact same source string as a key to **every** file in `l10n/*.json`
   with a proper translation for that language (do not leave English fallbacks).
3. Run `npm run l10n` to regenerate the `.js` files.
4. Run `npm run check:translations` to confirm nothing is missing.
5. Rebuild (`npm run build`) and commit the changed `l10n/*.json` **and**
   `l10n/*.js` files together.

## Audit: find missing translations

`npm run check:translations` (runs `l10n/check-translations.js`) lists any
source string not yet present, or present but empty, in each locale JSON. It
exits non-zero if anything is missing, and **CI runs it on every PR** (see
`.github/workflows/ci.yml`, job `check-translations`) — a PR that introduces
or leaves untranslated keys will fail CI.

> Note: the audit uses a simple regex and only detects single-quoted
> `t('moviedb', '...')` calls (the convention used throughout this codebase).
> If you introduce double-quoted calls or dynamic keys, verify them manually.
