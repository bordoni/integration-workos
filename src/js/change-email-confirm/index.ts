/**
 * Frontend confirm page logic.
 *
 * The PHP template (`templates/change-email/confirm-page.php`) prints
 * the token + user_id into a `data-*` host element and renders a
 * placeholder status. This script reads those values, decides whether
 * the user is confirming or cancelling (the cancel link in the
 * old-address notice appends `?action=cancel`), POSTs to the right REST
 * endpoint, and rewrites the host's contents with the outcome.
 *
 * We do the mutation in JS — rather than handling the link as a GET on
 * the server — so an email-prefetch scanner that hits the link can't
 * accidentally consume the token. (Browsers + reasonable mail clients
 * never POST on prefetch.)
 */

import { __ } from '@wordpress/i18n';
import './styles.css';

interface ConfirmConfig {
	restUrl: string;
	nonce: string;
	strings: {
		confirming: string;
		cancelling: string;
		success: string;
		cancelled: string;
		errorGeneric: string;
		continue: string;
	};
}

interface SuccessResponse {
	ok: true;
	redirect_url?: string;
}

interface ErrorResponse {
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		workosChangeEmailConfirm?: ConfirmConfig;
	}
}

function getConfig(): ConfirmConfig | null {
	return window.workosChangeEmailConfirm ?? null;
}

function setStatus(
	host: HTMLElement,
	message: string,
	kind: 'pending' | 'success' | 'error',
	redirectUrl?: string
): void {
	const klass =
		kind === 'success' ? 'is-success' : kind === 'error' ? 'is-error' : '';
	host.className = klass;

	host.innerHTML = '';
	const p = document.createElement( 'p' );
	p.textContent = message;
	host.appendChild( p );

	if ( kind === 'success' && redirectUrl ) {
		const a = document.createElement( 'a' );
		a.href = redirectUrl;
		a.className = 'button';
		a.textContent = getConfig()?.strings.continue ||
			__( 'Continue', 'integration-workos' );
		host.appendChild( a );
	}
}

function getActionFromUrl(): 'cancel' | 'confirm' {
	const params = new URLSearchParams( window.location.search );
	return params.get( 'action' ) === 'cancel' ? 'cancel' : 'confirm';
}

async function run(): Promise< void > {
	const host = document.getElementById( 'workos-change-email-confirm-status' );
	if ( ! host ) {
		return;
	}

	const config = getConfig();
	if ( ! config ) {
		setStatus( host, __( 'Confirmation is unavailable.', 'integration-workos' ), 'error' );
		return;
	}

	const token = host.getAttribute( 'data-token' ) || '';
	const userId = Number( host.getAttribute( 'data-user-id' ) || '0' );
	const redirectUrl = host.getAttribute( 'data-redirect-url' ) || '';

	if ( '' === token || ! userId ) {
		setStatus( host, config.strings.errorGeneric, 'error' );
		return;
	}

	const action = getActionFromUrl();
	const path = action === 'cancel' ? 'email-change/cancel' : 'email-change/confirm';

	setStatus(
		host,
		action === 'cancel' ? config.strings.cancelling : config.strings.confirming,
		'pending'
	);

	try {
		const url = `${ config.restUrl }${ userId }/${ path }`;
		// Forward the post-confirm redirect target (re-validated server-side).
		// Irrelevant for the cancel path, which ignores it.
		const payload: { token: string; redirect_url?: string } = { token };
		if ( action !== 'cancel' && '' !== redirectUrl ) {
			payload.redirect_url = redirectUrl;
		}
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( payload ),
		} );

		const data = ( await response.json() ) as
			| SuccessResponse
			| ErrorResponse;

		if ( ! response.ok ) {
			const err = data as ErrorResponse;
			setStatus(
				host,
				err.message || config.strings.errorGeneric,
				'error'
			);
			return;
		}

		const ok = data as SuccessResponse;
		setStatus(
			host,
			action === 'cancel' ? config.strings.cancelled : config.strings.success,
			'success',
			action === 'cancel' ? undefined : ok.redirect_url
		);
	} catch ( _ ) {
		setStatus( host, config.strings.errorGeneric, 'error' );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => void run() );
} else {
	void run();
}
