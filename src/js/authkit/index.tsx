/**
 * Entry point for the AuthKit React shell.
 *
 * Looks for `<div id="workos-authkit-root" data-profile="..." ...>` and
 * hydrates the App into it. Every piece of configuration arrives via
 * data-* attributes on that root div; we never fetch config after mount.
 */

import { SlotFillProvider } from '@wordpress/components';
import { createRoot } from '@wordpress/element';
import { PluginArea } from '@wordpress/plugins';
import { App } from './App';
import type { AppProps } from './App';
import type { MfaRequired, Profile, Step } from './types';
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
		auto_login_after_reset: rawProfile.auto_login_after_reset ?? true,
		mfa: rawProfile.mfa ?? { enforce: 'if_required', factors: [ 'totp' ] },
		branding: rawProfile.branding ?? {
			logo_mode: 'default',
			logo_attachment_id: 0,
			primary_color: '',
			heading: '',
			subheading: '',
		},
		post_login_redirect: rawProfile.post_login_redirect ?? '',
		forward_query_args: rawProfile.forward_query_args ?? false,
		mode: rawProfile.mode ?? 'custom',
		restBaseUrl:
			root.getAttribute( 'data-rest-base' ) || '/wp-json/workos/v1/auth',
		redirectTo: root.getAttribute( 'data-redirect-to' ) || '',
		siteName: root.getAttribute( 'data-site-name' ) || '',
		siteUrl: root.getAttribute( 'data-site-url' ) || '',
		showChrome: root.getAttribute( 'data-show-chrome' ) === '1',
		originalQuery: window.location.search || '',
	};

	const initialStep = ( root.getAttribute( 'data-initial-step' ) ||
		'pick' ) as Step;

	// A sign-in parked at the MFA step (e.g. after the hosted-AuthKit
	// callback) resumes straight at the code or enrollment step.
	const mfaJson = root.getAttribute( 'data-mfa' );
	const mfaResume: MfaRequired | undefined = mfaJson
		? JSON.parse( mfaJson )
		: undefined;

	// The resume code in the URL is spent; keep it out of history.
	const url = new URL( window.location.href );
	if ( url.searchParams.has( 'workos_mfa' ) ) {
		url.searchParams.delete( 'workos_mfa' );
		window.history.replaceState( window.history.state, '', url.toString() );
	}

	return {
		profile,
		invitationToken: root.getAttribute( 'data-invitation-token' ) || '',
		resetToken: root.getAttribute( 'data-reset-token' ) || '',
		initialStep,
		mfaResume,
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

	createRoot( root ).render(
		<SlotFillProvider>
			<App { ...config } />
			<PluginArea scope="workos-authkit" />
		</SlotFillProvider>
	);

	// Signal to non-React extenders that the shell is mounted and slots are
	// live. Carries the active profile slug so listeners can gate behavior.
	document.dispatchEvent(
		new CustomEvent( 'workos-authkit:mounted', {
			detail: { profileSlug: config.profile.slug },
		} )
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
