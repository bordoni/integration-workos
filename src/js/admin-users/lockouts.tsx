/**
 * "Sign-in lockouts" lookup on the WorkOS Users screen.
 *
 * Email in; the email-keyed rate-limit buckets out, with a Clear button
 * per bucket and a Clear all. Backed by POST /workos/v1/admin/lockouts/
 * {check,clear} (edit_users). The same lookup is `wp workos ratelimit`.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

interface Tier {
	limit: number;
	window: number;
	count: number;
	resets_in: number;
}

interface Bucket {
	bucket: string;
	key: 'exact' | 'mailbox';
	subject_hash: string;
	locked: boolean;
	retry_after: number;
	tiers: Tier[];
}

interface Props {
	url: string;
	nonce: string;
}

async function post< T >(
	url: string,
	nonce: string,
	body: Record< string, string >
): Promise< { ok: boolean; data: T & { message?: string } } > {
	const res = await fetch( url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: JSON.stringify( body ),
	} );
	const data = await res.json().catch( () => ( {} ) );
	return { ok: res.ok, data };
}

function formatWindow( seconds: number ): string {
	if ( seconds % 3600 === 0 ) {
		const h = seconds / 3600;
		return sprintf( _n( '%d hour', '%d hours', h, 'integration-workos' ), h );
	}
	const m = Math.max( 1, Math.round( seconds / 60 ) );
	return sprintf( _n( '%d minute', '%d minutes', m, 'integration-workos' ), m );
}

function formatTimeLeft( seconds: number ): string {
	if ( seconds < 60 ) {
		return sprintf( _n( '%d second', '%d seconds', seconds, 'integration-workos' ), seconds );
	}
	if ( seconds < 3600 ) {
		const m = Math.ceil( seconds / 60 );
		return sprintf( _n( '%d minute', '%d minutes', m, 'integration-workos' ), m );
	}
	const h = Math.ceil( seconds / 3600 );
	return sprintf( _n( '%d hour', '%d hours', h, 'integration-workos' ), h );
}

export function LockoutLookup( { url, nonce }: Props ): JSX.Element {
	const [ email, setEmail ] = useState< string >( '' );
	const [ looked, setLooked ] = useState< string >( '' );
	const [ buckets, setBuckets ] = useState< Bucket[] | null >( null );
	const [ busy, setBusy ] = useState< string >( '' );
	const [ notice, setNotice ] = useState< { kind: 'success' | 'error'; text: string } | null >( null );

	const lookup = async ( address: string ): Promise< void > => {
		setBusy( 'lookup' );
		try {
			const res = await post< { buckets: Bucket[] } >( `${ url }/check`, nonce, { email: address } );
			if ( ! res.ok ) {
				setBuckets( null );
				setNotice( {
					kind: 'error',
					text: res.data?.message || __( 'Could not look up this address.', 'integration-workos' ),
				} );
				return;
			}
			setLooked( address );
			setBuckets( Array.isArray( res.data.buckets ) ? res.data.buckets : [] );
		} catch ( _err ) {
			setNotice( { kind: 'error', text: __( 'Could not look up this address.', 'integration-workos' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const clear = async ( bucket: string ): Promise< void > => {
		setNotice( null );
		setBusy( bucket || 'all' );
		try {
			const res = await post< { cleared: string[] } >( `${ url }/clear`, nonce, { email: looked, bucket } );
			if ( ! res.ok ) {
				setNotice( {
					kind: 'error',
					text: res.data?.message || __( 'Could not clear the lockout.', 'integration-workos' ),
				} );
				return;
			}
			setNotice( {
				kind: 'success',
				text: bucket
					? sprintf( __( 'Cleared %s.', 'integration-workos' ), bucket )
					: __( 'Cleared every sign-in limit for this address.', 'integration-workos' ),
			} );
		} catch ( _err ) {
			setNotice( { kind: 'error', text: __( 'Could not clear the lockout.', 'integration-workos' ) } );
		} finally {
			setBusy( '' );
		}
		await lookup( looked );
	};

	const used = ( b: Bucket ): boolean => b.tiers.some( ( t ) => t.count > 0 );
	const anyUsed = ( buckets || [] ).some( used );

	return (
		<div className="workos-lockouts">
			<h2>{ __( 'Sign-in lockouts', 'integration-workos' ) }</h2>
			<p className="description">
				{ __(
					'Look up an email address to see whether sign-in rate limits are blocking it, and clear them.',
					'integration-workos'
				) }
			</p>
			<form
				className="workos-users-toolbar"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setNotice( null );
					void lookup( email.trim() );
				} }
			>
				<input
					type="email"
					className="workos-users-search"
					placeholder={ __( 'name@example.com', 'integration-workos' ) }
					value={ email }
					onChange={ ( event ) => setEmail( event.target.value ) }
					aria-label={ __( 'Email address to look up', 'integration-workos' ) }
					required
				/>
				<button type="submit" className="button" disabled={ busy !== '' }>
					{ busy === 'lookup'
						? __( 'Looking up…', 'integration-workos' )
						: __( 'Look up', 'integration-workos' ) }
				</button>
			</form>

			{ notice && (
				<div
					className={ `notice notice-${ notice.kind } workos-users-notice` }
					role={ notice.kind === 'error' ? 'alert' : 'status' }
				>
					<p>{ notice.text }</p>
				</div>
			) }

			{ buckets && (
				<>
					<table className="wp-list-table widefat striped workos-users-table workos-lockouts-table">
						<thead>
							<tr>
								<th scope="col">{ __( 'Bucket', 'integration-workos' ) }</th>
								<th scope="col">{ __( 'Used', 'integration-workos' ) }</th>
								<th scope="col">{ __( 'Status', 'integration-workos' ) }</th>
								<th scope="col">{ __( 'Actions', 'integration-workos' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ buckets.map( ( b ) => (
								<tr key={ b.bucket }>
									<td>
										<code>{ b.bucket }</code>
									</td>
									<td data-colname={ __( 'Used', 'integration-workos' ) }>
										{ b.tiers.map( ( t ) => (
											<div key={ t.window }>
												{ sprintf(
													/* translators: 1: attempts used, 2: attempts allowed, 3: window length, e.g. "5 minutes". */
													__( '%1$d of %2$d per %3$s', 'integration-workos' ),
													t.count,
													t.limit,
													formatWindow( t.window )
												) }
											</div>
										) ) }
									</td>
									<td data-colname={ __( 'Status', 'integration-workos' ) }>
										{ b.locked ? (
											<strong className="workos-lockouts-locked">
												{ sprintf(
													/* translators: %s: time until the lock lifts, e.g. "4 minutes". */
													__( 'Locked for %s', 'integration-workos' ),
													formatTimeLeft( b.retry_after )
												) }
											</strong>
										) : (
											__( 'Not locked', 'integration-workos' )
										) }
									</td>
									<td>
										<button
											type="button"
											className="button button-small"
											onClick={ () => void clear( b.bucket ) }
											disabled={ busy !== '' || ! used( b ) }
										>
											{ busy === b.bucket
												? __( 'Clearing…', 'integration-workos' )
												: __( 'Clear', 'integration-workos' ) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div className="workos-users-pagination">
						<button
							type="button"
							className="button"
							onClick={ () => void clear( '' ) }
							disabled={ busy !== '' || ! anyUsed }
						>
							{ busy === 'all'
								? __( 'Clearing…', 'integration-workos' )
								: __( 'Clear all', 'integration-workos' ) }
						</button>
					</div>
				</>
			) }
		</div>
	);
}
