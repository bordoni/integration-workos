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
import { confirmModal } from '../shared/modal';
import './styles.css';

/**
 * Runtime config injected via `wp_localize_script()` on `window.workosPasswordReset`.
 *
 * Mirrors the shape produced by {@link \WorkOS\Auth\PasswordResetAdmin\Assets::register_assets()}.
 */
interface PasswordResetConfig {
	/** Base REST URL — admin endpoint is `${restUrl}{id}/password-reset`. */
	restUrl: string;
	/** WP REST nonce (`wp_rest`). */
	nonce: string;
	/** Pre-translated UI strings (admin locale, server-side translated). */
	strings: {
		modalTitle: string;
		modalMessage: string;
		modalConfirm: string;
		modalCancel: string;
		sending: string;
		success: string;
		errorGeneric: string;
	};
}

/**
 * Successful response payload from `POST /workos/v1/admin/users/{id}/password-reset`.
 */
interface SuccessResponse {
	ok: true;
	/** Masked email like `j•••@e•••.com` — never the full address. */
	email_hint: string;
}

/**
 * Error response payload (`code`/`message` shape returned by `WP_Error::__construct`).
 */
interface ErrorResponse {
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		workosPasswordReset?: PasswordResetConfig;
	}
}

/**
 * Read the localized config from the global namespace.
 *
 * Returns null when the script was loaded without its localize-script
 * companion (e.g. on a page outside the registered admin screens).
 */
function getConfig(): PasswordResetConfig | null {
	return window.workosPasswordReset ?? null;
}

/**
 * Render a transient WP admin notice at the top of the current screen.
 *
 * Replaces any previously rendered notice with the same id so rapid
 * clicks don't stack multiple banners. Auto-dismisses after 7 seconds.
 *
 * @param message Pre-translated message to display.
 * @param kind    Visual variant — maps to `.notice-success` / `.notice-error`.
 */
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

/**
 * Send a password-reset request for the user identified by a trigger element.
 *
 * Reads `data-user-id`, `data-redirect-url`, and `data-profile` off the
 * button, prompts the operator to confirm, then POSTs to the admin REST
 * endpoint with the WP REST nonce. The button is put in a busy state
 * while the request is in-flight and restored in the `finally` block so
 * a failed call leaves the UI usable.
 *
 * @param button Trigger element clicked by the operator.
 */
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

	const confirmed = await confirmModal( {
		title: config.strings.modalTitle,
		message: config.strings.modalMessage,
		confirmLabel: config.strings.modalConfirm,
		cancelLabel: config.strings.modalCancel,
	} );
	if ( ! confirmed ) {
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

/**
 * Document-level click listener — delegates to {@link sendReset} when the
 * click originates inside a `.workos-pwreset-trigger` element.
 *
 * Delegation (vs per-element binding) lets surfaces like the users.php
 * row action and the shortcode share one bound handler regardless of
 * when the trigger element is rendered into the DOM.
 */
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
