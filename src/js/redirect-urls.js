/**
 * Redirect URLs – dynamic add/remove rows.
 *
 * @package WorkOS
 */

import '../css/redirect-urls.css';

( function () {
	const config  = window.workosRedirectUrls || {};
	const wpRoles = config.wpRoles || {};
	const env     = config.env || '';

	const table  = document.getElementById( 'workos-redirect-urls-table' );
	const tbody  = table ? table.querySelector( 'tbody' ) : null;
	const addBtn = document.getElementById( 'workos-redirect-urls-add' );

	if ( ! table || ! tbody || ! addBtn ) {
		return;
	}

	// Start counter after existing PHP-rendered rows.
	let rowIndex = tbody.querySelectorAll( 'tr' ).length;

	/**
	 * Build a <select> element with WP roles as options.
	 *
	 * @param {number} index Row index for the field name.
	 * @return {HTMLSelectElement} The select element.
	 */
	function buildRoleSelect( index ) {
		const select = document.createElement( 'select' );
		select.name  = 'workos_' + env + '[redirect_urls][keys][' + index + ']';

		const blank       = document.createElement( 'option' );
		blank.value       = '';
		blank.textContent = '\u2014 Select Role \u2014';
		select.appendChild( blank );

		Object.keys( wpRoles ).forEach(
			function ( slug ) {
				const opt       = document.createElement( 'option' );
				opt.value       = slug;
				opt.textContent = wpRoles[ slug ];
				select.appendChild( opt );
			}
		);

		return select;
	}

	/**
	 * Build the first-login-only checkbox cell contents.
	 *
	 * Uses a hidden input + checkbox pattern so unchecked rows submit "0".
	 *
	 * @param {number} index Row index for the field name.
	 * @return {DocumentFragment} Fragment with hidden input and checkbox.
	 */
	function buildFirstLoginInputs( index ) {
		const fragment = document.createDocumentFragment();
		const fieldName = 'workos_' + env + '[redirect_urls][first_login][' + index + ']';

		const hidden  = document.createElement( 'input' );
		hidden.type   = 'hidden';
		hidden.name   = fieldName;
		hidden.value  = '0';
		fragment.appendChild( hidden );

		const checkbox = document.createElement( 'input' );
		checkbox.type  = 'checkbox';
		checkbox.name  = fieldName;
		checkbox.value = '1';
		fragment.appendChild( checkbox );

		return fragment;
	}

	/**
	 * Build a remove button with dashicons minus icon.
	 *
	 * @return {HTMLButtonElement} The remove button.
	 */
	function buildRemoveButton() {
		const btn     = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'workos-redirect-url-remove';
		btn.setAttribute( 'aria-label', 'Remove redirect' );

		const icon     = document.createElement( 'span' );
		icon.className = 'dashicons dashicons-minus';
		btn.appendChild( icon );

		return btn;
	}

	/**
	 * Update remove button visibility — hide all remove buttons when only one row remains.
	 */
	function updateRemoveButtons() {
		const rows    = tbody.querySelectorAll( '.workos-redirect-url-row' );
		const buttons = tbody.querySelectorAll( '.workos-redirect-url-remove' );

		buttons.forEach(
			function ( btn ) {
				btn.style.display = rows.length <= 1 ? 'none' : '';
			}
		);
	}

	/**
	 * Add a remove button to an existing PHP-rendered row.
	 *
	 * @param {HTMLTableRowElement} row The table row.
	 */
	function addRemoveToRow( row ) {
		const td     = document.createElement( 'td' );
		td.className = 'workos-redirect-url-actions';
		td.appendChild( buildRemoveButton() );
		row.appendChild( td );
	}

	// Enhance existing rows: add class + remove button.
	const existingRows = tbody.querySelectorAll( 'tr' );
	existingRows.forEach(
		function ( row ) {
			row.classList.add( 'workos-redirect-url-row' );
			addRemoveToRow( row );
		}
	);

	// Show the add button (hidden by default until JS loads).
	addBtn.style.display = '';

	// Add a new row.
	addBtn.addEventListener(
		'click',
		function () {
			const index = rowIndex++;
			const row   = document.createElement( 'tr' );
			row.className = 'workos-redirect-url-row';

			const tdSelect = document.createElement( 'td' );
			tdSelect.appendChild( buildRoleSelect( index ) );
			row.appendChild( tdSelect );

			const tdInput     = document.createElement( 'td' );
			const input       = document.createElement( 'input' );
			input.type        = 'text';
			input.name        = 'workos_' + env + '[redirect_urls][values][' + index + ']';
			input.className   = 'regular-text';
			input.placeholder = '/welcome';
			tdInput.appendChild( input );
			row.appendChild( tdInput );

			const tdFirstLogin     = document.createElement( 'td' );
			tdFirstLogin.className = 'workos-redirect-url-first-login';
			tdFirstLogin.appendChild( buildFirstLoginInputs( index ) );
			row.appendChild( tdFirstLogin );

			const tdAction     = document.createElement( 'td' );
			tdAction.className = 'workos-redirect-url-actions';
			tdAction.appendChild( buildRemoveButton() );
			row.appendChild( tdAction );

			tbody.appendChild( row );
			updateRemoveButtons();
			tdSelect.querySelector( 'select' ).focus();
		}
	);

	// Event delegation for remove buttons.
	tbody.addEventListener(
		'click',
		function ( e ) {
			const btn = e.target.closest( '.workos-redirect-url-remove' );
			if ( ! btn ) {
				return;
			}

			const row = btn.closest( '.workos-redirect-url-row' );
			if ( row ) {
				row.remove();
				updateRemoveButtons();
			}
		}
	);

	// Initial visibility check.
	updateRemoveButtons();
} )();
