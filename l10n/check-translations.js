#!/usr/bin/env node
// Fails (non-zero exit) if any locale in l10n/*.json is missing a translation
// for a source string used in src/, or has an empty translation for one.
// See TRANSLATIONS.md for the full workflow this enforces.

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const LOCALES = ['de', 'es', 'fr', 'it', 'nl'];

const files = execSync("grep -rl \"t('moviedb'\" src/", { encoding: 'utf8' }).trim().split('\n');
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

let failed = false;

for (const loc of LOCALES) {
	const trans = JSON.parse(fs.readFileSync(path.join(__dirname, `${loc}.json`), 'utf8')).translations || {};
	const missing = allKeys.filter((k) => !(k in trans));
	const empty = allKeys.filter((k) => k in trans && !trans[k]);

	console.log(`=== ${loc.toUpperCase()} === (${Object.keys(trans).length} translated)`);
	console.log(`  Missing keys: ${missing.length}`);
	missing.forEach((k) => console.log(`    - "${k}"`));
	if (empty.length) {
		console.log(`  Empty: ${empty.length}`);
		empty.forEach((k) => console.log(`    ~ "${k}"`));
	}
	console.log('');

	if (missing.length || empty.length) {
		failed = true;
	}
}

if (failed) {
	console.error('Translation audit failed: see missing/empty keys above. See TRANSLATIONS.md.');
	process.exit(1);
}

console.log('Translation audit passed: no missing or empty keys.');
