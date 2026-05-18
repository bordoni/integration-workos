/**
 * Click handler for the "Send WorkOS password reset" trigger.
 *
 * Listens for clicks on any `.workos-pwreset-trigger` element on the
 * page — present on the WP users.php row action, the user-edit screen
 * panel, the WorkOS Users admin page, and the `[workos:password-reset]`
 * shortcode. POSTs to `POST /workos/v1/admin/users/{id}/password-reset`
 * with the per-element `data-user-id`, `data-redirect-url`, and
 * `data-profile` attributes.
 */

import { __, sprintf } from '@wordpress/i18n';
import './styles.css';

interface PasswordResetConfig {
	restUrl: string;
	nonce: string;
	strings: {
		confirm: string;
		sending: string;
		success: string;
		errorGeneric: string;
	};
}

interface SuccessResponse {
	ok: true;
	email_hint: string;
}

interface ErrorResponse {
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		workosPasswordReset?: PasswordResetConfig;
	}
}

function getConfig(): PasswordResetConfig | null {
	return window.workosPasswordReset ?? null;
}

function showNotice( message: string, kind: 'success' | 'error' ): void {
	const existing = document.getElementById(
		'workos-pwreset-notice'
	) as HTMLDivElement | null;
	if ( existing ) {
		existing.remove();
	}

	const notice = document.createElement( 'div' );
	notice.id = 'workos-pwreset-notice';
	notice.className = `notice notice-${ kind } is-dismissible workos-pwreset-notice`;
	notice.setAttribute( 'role', 'status' );

	const p = document.createElement( 'p' );
	p.textContent = message;
	notice.appendChild( p );

	const target =
		document.querySelector( '.wrap > h1, .wrap > h2' )?.parentElement ||
		document.body;
	target.insertBefore( notice, target.firstChild );

	setTimeout( () => {
		notice.remove();
	}, 7000 );
}

async function sendReset( button: HTMLElement ): Promise< void > {
	const config = getConfig();
	if ( ! config ) {
		return;
	}

	const userId = Number( button.getAttribute( 'data-user-id' ) || '0' );
	if ( ! userId ) {
		return;
	}

	const redirectUrl = button.getAttribute( 'data-redirect-url' ) || '';
	const profile = button.getAttribute( 'data-profile' ) || '';

	if ( ! window.confirm( config.strings.confirm ) ) {
		return;
	}

	const original = button.innerHTML;
	button.setAttribute( 'disabled', 'disabled' );
	button.classList.add( 'is-busy' );
	button.textContent = config.strings.sending;

	try {
		const url = `${ config.restUrl }${ userId }/password-reset`;
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( {
				redirect_url: redirectUrl,
				profile,
			} ),
		} );

		const data = ( await response.json() ) as
			| SuccessResponse
			| ErrorResponse;

		if ( ! response.ok ) {
			const err = data as ErrorResponse;
			showNotice(
				err.message || config.strings.errorGeneric,
				'error'
			);
			return;
		}

		const ok = data as SuccessResponse;
		showNotice(
			sprintf(
				config.strings.success,
				ok.email_hint || __( 'the user', 'integration-workos' )
			),
			'success'
		);
	} catch ( _ ) {
		showNotice( config.strings.errorGeneric, 'error' );
	} finally {
		button.removeAttribute( 'disabled' );
		button.classList.remove( 'is-busy' );
		button.innerHTML = original;
	}
}

document.addEventListener( 'click', ( event ) => {
	const target = event.target as HTMLElement | null;
	if ( ! target ) {
		return;
	}

	const trigger = target.closest< HTMLElement >(
		'.workos-pwreset-trigger'
	);
	if ( ! trigger ) {
		return;
	}

	event.preventDefault();
	void sendReset( trigger );
} );
