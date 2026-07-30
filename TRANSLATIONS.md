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
4. Run the audit below to confirm nothing is missing.
5. Rebuild (`npm run build`) and commit the changed `l10n/*.json` **and**
   `l10n/*.js` files together.

## Audit: find missing translations

Run this from the repo root to list any source string not yet present in each
locale JSON. It should print `Missing keys: 0` for every locale.

```bash
node - << 'EOF'
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const files = execSync("grep -rl \"t('moviedb'\" src/", {encoding:'utf8'}).trim().split('\n');
const keys = new Set();
const re = /t\(\s*'moviedb'\s*,\s*'((?:[^'\\]|\\.)*)'/g;
for (const f of files) {
  const content = fs.readFileSync(f, 'utf8');
  let m;
  while ((m = re.exec(content)) !== null) {
    keys.add(m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\'));
  }
}
const allKeys = [...keys].sort();
console.log(`Total unique source strings: ${allKeys.length}\n`);
for (const loc of ['de','es','fr','it','nl']) {
  const trans = JSON.parse(fs.readFileSync(path.join('l10n', loc + '.json'),'utf8')).translations || {};
  const missing = allKeys.filter(k => !(k in trans));
  const empty = allKeys.filter(k => k in trans && !trans[k]);
  console.log(`=== ${loc.toUpperCase()} === (${Object.keys(trans).length} translated)`);
  console.log(`  Missing keys: ${missing.length}`);
  missing.forEach(k => console.log(`    - "${k}"`));
  if (empty.length) { console.log(`  Empty: ${empty.length}`); empty.forEach(k => console.log(`    ~ "${k}"`)); }
  console.log('');
}
EOF
```

> Note: the audit uses a simple regex and only detects single-quoted
> `t('moviedb', '...')` calls (the convention used throughout this codebase).
> If you introduce double-quoted calls or dynamic keys, verify them manually.
