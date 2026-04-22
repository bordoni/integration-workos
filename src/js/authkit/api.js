/**
 * REST client for the AuthKit React shell.
 *
 * Every call goes to /wp-json/workos/v1/auth/*. We handle three things
 * the browser should never deal with directly:
 *   - the profile-scoped CSRF nonce (re-fetched automatically on 403),
 *   - the Radar action token (injected into every mutation when present),
 *   - 401 responses (trigger one silent /session/refresh before retrying).
 *
 * Not a Radix. Not axios. Just fetch + retry.
 */

const NONCE_CACHE_KEY = '__workos_authkit_nonce__';

class AuthKitClient {
	/**
	 * @param {Object}   options
	 * @param {string}   options.profile       Login profile slug.
	 * @param {string}   options.baseUrl       Absolute base URL for /wp-json/workos/v1/auth.
	 * @param {Function} [options.radarToken]  () => string|null — returns current Radar token.
	 */
	constructor( { profile, baseUrl, radarToken } ) {
		this.profile = profile;
		this.baseUrl = baseUrl.replace( /\/$/, '' );
		this.getRadarToken = radarToken || ( () => null );
		this.nonce = null;
		this.radarSiteKey = '';
	}

	/**
	 * Fetch a fresh nonce + Radar site key. Called once on mount.
	 */
	async bootstrap() {
		const resp = await fetch(
			`${ this.baseUrl }/nonce?profile=${ encodeURIComponent( this.profile ) }`,
			{ credentials: 'same-origin' }
		);
		if ( ! resp.ok ) {
			throw new Error( 'Failed to bootstrap authentication.' );
		}
		const data = await resp.json();
		this.nonce = data.nonce;
		this.radarSiteKey = data.radar_site_key || '';
		return data;
	}

	/**
	 * POST to an AuthKit endpoint with automatic nonce + Radar handling.
	 *
	 * @param {string} path   Endpoint path (e.g. '/password/authenticate').
	 * @param {Object} body   JSON body; `profile` is injected automatically.
	 * @param {Object} [opts] { method: 'POST'|'GET', retry: true }.
	 */
	async call( path, body = {}, opts = {} ) {
		const method = opts.method || 'POST';
		const retry = opts.retry !== false;

		if ( ! this.nonce && method !== 'GET' ) {
			await this.bootstrap();
		}

		const payload = method === 'GET' ? null : { profile: this.profile, ...body };

		const url = method === 'GET' && Object.keys( body ).length
			? `${ this.baseUrl }${ path }?${ new URLSearchParams( { profile: this.profile, ...body } ).toString() }`
			: method === 'GET'
				? `${ this.baseUrl }${ path }?profile=${ encodeURIComponent( this.profile ) }`
				: `${ this.baseUrl }${ path }`;

		const headers = { 'Content-Type': 'application/json' };
		if ( method !== 'GET' ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		const radar = this.getRadarToken();
		if ( radar ) {
			headers[ 'X-WorkOS-Radar-Action-Token' ] = radar;
		}

		const response = await fetch( url, {
			method,
			headers,
			credentials: 'same-origin',
			body: payload ? JSON.stringify( payload ) : undefined,
		} );

		// 403 with invalid_nonce — refresh once and retry.
		if ( response.status === 403 && retry ) {
			const data = await response.clone().json().catch( () => ( {} ) );
			if ( data.code === 'workos_authkit_invalid_nonce' ) {
				await this.bootstrap();
				return this.call( path, body, { ...opts, retry: false } );
			}
		}

		// 401 on an authenticated endpoint — attempt a silent refresh and retry.
		if ( response.status === 401 && retry && path !== '/session/refresh' ) {
			const refreshed = await fetch(
				`${ this.baseUrl }/session/refresh`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.nonce },
					credentials: 'same-origin',
				}
			);
			if ( refreshed.ok ) {
				return this.call( path, body, { ...opts, retry: false } );
			}
		}

		return response;
	}

	async json( path, body = {}, opts = {} ) {
		const response = await this.call( path, body, opts );
		const data = await response.json().catch( () => ( {} ) );
		return { status: response.status, data, ok: response.ok };
	}
}

export function createClient( { profile, baseUrl, radarToken } ) {
	return new AuthKitClient( { profile, baseUrl, radarToken } );
}

export { NONCE_CACHE_KEY };
