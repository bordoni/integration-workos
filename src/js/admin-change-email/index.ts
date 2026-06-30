/**
 * Click handler for the "Change email" admin trigger and the
 * `[workos:change-email]` self-service shortcode.
 *
 * Two entry shapes share one delegated handler:
 *
 *  - Standalone trigger button (admin row action / profile panel) — the
 *    handler opens a WP-styled modal asking for the new email address.
 *  - Form trigger inside a `.workos-change-email-form` — the handler
 *    pulls the new address from the form's `.workos-change-email-input`.
 */

import { __, sprintf } from '@wordpress/i18n';
import { promptModal } from '../shared/modal';
import './styles.css';

/**
 * Runtime config injected via `wp_localize_script()` on `window.workosChangeEmail`.
 *
 * Mirrors the shape produced by {@link \WorkOS\Auth\ChangeEmail\Assets::register_admin_assets()}.
 */
interface ChangeEmailConfig {
	/** Base REST URL — endpoint is `${restUrl}{id}/email-change`. */
	restUrl: string;
	/** WP REST nonce (`wp_rest`). */
	nonce: string;
	/** Pre-translated UI strings. */
	strings: {
		modalTitle: string;
		modalMessage: string;
		modalInputLabel: string;
		modalPlaceholder: string;
		modalConfirm: string;
		modalCancel: string;
		sending: string;
		success: string;
		successImmediate: string;
		errorGeneric: string;
		invalidEmail: string;
	};
}

interface SuccessResponse {
	ok: true;
	masked_new_email?: string;
	expires_at?: number;
	no_op?: boolean;
	/** Set when an admin change committed immediately (no verification). */
	committed?: boolean;
	/** New address, returned only on an immediate admin commit. */
	email?: string;
}

interface ErrorResponse {
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		workosChangeEmail?: ChangeEmailConfig;
	}
}

function getConfig(): ChangeEmailConfig | null {
	return window.workosChangeEmail ?? null;
}

/** Loose RFC-style email check — server is authoritative. */
function isLikelyEmail( value: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
}

/**
 * Render a transient WP admin notice. Mirrors PasswordReset's notice
 * surface so the two flows feel identical in admin.
 */
function showNotice( message: string, kind: 'success' | 'error' ): void {
	const existing = document.getElementById( 'workos-change-email-notice' );
	if ( existing ) {
		existing.remove();
	}

	const notice = document.createElement( 'div' );
	notice.id = 'workos-change-email-notice';
	notice.className = `notice notice-${ kind } is-dismissible workos-change-email-notice`;
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

/**
 * Update the inline status div inside a shortcode form (if present).
 */
function showInlineStatus(
	form: HTMLElement | null,
	message: string,
	kind: 'success' | 'error'
): void {
	if ( ! form ) {
		return;
	}
	const status = form.querySelector< HTMLElement >(
		'.workos-change-email-status'
	);
	if ( ! status ) {
		return;
	}
	status.textContent = message;
	status.classList.remove( 'is-success', 'is-error' );
	status.classList.add( `is-${ kind }` );
}

async function sendChange( trigger: HTMLElement ): Promise< void > {
	const config = getConfig();
	if ( ! config ) {
		return;
	}

	// Form mode vs standalone-button mode.
	const form = trigger.closest< HTMLElement >( '.workos-change-email-form' );
	const userId = Number(
		( form ?? trigger ).getAttribute( 'data-user-id' ) || '0'
	);
	if ( ! userId ) {
		return;
	}

	const redirectUrl =
		( form ?? trigger ).getAttribute( 'data-redirect-url' ) || '';

	let newEmail = '';
	if ( form ) {
		const input = form.querySelector< HTMLInputElement >(
			'.workos-change-email-input'
		);
		newEmail = input?.value.trim() || '';

		if ( '' === newEmail ) {
			return;
		}

		if ( ! isLikelyEmail( newEmail ) ) {
			showInlineStatus( form, config.strings.invalidEmail, 'error' );
			return;
		}
	} else {
		// Standalone-button mode: open a WP-styled modal. The modal
		// runs the email-shape check internally and refuses to close
		// on invalid input; a cancel resolves to null.
		const result = await promptModal( {
			title: config.strings.modalTitle,
			message: config.strings.modalMessage,
			inputLabel: config.strings.modalInputLabel,
			inputType: 'email',
			placeholder: config.strings.modalPlaceholder,
			confirmLabel: config.strings.modalConfirm,
			cancelLabel: config.strings.modalCancel,
			validate: ( value: string ) =>
				isLikelyEmail( value ) ? null : config.strings.invalidEmail,
		} );
		if ( null === result || '' === result ) {
			return;
		}
		newEmail = result;
	}

	const original = trigger.innerHTML;
	trigger.setAttribute( 'disabled', 'disabled' );
	trigger.classList.add( 'is-busy' );
	trigger.textContent = config.strings.sending;

	if ( form ) {
		showInlineStatus( form, config.strings.sending, 'success' );
	}

	try {
		const url = `${ config.restUrl }${ userId }/email-change`;
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( {
				new_email: newEmail,
				redirect_url: redirectUrl,
			} ),
		} );

		const data = ( await response.json() ) as
			| SuccessResponse
			| ErrorResponse;

		if ( ! response.ok ) {
			const err = data as ErrorResponse;
			const msg = err.message || config.strings.errorGeneric;
			if ( form ) {
				showInlineStatus( form, msg, 'error' );
			} else {
				showNotice( msg, 'error' );
			}
			return;
		}

		const ok = data as SuccessResponse;
		// An admin acting on another account commits immediately; a
		// self-service change is still pending a verification click.
		let msg: string;
		if ( ok.committed ) {
			const addr = ok.email || __( 'the new address', 'integration-workos' );
			msg = sprintf( config.strings.successImmediate, addr );
		} else {
			const masked =
				ok.masked_new_email || __( 'the new address', 'integration-workos' );
			msg = sprintf( config.strings.success, masked );
		}
		if ( form ) {
			showInlineStatus( form, msg, 'success' );
		} else {
			showNotice( msg, 'success' );
		}
	} catch ( _ ) {
		const msg = config.strings.errorGeneric;
		if ( form ) {
			showInlineStatus( form, msg, 'error' );
		} else {
			showNotice( msg, 'error' );
		}
	} finally {
		trigger.removeAttribute( 'disabled' );
		trigger.classList.remove( 'is-busy' );
		trigger.innerHTML = original;
	}
}

document.addEventListener( 'click', ( event ) => {
	const target = event.target as HTMLElement | null;
	if ( ! target ) {
		return;
	}

	const trigger = target.closest< HTMLElement >(
		'.workos-change-email-trigger'
	);
	if ( ! trigger ) {
		return;
	}

	event.preventDefault();
	void sendChange( trigger );
} );
