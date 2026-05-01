/**
 * Organization-refresh button — re-fetches WorkOS organizations on demand.
 *
 * Hits GET /workos/v1/admin/profiles/organizations?refresh=1 via wp.apiFetch
 * (REST, not admin-ajax) so the dropdown updates without a page reload after
 * an admin creates an org in the WorkOS dashboard. The selected option is
 * preserved across the swap when it still exists in the new list.
 *
 * @package WorkOS
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import '../css/organization-refresh.css';

( function () {
	const config   = window.workosOrgRefresh || {};
	const restPath = config.restPath || '/workos/v1/admin/profiles/organizations';

	const wrapper = document.querySelector( '[data-workos-org-select-wrapper]' );
	if ( ! wrapper ) {
		return;
	}

	const select  = wrapper.querySelector( '[data-workos-org-select]' );
	const button  = wrapper.querySelector( '[data-workos-org-refresh]' );
	const spinner = wrapper.querySelector( '[data-workos-org-refresh-spinner]' );
	const error   = document.querySelector( '[data-workos-org-refresh-error]' );

	if ( ! select || ! button || ! spinner ) {
		return;
	}

	function setBusy( busy ) {
		button.disabled = busy;
		select.disabled = busy;
		wrapper.classList.toggle( 'is-busy', busy );
		spinner.classList.toggle( 'is-active', busy );
	}

	function showError( message ) {
		if ( ! error ) {
			return;
		}
		if ( message ) {
			error.textContent = message;
			error.removeAttribute( 'hidden' );
		} else {
			error.textContent = '';
			error.setAttribute( 'hidden', '' );
		}
	}

	function repopulate( organizations ) {
		const previousValue = select.value;
		const placeholder   = select.querySelector( 'option[value=""]' );
		const placeholderText = placeholder
			? placeholder.textContent
			: '— ' + __( 'Select Organization', 'integration-workos' ) + ' —';

		select.innerHTML = '';

		const blank       = document.createElement( 'option' );
		blank.value       = '';
		blank.textContent = placeholderText;
		select.appendChild( blank );

		organizations.forEach( function ( org ) {
			const opt       = document.createElement( 'option' );
			opt.value       = org.id;
			opt.textContent = org.name || org.id;
			select.appendChild( opt );
		} );

		// Preserve the previously selected value if it survives the refresh.
		if ( previousValue && select.querySelector( 'option[value="' + previousValue.replace( /"/g, '\\"' ) + '"]' ) ) {
			select.value = previousValue;
		} else {
			select.value = '';
		}
	}

	button.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		showError( '' );
		setBusy( true );

		apiFetch( {
			path:   restPath + '?refresh=1',
			method: 'GET',
		} )
			.then( function ( response ) {
				const organizations = ( response && response.organizations ) || [];
				if ( response && response.error ) {
					showError( response.error );
				} else if ( organizations.length === 0 ) {
					showError( __( 'No organizations returned by WorkOS.', 'integration-workos' ) );
				}
				repopulate( organizations );
			} )
			.catch( function ( err ) {
				const message = ( err && err.message )
					? err.message
					: __( 'Could not refresh organizations.', 'integration-workos' );
				showError( message );
			} )
			.finally( function () {
				setBusy( false );
			} );
	} );
} )();
