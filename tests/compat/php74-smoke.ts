/**
 * Install the built plugin ZIP on a fresh WordPress and prove it loads.
 *
 * Used by the PHP 7.4 workflow: wp-browser 4.x needs PHP 8, so wpunit
 * can't run on 7.4. This covers what it can instead: the ZIP installs and
 * activates through WP-CLI, the plugin boots (container, constants,
 * REST routes), the front page, wp-login.php and a public REST route
 * answer without a fatal, and debug.log has no PHP errors from the plugin.
 *
 * Environment:
 *   WP_CMD      how to run WP-CLI, e.g. `wp --path=wp` (CI) or
 *               `docker exec iwos74 wp --path=/wp` (local)
 *   PLUGIN_ZIP  path to the plugin ZIP, as WP_CMD sees it
 *   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
 *   WP_VERSION  WordPress to install (default 6.2, the plugin's minimum)
 *   SITE_URL    where SERVER_CMD serves the site (default http://127.0.0.1:8080)
 *   SERVER_CMD  starts a web server for the site, e.g. `wp --path=wp server --port=8080`
 *
 *   bun tests/compat/php74-smoke.ts
 */

import { $ } from 'bun';

const env = ( name: string, fallback?: string ): string => {
	const value = process.env[ name ] ?? fallback;
	if ( value === undefined ) {
		console.error( `Missing ${ name}` );
		process.exit( 2 );
	}
	return value;
};

const wpCmd = env( 'WP_CMD' ).split( ' ' );
const siteUrl = env( 'SITE_URL', 'http://127.0.0.1:8080' );
const failures: string[] = [];

async function wp( ...args: string[] ): Promise< string > {
	const result = await $`${ wpCmd } ${ args }`.nothrow().quiet();
	if ( result.exitCode !== 0 ) {
		throw new Error( `wp ${ args.join( ' ' ) } failed:\n${ result.stderr.toString() }${ result.stdout.toString() }` );
	}
	return result.stdout.toString().trim();
}

function check( label: string, ok: boolean, detail = '' ): void {
	console.log( `${ ok ? '✓' : '✗' } ${ label }${ detail ? `: ${ detail }` : '' }` );
	if ( ! ok ) {
		failures.push( label );
	}
}

// 1. A fresh WordPress at the plugin's minimum version.
await wp( 'core', 'download', `--version=${ env( 'WP_VERSION', '6.2' ) }`, '--force' );
await wp(
	'config', 'create',
	`--dbhost=${ env( 'DB_HOST' ) }`, `--dbname=${ env( 'DB_NAME' ) }`,
	`--dbuser=${ env( 'DB_USER' ) }`, `--dbpass=${ env( 'DB_PASSWORD' ) }`,
	'--skip-check', '--force'
);
for ( const [ name, value ] of [ [ 'WP_DEBUG', 'true' ], [ 'WP_DEBUG_LOG', 'true' ], [ 'WP_DEBUG_DISPLAY', 'false' ] ] ) {
	await wp( 'config', 'set', name, value, '--raw' );
}
// The database itself comes empty from the caller (a fresh MySQL service).
await wp( 'core', 'install', `--url=${ siteUrl }`, '--title=PHP 7.4 smoke', '--admin_user=admin', '--admin_password=password', '--admin_email=admin@example.test', '--skip-email' );

const runtime = JSON.parse( await wp( 'eval', 'echo wp_json_encode( [ "php" => PHP_VERSION, "wp" => get_bloginfo( "version" ) ] );' ) );
check( 'PHP 7.4 runtime', runtime.php.startsWith( '7.4.' ), runtime.php );
check( `WordPress ${ env( 'WP_VERSION', '6.2' ) }`, runtime.wp.startsWith( env( 'WP_VERSION', '6.2' ) ), runtime.wp );

// 2. Install and activate the built ZIP.
console.log( await wp( 'plugin', 'install', env( 'PLUGIN_ZIP' ), '--activate', '--force' ) );
check( 'plugin is active', ( await wp( 'plugin', 'list', '--status=active', '--field=name' ) ).split( '\n' ).includes( 'integration-workos' ) );

// 3. The plugin booted: singleton, constants, container, routes.
const boot = JSON.parse(
	await wp(
		'eval',
		[
			'do_action( "rest_api_init" );',
			'$routes = array_filter( array_keys( rest_get_server()->get_routes() ), function ( $r ) { return 0 === strpos( $r, "/workos/v1/" ); } );',
			'echo wp_json_encode( [',
			'"plugin" => class_exists( "WorkOS\\\\Plugin", false ),',
			'"instance" => function_exists( "workos" ) && workos() instanceof WorkOS\\Plugin,',
			'"version" => defined( "WORKOS_VERSION" ) ? WORKOS_VERSION : "",',
			'"api" => get_class( workos()->api() ),',
			'"routes" => count( $routes ),',
			'"db_version" => get_option( "workos_db_version" ),',
			'] );',
		].join( ' ' )
	)
);
check( 'WorkOS\\Plugin declared and workos() returns it', boot.plugin && boot.instance );
check( 'WORKOS_VERSION defined', boot.version !== '', boot.version );
check( 'API client resolves from the container', boot.api === 'WorkOS\\Api\\Client', boot.api );
check( 'workos/v1 REST routes registered', boot.routes >= 30, `${ boot.routes } routes` );
check( 'schema installed on activation', Boolean( boot.db_version ), String( boot.db_version ) );

// 4. Real requests through a web server on the same PHP.
const server = Bun.spawn( env( 'SERVER_CMD' ).split( ' ' ), { stdout: 'ignore', stderr: 'ignore' } );
try {
	let up = false;
	for ( let i = 0; i < 40 && ! up; i++ ) {
		up = await fetch( `${ siteUrl }/wp-login.php?fallback=1`, { redirect: 'manual' } ).then( () => true, () => false );
		if ( ! up ) {
			await Bun.sleep( 250 );
		}
	}
	check( 'web server is up', up, siteUrl );

	for ( const [ path, expect ] of [
		[ '/', [ 200 ] ],
		[ '/wp-login.php', [ 200, 302 ] ],
		[ '/wp-login.php?fallback=1', [ 200 ] ],
		[ '/?rest_route=/workos/v1', [ 200 ] ],
		[ '/?rest_route=/workos/v1/auth/nonce&profile=default', [ 200, 400, 404 ] ],
	] as const ) {
		const response = await fetch( `${ siteUrl }${ path }`, { redirect: 'manual' } ).catch( ( e ) => e as Error );
		const status = response instanceof Response ? response.status : 0;
		check( `GET ${ path }`, ( expect as readonly number[] ).includes( status ), `HTTP ${ status }` );
	}
} finally {
	server.kill();
}

// 5. No PHP errors from the plugin.
const log = await wp( 'eval', 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";' );
const pluginErrors = log.split( '\n' ).filter( ( line ) => /PHP (Fatal|Parse|Warning|Notice|Deprecated)/.test( line ) && line.includes( 'integration-workos' ) );
check( 'debug.log has no PHP errors from the plugin', pluginErrors.length === 0, pluginErrors.slice( 0, 5 ).join( '\n    ' ) );

if ( failures.length > 0 ) {
	console.error( `\n${ failures.length } check(s) failed.` );
	process.exit( 1 );
}
console.log( '\nThe built plugin installs, activates and serves requests on this PHP.' );
