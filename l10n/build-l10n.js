#!/usr/bin/env node
/**
 * Build script to generate .js translation files from .json files
 *
 * Usage: node l10n/build-l10n.js
 *
 * This ensures we only need to maintain the .json files.
 * The .js files are auto-generated for Nextcloud's frontend.
 */

const fs = require('fs');
const path = require('path');

const l10nDir = __dirname;
const appId = 'moviedb';

// Find all .json files in the l10n directory
const jsonFiles = fs.readdirSync(l10nDir).filter(f => f.endsWith('.json'));

console.log(`Building l10n .js files for ${appId}...`);

for (const jsonFile of jsonFiles) {
	const locale = path.basename(jsonFile, '.json');
	const jsonPath = path.join(l10nDir, jsonFile);
	const jsPath = path.join(l10nDir, `${locale}.js`);

	try {
		const jsonContent = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
		const translations = jsonContent.translations || {};
		const pluralForm = jsonContent.pluralForm || 'nplurals=2; plural=(n != 1);';

		// Build the .js file content
		const jsContent = `OC.L10N.register(
    "${appId}",
    ${JSON.stringify(translations, null, 4).replace(/\n/g, '\n    ')},
"${pluralForm}");
`;

		fs.writeFileSync(jsPath, jsContent);
		console.log(`  ✓ ${locale}.json -> ${locale}.js (${Object.keys(translations).length} strings)`);
	} catch (error) {
		console.error(`  ✗ Error processing ${jsonFile}: ${error.message}`);
	}
}

console.log('Done!');
