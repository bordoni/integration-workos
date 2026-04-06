/**
 * Role Mapping – dynamic add/remove rows.
 *
 * @package WorkOS
 */

import '../css/role-mapping.css';

( function () {
	const config         = window.workosRoleMapping || {};
	const wpRoles        = config.wpRoles || {};
	const workosRoles    = config.workosRoles || {};
	const env            = config.env || 'production';
	const hasWorkosRoles = Object.keys( workosRoles ).length > 0;

	const table  = document.getElementById( 'workos-role-map-table' );
	const tbody  = table ? table.querySelector( 'tbody' ) : null;
	const addBtn = document.getElementById( 'workos-role-map-add' );

	if ( ! table || ! tbody || ! addBtn ) {
		return;
	}

	/**
	 * Validate duplicate WorkOS role selections across all rows.
	 *
	 * Adds/removes the `workos-role-map-row-duplicate` class on each row and
	 * shows/hides a warning message below the table.
	 *
	 * @return {boolean} True if duplicates exist, false if clean.
	 */
	function validateDuplicates() {
		const rows   = tbody.querySelectorAll( '.workos-role-map-row' );
		const counts = {};

		// Count occurrences of each non-empty WorkOS role value.
		rows.forEach(
			function ( row ) {
				const firstTd = row.querySelector( 'td' );
				if ( ! firstTd ) {
						return;
				}
				const input = firstTd.querySelector( 'select, input' );
				if ( ! input ) {
					return;
				}
				const val = input.value.trim();
				if ( val === '' ) {
					return;
				}
				counts[ val ] = ( counts[ val ] || 0 ) + 1;
			}
		);

		// Determine which values are duplicated.
		const duplicated = {};
		Object.keys( counts ).forEach(
			function ( key ) {
				if ( counts[ key ] > 1 ) {
						duplicated[ key ] = true;
				}
			}
		);

		const hasDuplicates = Object.keys( duplicated ).length > 0;

		// Toggle the duplicate class on each row.
		rows.forEach(
			function ( row ) {
				const firstTd = row.querySelector( 'td' );
				if ( ! firstTd ) {
						return;
				}
				const input = firstTd.querySelector( 'select, input' );
				const val   = input ? input.value.trim() : '';

				if ( val !== '' && duplicated[ val ] ) {
					row.classList.add( 'workos-role-map-row-duplicate' );
				} else {
					row.classList.remove( 'workos-role-map-row-duplicate' );
				}
			}
		);

		// Show or hide the duplicate warning.
		let warning = document.getElementById( 'workos-role-map-duplicate-warning' );

		if ( hasDuplicates && ! warning ) {
			warning           = document.createElement( 'div' );
			warning.id        = 'workos-role-map-duplicate-warning';
			warning.className = 'workos-role-map-duplicate-warning';
			warning.innerHTML = '<p>' +
				'Duplicate WorkOS role detected. Each WorkOS role may only be mapped once.' +
				'</p>';
			table.parentNode.insertBefore( warning, table.nextSibling );
		} else if ( ! hasDuplicates && warning ) {
			warning.remove();
		}

		return hasDuplicates;
	}

	/**
	 * Build a <select> element with WP roles as options.
	 *
	 * @return {HTMLSelectElement} The select element.
	 */
	function buildWpRoleSelect() {
		const select = document.createElement( 'select' );
		select.name  = 'workos_' + env + '[role_map][values][]';

		const blank       = document.createElement( 'option' );
		blank.value       = '';
		blank.textContent = '\u2014 No Role \u2014';
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
	 * Build a WorkOS role input — <select> if roles are available, text input fallback.
	 *
	 * @return {HTMLElement} The select or input element.
	 */
	function buildWorkosRoleInput() {
		if ( hasWorkosRoles ) {
			const select = document.createElement( 'select' );
			select.name  = 'workos_' + env + '[role_map][keys][]';

			const blank       = document.createElement( 'option' );
			blank.value       = '';
			blank.textContent = '\u2014 No Role \u2014';
			select.appendChild( blank );

			Object.keys( workosRoles ).forEach(
				function ( slug ) {
					const opt       = document.createElement( 'option' );
					opt.value       = slug;
					opt.textContent = workosRoles[ slug ];
					select.appendChild( opt );
				}
			);

			return select;
		}

		const input       = document.createElement( 'input' );
		input.type        = 'text';
		input.name        = 'workos_' + env + '[role_map][keys][]';
		input.className   = 'regular-text';
		input.placeholder = 'New WorkOS role\u2026';
		return input;
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

	/**
	 * Show the change-detection warning when a mapping is modified.
	 */
	function showChangeWarning() {
		if ( document.getElementById( 'workos-role-map-change-warning' ) ) {
			return;
		}

		const warning     = document.createElement( 'div' );
		warning.id        = 'workos-role-map-change-warning';
		warning.className = 'workos-role-map-change-warning';
		warning.innerHTML = '<p>' +
			'Changing role mappings may put existing users out of sync. ' +
			'Save settings and visit the <a href="users.php">Users page</a> to review affected users.' +
			'</p>';

		table.parentNode.insertBefore( warning, table.nextSibling );
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
	addBtn.style.display = 'inline-flex';

	// Add a new row.
	addBtn.addEventListener(
		'click',
		function () {
			const row     = document.createElement( 'tr' );
			row.className = 'workos-role-map-row';

			const tdWorkos    = document.createElement( 'td' );
			const workosInput = buildWorkosRoleInput();
			tdWorkos.appendChild( workosInput );
			row.appendChild( tdWorkos );

			const tdSelect = document.createElement( 'td' );
			tdSelect.appendChild( buildWpRoleSelect() );
			row.appendChild( tdSelect );

			const tdAction     = document.createElement( 'td' );
			tdAction.className = 'workos-role-map-actions';
			tdAction.appendChild( buildRemoveButton() );
			row.appendChild( tdAction );

			tbody.appendChild( row );
			updateRemoveButtons();
			validateDuplicates();
			workosInput.focus();
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
				showChangeWarning();
				validateDuplicates();
			}
		}
	);

	// Change detection on selects and inputs within the table.
	table.addEventListener(
		'change',
		function () {
			showChangeWarning();
			validateDuplicates();
		}
	);

	// Block form submission when duplicate WorkOS roles exist.
	const form = table.closest( 'form' );
	if ( form ) {
		form.addEventListener(
			'submit',
			function ( e ) {
				if ( validateDuplicates() ) {
					e.preventDefault();
					const warning = document.getElementById( 'workos-role-map-duplicate-warning' );
					if ( warning ) {
						warning.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				}
			}
		);
	}

	// Initial visibility and duplicate checks.
	updateRemoveButtons();
	validateDuplicates();
} )();
