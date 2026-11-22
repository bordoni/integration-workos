/**
 * Generate docs/hooks.md from the `do_action()` / `apply_filters()` calls in src/.
 *
 *   bun tools/hooks-reference.ts           write docs/hooks.md
 *   bun tools/hooks-reference.ts --check   fail when docs/hooks.md is stale,
 *                                          or when a call site has no docblock
 *
 * The docblock right above a call site is the source of truth: its summary,
 * `@param` lines and `@since` tag end up in the reference. A site whose
 * docblock reads "This action/filter is documented in …" points at the
 * documented site. Dynamic hook names get the reviewed notes in `DYNAMIC`
 * below, plus the concrete names the plugin itself listens to.
 */

import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const root = resolve( import.meta.dir, '..' );
const srcDir = join( root, 'src' );
const outFile = join( root, 'docs', 'hooks.md' );
const check = process.argv.includes( '--check' );

/** WordPress core hooks the plugin fires on purpose; listed separately. */
const CORE_HOOKS = new Set( [ 'wp_login', 'wp_mail_from' ] );

/** Reviewed notes for hooks whose names are built at runtime. */
const DYNAMIC: Record< string, { summary: string; listenerPattern: RegExp } > = {
	'workos_webhook_{$event_type}': {
		summary:
			'Fires for each verified WorkOS webhook event the plugin handles, named after the WorkOS event type with its dots kept (`workos_webhook_user.updated`, `workos_webhook_dsync.user.created`, …). The dots mirror WorkOS event names on purpose; it is the one exception to the snake_case naming convention. The event types are the `$handlers` list in `Webhook\\Receiver::handle()`. Use `workos_webhook` to see every event.',
		listenerPattern: /^workos_webhook_[a-z_]+\.[a-z_.]+$/,
	},
	'workos/container/registered_provider:{static::class}': {
		summary:
			'Fires after one specific controller registers, with the controller\'s fully qualified class name as the suffix (`workos/container/registered_provider:WorkOS\\Sync\\Controller`). Inherited from the di52 container conventions; new hooks use `workos_<area>_<event>`.',
		listenerPattern: /^workos\/container\/registered_provider:.+$/,
	},
};

type Param = { type: string; name: string; description: string };
type Doc = { summary: string; params: Param[]; since: string; reference: string; ret: string };
type Site = { file: string; line: number; args: string[]; doc: Doc | null };
type Hook = { name: string; kind: 'action' | 'filter'; dynamic: boolean; sites: Site[] };

function phpFiles( dir: string ): string[] {
	const out: string[] = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...phpFiles( path ) );
		} else if ( entry.name.endsWith( '.php' ) ) {
			out.push( path );
		}
	}
	return out.sort();
}

/** Split the argument list that starts right after `(` at `start`. */
function parseArgs( source: string, start: number ): string[] {
	const args: string[] = [];
	let depth = 0;
	let current = '';
	for ( let i = start; i < source.length; i++ ) {
		const ch = source[ i ];
		if ( ch === "'" || ch === '"' ) {
			let j = i + 1;
			while ( j < source.length && source[ j ] !== ch ) {
				j += source[ j ] === '\\' ? 2 : 1;
			}
			current += source.slice( i, j + 1 );
			i = j;
			continue;
		}
		if ( ch === '(' || ch === '[' || ch === '{' ) {
			depth++;
		} else if ( ch === ')' || ch === ']' || ch === '}' ) {
			if ( depth === 0 ) {
				args.push( current.trim() );
				return args.filter( ( a ) => a !== '' );
			}
			depth--;
		} else if ( ch === ',' && depth === 0 ) {
			args.push( current.trim() );
			current = '';
			continue;
		}
		current += ch;
	}
	throw new Error( `Unterminated call at offset ${ start }` );
}

