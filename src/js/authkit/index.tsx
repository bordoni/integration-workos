/**
 * Entry point for the AuthKit React shell.
 *
 * Looks for `<div id="workos-authkit-root" data-profile="..." ...>` and
 * hydrates the App into it. Every piece of configuration arrives via
 * data-* attributes on that root div; we never fetch config after mount.
 */

import { createRoot } from '@wordpress/element';
import { App } from './App';
import type { AppProps } from './App';
import type { Profile, Step } from './types';
import './styles.css';

function parseConfig( root: HTMLElement ): AppProps {
	const profileJson = root.getAttribute( 'data-profile' );
	const rawProfile: Partial< Profile > = profileJson ? JSON.parse( profileJson ) : {};

	// Compose a few derived fields the React tree uses.
	const profile: Profile = {
		id: rawProfile.id,
		slug: rawProfile.slug ?? '',
		title: rawProfile.title ?? '',
		methods: rawProfile.methods ?? [],
		organization_id: rawProfile.organization_id ?? '',
		signup: rawProfile.signup ?? { enabled: false, require_invite: false },
		invite_flow: rawProfile.invite_flow ?? true,
		password_reset_flow: rawProfile.password_reset_flow ?? true,
		mfa: rawProfile.mfa ?? { enforce: 'if_required', factors: [ 'totp' ] },
		branding: rawProfile.branding ?? {
			logo_attachment_id: 0,
			primary_color: '',
			heading: '',
			subheading: '',
		},
		post_login_redirect: rawProfile.post_login_redirect ?? '',
		mode: rawProfile.mode ?? 'custom',
		restBaseUrl:
			root.getAttribute( 'data-rest-base' ) || '/wp-json/workos/v1/auth',
		redirectTo: root.getAttribute( 'data-redirect-to' ) || '',
	};

	const initialStep = ( root.getAttribute( 'data-initial-step' ) ||
		'pick' ) as Step;

	return {
		profile,
		invitationToken: root.getAttribute( 'data-invitation-token' ) || '',
		resetToken: root.getAttribute( 'data-reset-token' ) || '',
		initialStep,
	};
}

function mount(): void {
	const root = document.getElementById( 'workos-authkit-root' );
	if ( ! root ) {
		return;
	}

	let config: AppProps;
	try {
		config = parseConfig( root );
	} catch ( _ ) {
		// Malformed data-profile — leave the markup as-is so the caller can
		// inspect what shipped.
		return;
	}

	createRoot( root ).render( <App { ...config } /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
