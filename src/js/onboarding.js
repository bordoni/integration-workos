/**
 * WorkOS Onboarding — batch user sync UI.
 *
 * @package WorkOS
 */

/* global workosOnboarding */

import { __, _n, sprintf } from '@wordpress/i18n';

( function () {
	'use strict';

	const { ajaxUrl, nonce } = workosOnboarding;
	let currentPage = 1;

	/**
	 * Make an AJAX request.
	 *
	 * @param {string} action AJAX action name.
	 * @param {Object} data   Additional POST data.
	 * @return {Promise<Object>} Response data.
	 */
	async function ajax( action, data = {} ) {
		const formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', nonce );

		for ( const [ key, value ] of Object.entries( data ) ) {
			if ( Array.isArray( value ) ) {
				value.forEach( ( v ) => formData.append( `${ key }[]`, v ) );
			} else {
				formData.append( key, value );
			}
		}

		const response = await fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );

		return response.json();
	}

	/**
	 * Load and render the users table.
	 *
	 * @param {number} page Page number.
	 */
	async function loadUsers( page = 1 ) {
		currentPage = page;
		const tbody = document.getElementById( 'workos-users-tbody' );
		tbody.innerHTML = `<tr><td colspan="5">${ escHtml(
			__( 'Loading…', 'integration-workos' )
		) }</td></tr>`;

		const result = await ajax( 'workos_onboarding_get_users', { page } );

		if ( ! result.success ) {
			const message =
				result.data?.message || __( 'Unknown error', 'integration-workos' );
			tbody.innerHTML = `<tr><td colspan="5">${ escHtml(
				sprintf(
					/* translators: %s: error message. */
					__( 'Error: %s', 'integration-workos' ),
					message
				)
			) }</td></tr>`;
			return;
		}

		const { users, total, total_pages: totalPages } = result.data;

		if ( users.length === 0 ) {
			tbody.innerHTML = `<tr><td colspan="5">${ escHtml(
				__( 'All users are already synced to WorkOS!', 'integration-workos' )
			) }</td></tr>`;
			document.getElementById( 'workos-sync-all-btn' ).disabled = true;
			return;
		}

		const pendingLabel = escHtml( __( 'Pending', 'integration-workos' ) );
		const syncLabel = escHtml( __( 'Sync', 'integration-workos' ) );

		tbody.innerHTML = users
			.map(
				( user ) => `
			<tr data-user-id="${ user.id }">
				<td>${ escHtml( user.display_name ) }</td>
				<td>${ escHtml( user.email ) }</td>
				<td>${ escHtml( user.role ) }</td>
				<td class="workos-user-status">${ pendingLabel }</td>
				<td>
					<button type="button" class="button button-small workos-sync-single" data-user-id="${ user.id }">
						${ syncLabel }
					</button>
				</td>
			</tr>`
			)
			.join( '' );

		// Pagination.
		const paginationEl = document.getElementById( 'workos-users-pagination' );
		if ( totalPages > 1 ) {
			const pageLabel = escHtml(
				sprintf(
					/* translators: 1: current page, 2: total pages, 3: total users. */
					__( 'Page %1$d of %2$d (%3$d users)', 'integration-workos' ),
					page,
					totalPages,
					total
				)
			);
			let html = `<span>${ pageLabel }</span> `;
			if ( page > 1 ) {
				html += `<button type="button" class="button button-small workos-page-btn" data-page="${
					page - 1
				}">${ escHtml(
					__( '« Previous', 'integration-workos' )
				) }</button> `;
			}
			if ( page < totalPages ) {
				html += `<button type="button" class="button button-small workos-page-btn" data-page="${
					page + 1
				}">${ escHtml(
					__( 'Next »', 'integration-workos' )
				) }</button>`;
			}
			paginationEl.innerHTML = html;
		} else {
			paginationEl.innerHTML =
				total > 0
					? `<span>${ escHtml(
							sprintf(
								/* translators: %d: number of unlinked users. */
								_n(
									'%d unlinked user',
									'%d unlinked users',
									total,
									'integration-workos'
								),
								total
							)
					  ) }</span>`
					: '';
		}
	}

	/**
	 * Sync a single user.
	 *
	 * @param {number} userId WP user ID.
	 * @return {Promise<boolean>} Whether sync succeeded.
	 */
	async function syncUser( userId ) {
		const row = document.querySelector( `tr[data-user-id="${ userId }"]` );
		if ( ! row ) return false;

		const statusCell = row.querySelector( '.workos-user-status' );
		const btn = row.querySelector( '.workos-sync-single' );

		statusCell.textContent = __( 'Syncing…', 'integration-workos' );
		statusCell.style.color = '#996800';
		if ( btn ) btn.disabled = true;

		const result = await ajax( 'workos_onboarding_sync_user', { user_id: userId } );

		if ( result.success ) {
			statusCell.textContent = sprintf(
				/* translators: %s: sync action name (e.g. "create", "update"). */
				__( 'Synced (%s)', 'integration-workos' ),
				result.data.action
			);
			statusCell.style.color = '#00a32a';
			if ( btn ) btn.remove();
			return true;
		}

		const message =
			result.data?.message || __( 'Unknown error', 'integration-workos' );
		statusCell.textContent = sprintf(
			/* translators: %s: error message. */
			__( 'Failed: %s', 'integration-workos' ),
			message
		);
		statusCell.style.color = '#d63638';
		if ( btn ) btn.disabled = false;
		return false;
	}

	/**
	 * Sync all unlinked users in batches.
	 */
	async function syncAll() {
		const syncAllBtn = document.getElementById( 'workos-sync-all-btn' );
		const progressWrap = document.getElementById( 'workos-onboarding-progress' );
		const progressBar = document.getElementById( 'workos-progress-bar' );
		const progressText = document.getElementById( 'workos-progress-text' );

		syncAllBtn.disabled = true;
		progressWrap.style.display = 'block';

		// First, collect ALL unlinked user IDs across all pages.
		let allIds = [];
		let page = 1;
		let totalPages = 1;

		do {
			const result = await ajax( 'workos_onboarding_get_users', { page } );
			if ( result.success ) {
				allIds = allIds.concat( result.data.users.map( ( u ) => u.id ) );
				totalPages = result.data.total_pages;
			}
			page++;
		} while ( page <= totalPages );

		const total = allIds.length;
		let synced = 0;
		let failed = 0;
		const batchSize = 5;

		for ( let i = 0; i < allIds.length; i += batchSize ) {
			const batch = allIds.slice( i, i + batchSize );
			const result = await ajax( 'workos_onboarding_sync_batch', { user_ids: batch } );

			if ( result.success ) {
				for ( const r of result.data.results ) {
					if ( r.success ) {
						synced++;
					} else {
						failed++;
					}
				}
			} else {
				failed += batch.length;
			}

			const percent = Math.round( ( ( synced + failed ) / total ) * 100 );
			progressBar.style.width = `${ percent }%`;
			progressText.textContent = sprintf(
				/* translators: 1: processed count, 2: total count, 3: synced count, 4: failed count. */
				__(
					'%1$d of %2$d processed (%3$d synced, %4$d failed)',
					'integration-workos'
				),
				synced + failed,
				total,
				synced,
				failed
			);
		}

		progressText.textContent = sprintf(
			/* translators: 1: synced count, 2: failed count, 3: total users. */
			__(
				'Complete! %1$d synced, %2$d failed out of %3$d users.',
				'integration-workos'
			),
			synced,
			failed,
			total
		);
		syncAllBtn.disabled = false;

		// Reload the table.
		await loadUsers( 1 );
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Input string.
	 * @return {string} Escaped string.
	 */
	function escHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	// Event delegation.
	document.addEventListener( 'click', ( e ) => {
		// Single sync button.
		if ( e.target.classList.contains( 'workos-sync-single' ) ) {
			const userId = parseInt( e.target.dataset.userId, 10 );
			if ( userId ) syncUser( userId );
			return;
		}

		// Pagination buttons.
		if ( e.target.classList.contains( 'workos-page-btn' ) ) {
			const page = parseInt( e.target.dataset.page, 10 );
			if ( page ) loadUsers( page );
			return;
		}

		// Sync all.
		if ( e.target.id === 'workos-sync-all-btn' ) {
			syncAll();
			return;
		}

		// Refresh.
		if ( e.target.id === 'workos-refresh-btn' ) {
			loadUsers( currentPage );
		}
	} );

	// Initial load.
	document.addEventListener( 'DOMContentLoaded', () => loadUsers( 1 ) );
} )();
