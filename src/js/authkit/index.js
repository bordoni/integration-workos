/**
 * Entry point for the AuthKit React shell.
 *
 * Looks for `<div id="workos-authkit-root" data-profile="..." ...>` and
 * hydrates the App into it. Every piece of configuration arrives via
 * data-* attributes on that root div; we never fetch config after mount.
 */

import { createElement as h, createRoot } from '@wordpress/element';
import { App } from './App';
import './styles.css';

function parseConfig( root ) {
	const profileJson = root.getAttribute( 'data-profile' );
	const profile = profileJson ? JSON.parse( profileJson ) : {};

	// Compose a few derived fields the React tree uses.
	profile.restBaseUrl = root.getAttribute( 'data-rest-base' ) || '/wp-json/workos/v1/auth';
	profile.redirectTo = root.getAttribute( 'data-redirect-to' ) || '';

	return {
		profile,
		invitationToken: root.getAttribute( 'data-invitation-token' ) || '',
		resetToken: root.getAttribute( 'data-reset-token' ) || '',
		initialStep: root.getAttribute( 'data-initial-step' ) || 'pick',
	};
}

function mount() {
	const root = document.getElementById( 'workos-authkit-root' );
	if ( ! root ) {
		return;
	}

	let config;
	try {
		config = parseConfig( root );
	} catch ( err ) {
		// Malformed data-profile — leave the markup as-is so the caller can
		// inspect what shipped.
		return;
	}

	createRoot( root ).render( h( App, config ) );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
