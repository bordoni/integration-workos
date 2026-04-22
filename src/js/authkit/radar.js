/**
 * WorkOS Radar SDK loader.
 *
 * Because we replace AuthKit's hosted page with our own React shell, we
 * lose AuthKit's built-in anti-fraud layer. Radar fills that gap: a
 * fingerprint script runs in the browser, produces an *action token* per
 * sensitive action, and WorkOS scores risk on the server side when we
 * forward that token.
 *
 * Integration points:
 *   - load( siteKey ) starts the SDK load but does not block rendering.
 *   - getActionToken( action ) returns a string or null. api.js threads
 *     that into the X-WorkOS-Radar-Action-Token header on every mutation.
 */

const SDK_URL = 'https://radar.workos.com/v1/radar.js';
const SDK_ID = 'workos-radar-sdk';
const READY_TIMEOUT_MS = 4000;

let readyPromise = null;

/**
 * Load the Radar SDK, idempotent. Resolves when `window.WorkOSRadar` is
 * available, rejects on timeout (so callers can fall through cleanly).
 *
 * @param {string} siteKey Radar public site key.
 * @returns {Promise<object|null>} Resolves to the SDK or null.
 */
export function load( siteKey ) {
	if ( ! siteKey || typeof window === 'undefined' ) {
		return Promise.resolve( null );
	}

	if ( readyPromise ) {
		return readyPromise;
	}

	readyPromise = new Promise( ( resolve ) => {
		// Avoid double-insertion if the SDK script tag was added elsewhere.
		if ( document.getElementById( SDK_ID ) ) {
			waitForGlobal( siteKey, resolve );
			return;
		}

		const script = document.createElement( 'script' );
		script.id = SDK_ID;
		script.src = SDK_URL;
		script.async = true;
		script.addEventListener( 'load', () => waitForGlobal( siteKey, resolve ) );
		script.addEventListener( 'error', () => resolve( null ) );
		document.head.appendChild( script );
	} );

	return readyPromise;
}

function waitForGlobal( siteKey, resolve ) {
	const start = Date.now();
	const tick = () => {
		const radar = window.WorkOSRadar;
		if ( radar && typeof radar.init === 'function' ) {
			try {
				radar.init( { siteKey } );
			} catch ( _ ) {
				// init may be idempotent; swallow duplicate-init errors.
			}
			resolve( radar );
			return;
		}
		if ( Date.now() - start > READY_TIMEOUT_MS ) {
			resolve( null );
			return;
		}
		setTimeout( tick, 100 );
	};
	tick();
}

/**
 * Ask the loaded SDK for an action token.
 *
 * @param {string} action Action name to associate with the token.
 * @returns {Promise<string|null>}
 */
export async function getActionToken( action ) {
	if ( ! readyPromise ) {
		return null;
	}
	const sdk = await readyPromise;
	if ( ! sdk || typeof sdk.getActionToken !== 'function' ) {
		return null;
	}
	try {
		const token = await sdk.getActionToken( { action } );
		return typeof token === 'string' ? token : null;
	} catch ( _ ) {
		return null;
	}
}
