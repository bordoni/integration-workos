/**
 * Role Mapping – dynamic add/remove rows.
 *
 * @package WorkOS
 */

import '../css/role-mapping.css';

( function () {
	const config  = window.workosRoleMapping || {};
	const wpRoles = config.wpRoles || {};

	const table  = document.getElementById( 'workos-role-map-table' );
	const tbody  = table ? table.querySelector( 'tbody' ) : null;
	const addBtn = document.getElementById( 'workos-role-map-add' );

	if ( ! table || ! tbody || ! addBtn ) {
		return;
	}

	/**
	 * Build a <select> element with WP roles as options.
	 *
	 * @return {HTMLSelectElement} The select element.
	 */
	function buildRoleSelect() {
		const select = document.createElement( 'select' );
		select.name  = 'workos_role_map[values][]';

		const blank       = document.createElement( 'option' );
		blank.value       = '';
		blank.textContent = '\u2014 Select \u2014';
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
	 * Build a remove button with dashicons minus icon.
	 *
	 * @return {HTMLButtonElement} The remove button.
	 */
	function buildRemoveButton() {
		const btn     = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'workos-role-map-remove';
		btn.setAttribute( 'aria-label', 'Remove mapping' );

		const icon     = document.createElement( 'span' );
		icon.className = 'dashicons dashicons-minus';
		btn.appendChild( icon );

		return btn;
	}

	/**
	 * Update remove button visibility — hide all remove buttons when only one row remains.
	 */
	function updateRemoveButtons() {
		const rows    = tbody.querySelectorAll( '.workos-role-map-row' );
		const buttons = tbody.querySelectorAll( '.workos-role-map-remove' );

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
		td.className = 'workos-role-map-actions';
		td.appendChild( buildRemoveButton() );
		row.appendChild( td );
	}

	// Enhance existing rows: add class + remove button.
	const existingRows = tbody.querySelectorAll( 'tr' );
	existingRows.forEach(
		function ( row ) {
			row.classList.add( 'workos-role-map-row' );
			addRemoveToRow( row );
		}
	);

	// Show the add button (hidden by default until JS loads).
	addBtn.style.display = '';

	// Add a new row.
	addBtn.addEventListener(
		'click',
		function () {
			const row     = document.createElement( 'tr' );
			row.className = 'workos-role-map-row';

			const tdInput     = document.createElement( 'td' );
			const input       = document.createElement( 'input' );
			input.type        = 'text';
			input.name        = 'workos_role_map[keys][]';
			input.className   = 'regular-text';
			input.placeholder = 'New WorkOS role\u2026';
			tdInput.appendChild( input );
			row.appendChild( tdInput );

			const tdSelect = document.createElement( 'td' );
			tdSelect.appendChild( buildRoleSelect() );
			row.appendChild( tdSelect );

			const tdAction     = document.createElement( 'td' );
			tdAction.className = 'workos-role-map-actions';
			tdAction.appendChild( buildRemoveButton() );
			row.appendChild( tdAction );

			tbody.appendChild( row );
			updateRemoveButtons();
			input.focus();
		}
	);

	// Event delegation for remove buttons.
	tbody.addEventListener(
		'click',
		function ( e ) {
			const btn = e.target.closest( '.workos-role-map-remove' );
			if ( ! btn ) {
				return;
			}

			const row = btn.closest( '.workos-role-map-row' );
			if ( row ) {
				row.remove();
				updateRemoveButtons();
			}
		}
	);

	// Initial visibility check.
	updateRemoveButtons();
} )();
