/**
 * Post-login redirect helpers.
 */

/**
 * Query-arg names the WP / plugin internals own — never forwarded to
 * the destination because they would either pollute it or create loops.
 * Mirrors `LoginRedirector::INTERNAL_QUERY_ARGS` on the PHP side.
 */
const INTERNAL_QUERY_ARGS = new Set( [
	'redirect_to',
	'_wpnonce',
	'interim-login',
	'loggedout',
	'reauth',
	'instance',
	'wp_lang',
	'action',
	'fallback',
] );

function isForwardable( key: string ): boolean {
	if ( INTERNAL_QUERY_ARGS.has( key ) ) {
		return false;
	}
	if ( key.startsWith( 'workos_' ) ) {
		return false;
	}
	return true;
}

/**
 * Append safe query args from `originalQuery` onto `destination`.
 *
 * - Internals (redirect_to, _wpnonce, etc.) are stripped.
 * - Existing query args on the destination win on key collision.
 * - When the destination is empty / invalid, the input is returned
 *   unchanged so we never produce a malformed URL.
 */
export function forwardQueryArgs(
	destination: string,
	originalQuery: string
): string {
	if ( ! destination || ! originalQuery ) {
		return destination;
	}

	let originalParams: URLSearchParams;
	try {
		originalParams = new URLSearchParams( originalQuery );
	} catch ( _ ) {
		return destination;
	}

	const forward: Array< [ string, string ] > = [];
	originalParams.forEach( ( value, key ) => {
		if ( isForwardable( key ) ) {
			forward.push( [ key, value ] );
		}
	} );

	if ( forward.length === 0 ) {
		return destination;
	}

	// URL parsing requires an absolute base; use window.location.origin so
	// relative paths like `/dashboard` are handled too. We strip the
	// origin back out at the end if the destination didn't include one.
	const isAbsolute = /^https?:\/\//i.test( destination );
	const base       = window.location.origin;
	let url: URL;
	try {
		url = new URL( destination, base );
	} catch ( _ ) {
		return destination;
	}

	for ( const [ key, value ] of forward ) {
		if ( ! url.searchParams.has( key ) ) {
			url.searchParams.append( key, value );
		}
	}

	if ( isAbsolute ) {
		return url.toString();
	}
	// Re-strip the synthetic origin so we hand back a relative path.
	return url.pathname + url.search + url.hash;
}