/** Turn the first argument into a hook name, or a `{…}` pattern when dynamic. */
function hookName( expr: string ): { name: string; dynamic: boolean } {
	const single = expr.match( /^'([^']*)'$/ );
	if ( single ) {
		return { name: single[ 1 ], dynamic: false };
	}
	const double = expr.match( /^"([^"]*)"$/ );
	if ( double ) {
		return { name: double[ 1 ], dynamic: double[ 1 ].includes( '$' ) };
	}
	const parts = expr.split( /\s*\.\s*(?=(?:[^'"]*['"][^'"]*['"])*[^'"]*$)/ );
	const name = parts
		.map( ( part ) => {
			const literal = part.match( /^'([^']*)'$/ );
			return literal ? literal[ 1 ] : `{${ part }}`;
		} )
		.join( '' );
	return { name, dynamic: true };
}

/** The docblock that ends on the line right above `lineIndex`, if any. */
function docblockAbove( lines: string[], lineIndex: number ): Doc | null {
	let end = lineIndex - 1;
	// Skip blank lines and `// phpcs:ignore …`-style line comments between the docblock and the call.
	while ( end >= 0 && ( lines[ end ].trim() === '' || lines[ end ].trim().startsWith( '//' ) ) ) {
		end--;
	}
	if ( end < 0 || ! lines[ end ].trim().endsWith( '*/' ) ) {
		return null;
	}
	let start = end;
	while ( start >= 0 && ! lines[ start ].trim().startsWith( '/**' ) ) {
		start--;
	}
	if ( start < 0 ) {
		return null;
	}

	const body = lines
		.slice( start, end + 1 )
		.join( '\n' )
		.replace( /^\s*\/\*\*\s?/, '' )
		.replace( /\s*\*\/\s*$/, '' )
		.split( '\n' )
		.map( ( l ) => l.replace( /^\s*\*\s?/, '' ) );

	const reference = body.join( ' ' ).match( /This (?:action|filter) is documented in ([^\s*]+)/ );
	if ( reference ) {
		return { summary: '', params: [], since: '', ret: '', reference: reference[ 1 ] };
	}

	const summaryLines: string[] = [];
	for ( const line of body ) {
		if ( line.trim() === '' || line.trim().startsWith( '@' ) ) {
			break;
		}
		summaryLines.push( line.trim() );
	}

	const params: Param[] = [];
	let since = '';
	let ret = '';
	const tags = body.join( '\n' ).split( /\n(?=\s*@)/ );
	for ( const tag of tags ) {
		const text = tag.trim().replace( /\s+/g, ' ' );
		const param = text.match( /^@param\s+(\S+)\s+(\$\S+)\s*(.*)$/ );
		if ( param ) {
			params.push( { type: param[ 1 ], name: param[ 2 ], description: param[ 3 ] } );
		}
		const sinceTag = text.match( /^@since\s+(\S+)/ );
		if ( sinceTag ) {
			since = sinceTag[ 1 ];
		}
		const returnTag = text.match( /^@return\s+(.*)$/ );
		if ( returnTag ) {
			ret = returnTag[ 1 ];
		}
	}

	return { summary: summaryLines.join( ' ' ), params, since, ret, reference: '' };
}

function collect(): { hooks: Map< string, Hook >; listeners: Set< string > } {
	const hooks = new Map< string, Hook >();
	const listeners = new Set< string >();
	const call = /\b(do_action|apply_filters)\s*\(/g;
	const listen = /\badd_(?:action|filter)\s*\(\s*'([^']+)'/g;

	for ( const path of phpFiles( srcDir ) ) {
		const source = readFileSync( path, 'utf8' );
		const lines = source.split( '\n' );
		const file = relative( root, path );

		for ( const match of source.matchAll( listen ) ) {
			listeners.add( match[ 1 ] );
		}

		for ( const match of source.matchAll( call ) ) {
			const offset = match.index ?? 0;
			const lineIndex = source.slice( 0, offset ).split( '\n' ).length - 1;
			const lineText = lines[ lineIndex ].trim();
			// Skip mentions inside comments and docblocks.
			if ( lineText.startsWith( '*' ) || lineText.startsWith( '//' ) || lineText.startsWith( '/*' ) ) {
				continue;
			}

			const args = parseArgs( source, offset + match[ 0 ].length );
			const { name, dynamic } = hookName( args[ 0 ] );
			const kind = match[ 1 ] === 'do_action' ? 'action' : 'filter';
			const hook = hooks.get( name ) ?? { name, kind, dynamic, sites: [] };
			hook.sites.push( { file, line: lineIndex + 1, args: args.slice( 1 ), doc: docblockAbove( lines, lineIndex ) } );
			hooks.set( name, hook );
		}
	}

	return { hooks, listeners };
}

function anchor( text: string ): string {
	return text.toLowerCase().replace( /[^a-z0-9 _-]/g, '' ).replace( / /g, '-' );
}

function cell( text: string ): string {
	return text.replace( /\|/g, '\\|' ).replace( /\s+/g, ' ' ).trim();
}

function primaryDoc( hook: Hook ): Doc | null {
	return hook.sites.find( ( s ) => s.doc && ! s.doc.reference )?.doc ?? null;
}

function render( hooks: Map< string, Hook >, listeners: Set< string > ): string {
	const all = [ ...hooks.values() ].sort( ( a, b ) => a.name.localeCompare( b.name ) );
	const own = all.filter( ( h ) => ! CORE_HOOKS.has( h.name ) );
	const core = all.filter( ( h ) => CORE_HOOKS.has( h.name ) );
	const sites = all.reduce( ( n, h ) => n + h.sites.length, 0 );
	const actions = own.filter( ( h ) => h.kind === 'action' );
	const filters = own.filter( ( h ) => h.kind === 'filter' );

	const out: string[] = [];
	out.push( '# Hook reference' );
	out.push( '' );
	out.push( '<!-- Generated by `bun run docs:hooks` (tools/hooks-reference.ts). Do not edit by hand: edit the docblock at the call site and regenerate. CI fails when this file is stale. -->' );
	out.push( '' );
	out.push(
		`Every action and filter the plugin fires, read from \`src/\`: ${ actions.length } actions and ${ filters.length } filters of its own, plus ${ core.length } WordPress core hooks it fires, across ${ sites } call sites. Naming conventions for new hooks are in [AGENTS.md](../AGENTS.md#hook-conventions).`
	);
	out.push( '' );
	out.push( 'Feature guides with examples: [extending the login UI](extending-the-login-ui.md), [change email](change-email.md), [password reset](password-reset.md), [identity linking](identity-linking.md), [rate limiting](rate-limiting.md).' );
	out.push( '' );

	const index = ( title: string, list: Hook[] ) => {
		out.push( `## ${ title }` );
		out.push( '' );
		out.push( '| Hook | Summary | Since |' );
		out.push( '|---|---|---|' );
		for ( const hook of list ) {
			const doc = primaryDoc( hook );
			const summary = hook.dynamic && DYNAMIC[ hook.name ] ? DYNAMIC[ hook.name ].summary.split( '. ' )[ 0 ] + '.' : doc?.summary ?? '';
			out.push( `| [\`${ cell( hook.name ) }\`](#${ anchor( hook.name ) }) | ${ cell( summary ) } | ${ doc?.since ?? '' } |` );
		}
		out.push( '' );
	};

	index( 'Actions', actions );
	index( 'Filters', filters );
	index( 'WordPress core hooks the plugin fires', core );

	out.push( '## Details' );
	out.push( '' );

	for ( const hook of [ ...own, ...core ] ) {
		const doc = primaryDoc( hook );
		out.push( `### \`${ hook.name }\`` );
		out.push( '' );
		const kind = CORE_HOOKS.has( hook.name ) ? `WordPress core ${ hook.kind }` : hook.kind === 'action' ? 'Action' : 'Filter';
		out.push( `${ kind }${ doc?.since ? `, since ${ doc.since }` : '' }.` );
		out.push( '' );

		const dynamic = hook.dynamic ? DYNAMIC[ hook.name ] : undefined;
		if ( dynamic ) {
			out.push( dynamic.summary );
			out.push( '' );
		}
		if ( doc?.summary ) {
			out.push( doc.summary );
			out.push( '' );
		}

		if ( doc && doc.params.length > 0 ) {
			out.push( '| Argument | Type | Description |' );
			out.push( '|---|---|---|' );
			for ( const param of doc.params ) {
				out.push( `| \`${ param.name }\` | \`${ cell( param.type ) }\` | ${ cell( param.description ) } |` );
			}
			out.push( '' );
		} else if ( hook.sites[ 0 ].args.length > 0 ) {
			out.push( `Arguments: ${ hook.sites[ 0 ].args.map( ( a ) => `\`${ cell( a ) }\`` ).join( ', ' ) }.` );
			out.push( '' );
		}

		if ( hook.kind === 'filter' && doc?.ret ) {
			out.push( `Returns: ${ doc.ret }` );
			out.push( '' );
		}

		const siteList = hook.sites.map( ( s ) => `[\`${ s.file.replace( /^src\/WorkOS\//, '' ) }:${ s.line }\`](../${ s.file }#L${ s.line })` );
		out.push( `Fired from: ${ siteList.join( ', ' ) }.` );
		out.push( '' );

		if ( dynamic ) {
			const known = [ ...listeners ].filter( ( l ) => dynamic.listenerPattern.test( l ) ).sort();
			if ( known.length > 0 ) {
				out.push( `Names the plugin listens to itself: ${ known.map( ( k ) => `\`${ k }\`` ).join( ', ' ) }.` );
				out.push( '' );
			}
		}
	}

	return out.join( '\n' ).replace( /\n+$/, '\n' );
}

const { hooks, listeners } = collect();
const markdown = render( hooks, listeners );

const undocumented: string[] = [];
for ( const hook of hooks.values() ) {
	for ( const site of hook.sites ) {
		if ( ! site.doc ) {
			undocumented.push( `${ site.file }:${ site.line } ${ hook.name }` );
		} else if ( site.doc.reference && ! primaryDoc( hook ) ) {
			undocumented.push( `${ site.file }:${ site.line } ${ hook.name } (points at ${ site.doc.reference }, which has no docblock for it)` );
		}
	}
}

if ( undocumented.length > 0 ) {
	console.error( `Hook call sites without a docblock (${ undocumented.length }):` );
	for ( const site of undocumented ) {
		console.error( `  - ${ site }` );
	}
}

if ( check ) {
	const current = existsSync( outFile ) ? readFileSync( outFile, 'utf8' ) : '';
	if ( current !== markdown ) {
		const a = current.split( '\n' );
		const b = markdown.split( '\n' );
		const first = b.findIndex( ( line, i ) => line !== a[ i ] );
		console.error( `docs/hooks.md is stale (first difference at line ${ first + 1 }):` );
		console.error( `  committed: ${ a[ first ] ?? '(end of file)' }` );
		console.error( `  generated: ${ b[ first ] ?? '(end of file)' }` );
		console.error( 'Run `bun run docs:hooks` and commit the result.' );
		process.exit( 1 );
	}
	if ( undocumented.length > 0 ) {
		console.error( 'Add a docblock above each hook call (summary + @param lines), then run `bun run docs:hooks`.' );
		process.exit( 1 );
	}
	console.log( `docs/hooks.md is up to date (${ hooks.size } hooks).` );
} else {
	writeFileSync( outFile, markdown );
	console.log( `Wrote docs/hooks.md (${ hooks.size } hooks).` );
}
