/**
 * WorkOS Login Button — Frontend JS
 *
 * Handles headless form toggle and AJAX submission.
 *
 * @package WorkOS
 */

import { __ } from '@wordpress/i18n';

( function () {
	'use strict';

	var config = window.workosLoginButton || {};

	function init() {
		document.querySelectorAll( '[data-workos-headless-toggle]' ).forEach(
			function ( btn ) {
				btn.addEventListener( 'click', handleToggle );
			}
		);

		document.querySelectorAll( '[data-workos-headless-form]' ).forEach(
			function ( form ) {
				form.addEventListener( 'submit', handleSubmit );
			}
		);
	}

	function handleToggle( e ) {
		var wrapper = e.target.closest( '.workos-login-button' );
		if ( ! wrapper ) {
			return;
		}

		var form = wrapper.querySelector( '[data-workos-headless-form]' );
		if ( ! form ) {
			return;
		}

		var isHidden       = form.style.display === 'none' || form.style.display === '';
		form.style.display = isHidden ? 'flex' : 'none';

		if ( isHidden ) {
			var firstInput = form.querySelector( 'input' );
			if ( firstInput ) {
				firstInput.focus();
			}
		}
	}

	function handleSubmit( e ) {
		e.preventDefault();

		var form     = e.target;
		var wrapper  = form.closest( '.workos-login-button' );
		var errorEl  = form.querySelector( '.workos-login-button__error' );
		var email    = form.querySelector( 'input[name="email"]' ).value;
		var password = form.querySelector( 'input[name="password"]' ).value;

		if ( ! email || ! password ) {
			return;
		}

		// Clear previous errors.
		if ( errorEl ) {
			errorEl.textContent = '';
		}

		// Set loading state.
		form.setAttribute( 'aria-busy', 'true' );

		var body = new FormData();
		body.append( 'action', 'workos_headless_login' );
		body.append( 'nonce', config.nonce || '' );
		body.append( 'email', email );
		body.append( 'password', password );

		// Check for a custom redirect_to on the wrapper.
		var redirectInput = wrapper ? wrapper.querySelector( 'input[name="redirect_to"]' ) : null;
		if ( redirectInput && redirectInput.value ) {
			body.append( 'redirect_to', redirectInput.value );
		}

		fetch(
			config.ajaxUrl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			}
		)
			.then(
				function ( res ) {
					return res.json();
				}
			)
			.then(
				function ( data ) {
					form.removeAttribute( 'aria-busy' );

					if ( data.success && data.data && data.data.redirect_to ) {
							window.location.href = data.data.redirect_to;
					} else {
						var msg = ( data.data && data.data.message ) || __( 'Login failed.', 'integration-workos' );
						if ( errorEl ) {
							errorEl.textContent = msg;
						}
					}
				}
			)
			.catch(
				function () {
					form.removeAttribute( 'aria-busy' );
					if ( errorEl ) {
							errorEl.textContent = __( 'An error occurred. Please try again.', 'integration-workos' );
					}
				}
			);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
