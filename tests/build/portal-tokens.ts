/**
 * Front-end contract check for the built bundles in `build/`.
 *
 * The Software Portal (stellarwp/software.liquidweb.com) styles and drives
 * the AuthKit shell and the change-email confirm page through these ids,
 * classes, CSS variables, SlotFill names and strings; its Playwright tests
 * select on some of them. wpunit can't see the bundles, so this script
 * asserts each token survives the build.
 *
 * Run after `bun run build`:  bun tests/build/portal-tokens.ts [build-dir]
 *
 * A failure means a change renamed or dropped something the Portal uses.
 * Keep the old name (or ship both) instead of editing this list.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

type Expectation = { file: string; tokens: string[] };

const expectations: Expectation[] = [
	{
		file: 'authkit.js',
		tokens: [
			'workos-authkit-root',
			'wa-email',
			'wa-password',
			'wa-logo',
			'wa-slot',
			'wa-card',
			'wa-below-card',
			'wa-footer',
			'workos.authkit.beforeHeader',
			'workos.authkit.afterFooter',
			'Sign in with password',
		],
	},
	{
		file: 'authkit.css',
		tokens: [
			'workos-authkit-root',
			'wa-card',
			'wa-footer',
			'--wa-card-border',
			'--wa-radius',
			'--wa-danger',
			'--wa-danger-bg',
		],
	},
	{
		file: 'change-email-confirm.js',
		tokens: [ 'workos-change-email-confirm-status', 'workosChangeEmailConfirm' ],
	},
];

const buildDir = resolve( process.argv[ 2 ] ?? 'build' );
const failures: string[] = [];
let checked = 0;

for ( const { file, tokens } of expectations ) {
	const path = join( buildDir, file );
	if ( ! existsSync( path ) ) {
		failures.push( `${ file }: missing (run \`bun run build\` first)` );
		continue;
	}

	const contents = readFileSync( path, 'utf8' );
	for ( const token of tokens ) {
		checked++;
		if ( ! contents.includes( token ) ) {
			failures.push( `${ file }: "${ token }" not found` );
		}
	}
}

if ( failures.length > 0 ) {
	console.error( `Portal front-end tokens: ${ failures.length } missing\n` );
	for ( const failure of failures ) {
		console.error( `  ✗ ${ failure }` );
	}
	process.exit( 1 );
}

console.log( `Portal front-end tokens: all ${ checked } present in ${ buildDir }` );
