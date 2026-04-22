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
 *   - getActionToken( action ) returns a string or null. api.ts threads
 *     that into the X-WorkOS-Radar-Action-Token header on every mutation.
 */

interface WorkOSRadarSdk {
	init( options: { siteKey: string } ): void;
	getActionToken( options: { action: string } ): Promise< string >;
}

declare global {
	interface Window {
		WorkOSRadar?: WorkOSRadarSdk;
	}
}

const SDK_URL = 'https://radar.workos.com/v1/radar.js';
const SDK_ID = 'workos-radar-sdk';
const READY_TIMEOUT_MS = 4000;

let readyPromise: Promise< WorkOSRadarSdk | null > | null = null;

/**
 * Load the Radar SDK, idempotent. Resolves when `window.WorkOSRadar` is
 * available, resolves to null on timeout / load failure so callers can
 * fall through cleanly.
 */
export function load( siteKey: string ): Promise< WorkOSRadarSdk | null > {
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

function waitForGlobal(
	siteKey: string,
	resolve: ( sdk: WorkOSRadarSdk | null ) => void
): void {
	const start = Date.now();
	const tick = (): void => {
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
 */
export async function getActionToken(
	action: string
): Promise< string | null > {
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
