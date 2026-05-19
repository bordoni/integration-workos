/**
 * WorkOS Users admin page.
 *
 * Mounts onto #workos-users-admin-root. Read-only list of WorkOS users
 * with cursor pagination + email search; each row links to the WorkOS
 * Dashboard where admins can re-enable a suppressed email (no public API
 * for that action yet).
 */

import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import './styles.css';

interface AdminConfig {
	restUrl: string;
	nonce: string;
	environment: string;
	environmentId: string;
	dashboardBaseUrl: string;
	defaultLimit: number;
	pluginEnabled: boolean;
}

declare global {
	interface Window {
		workosUsersAdmin?: AdminConfig;
	}
}

interface WorkosUser {
	id: string;
	email: string;
	email_verified: boolean;
	first_name: string;
	last_name: string;
	last_sign_in_at: string;
	created_at: string;
	updated_at: string;
	dashboard_url: string;
}

interface ListMetadata {
	before: string | null;
	after: string | null;
}

interface ListResponse {
	data: WorkosUser[];
	list_metadata: ListMetadata;
	error?: string;
}

interface ApiResult< T > {
	ok: boolean;
	status: number;
	data: T;
}

const PAGE_SIZE_OPTIONS = [ 10, 25, 50, 100 ] as const;

function getConfig(): AdminConfig {
	return (
		window.workosUsersAdmin || {
			restUrl: '/wp-json/workos/v1/admin/users',
			nonce: '',
			environment: '',
			environmentId: '',
			dashboardBaseUrl: 'https://dashboard.workos.com',
			defaultLimit: 25,
			pluginEnabled: false,
		}
	);
}

async function apiCall< T >( query: Record< string, string | number > ): Promise< ApiResult< T > > {
	const cfg = getConfig();
	const search = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value === '' || value === null || value === undefined ) {
			continue;
		}
		search.append( key, String( value ) );
	}
	const url = `${ cfg.restUrl }${ search.toString() ? `?${ search.toString() }` : '' }`;
	const res = await fetch( url, {
		method: 'GET',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.nonce,
		},
	} );
	const data = await res.json().catch( () => ( {} ) );
	return { ok: res.ok, status: res.status, data: data as T };
}

function formatRelative( iso: string ): string {
	if ( ! iso ) {
		return '—';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '—';
	}
	const diffMs = Date.now() - date.getTime();
	const absSec = Math.abs( diffMs ) / 1000;
	const future = diffMs < 0;
	const units: Array< { limit: number; div: number; one: string; many: string } > = [
		{ limit: 60, div: 1, one: __( '%d second', 'integration-workos' ), many: __( '%d seconds', 'integration-workos' ) },
		{ limit: 3600, div: 60, one: __( '%d minute', 'integration-workos' ), many: __( '%d minutes', 'integration-workos' ) },
		{ limit: 86400, div: 3600, one: __( '%d hour', 'integration-workos' ), many: __( '%d hours', 'integration-workos' ) },
		{ limit: 2592000, div: 86400, one: __( '%d day', 'integration-workos' ), many: __( '%d days', 'integration-workos' ) },
		{ limit: 31536000, div: 2592000, one: __( '%d month', 'integration-workos' ), many: __( '%d months', 'integration-workos' ) },
	];
	for ( const u of units ) {
		if ( absSec < u.limit ) {
			const n = Math.max( 1, Math.floor( absSec / u.div ) );
			const tpl = n === 1 ? u.one : u.many;
			return future
				? sprintf( __( 'in %s', 'integration-workos' ), sprintf( tpl, n ) )
				: sprintf( __( '%s ago', 'integration-workos' ), sprintf( tpl, n ) );
		}
	}
	const years = Math.max( 1, Math.floor( absSec / 31536000 ) );
	const tpl = years === 1
		? __( '%d year', 'integration-workos' )
		: __( '%d years', 'integration-workos' );
	return future
		? sprintf( __( 'in %s', 'integration-workos' ), sprintf( tpl, years ) )
		: sprintf( __( '%s ago', 'integration-workos' ), sprintf( tpl, years ) );
}

function fullName( user: WorkosUser ): string {
	const parts = [ user.first_name, user.last_name ].filter( Boolean );
	return parts.length ? parts.join( ' ' ) : '—';
}

function App(): JSX.Element {
	const cfg = getConfig();
	const [ users, setUsers ] = useState< WorkosUser[] >( [] );
	const [ metadata, setMetadata ] = useState< ListMetadata >( { before: null, after: null } );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string >( '' );
	const [ searchInput, setSearchInput ] = useState< string >( '' );
	const [ search, setSearch ] = useState< string >( '' );
	const [ limit, setLimit ] = useState< number >( cfg.defaultLimit || 25 );
	const [ cursor, setCursor ] = useState< { after?: string; before?: string } >( {} );
	// Stack of `after` cursors we've passed through, so Prev can rewind without
	// the upstream `before` cursor (WorkOS pagination is one-way per direction).
	const cursorStack = useRef< string[] >( [] );
	const fetchSeq = useRef< number >( 0 );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );
		const seq = ++fetchSeq.current;
		const query: Record< string, string | number > = { limit };
		if ( search ) {
			query.email = search;
		}
		if ( cursor.after ) {
			query.after = cursor.after;
		} else if ( cursor.before ) {
			query.before = cursor.before;
		}
		const res = await apiCall< ListResponse >( query );
		// Drop stale responses if a newer fetch has started.
		if ( seq !== fetchSeq.current ) {
			return;
		}
		if ( ! res.ok ) {
			setLoading( false );
			setError(
				res.data?.error ||
					sprintf(
						__( 'Failed to load users (status %d).', 'integration-workos' ),
						res.status
					)
			);
			setUsers( [] );
			setMetadata( { before: null, after: null } );
			return;
		}
		if ( res.data.error ) {
			setError( res.data.error );
		}
		setUsers( Array.isArray( res.data.data ) ? res.data.data : [] );
		setMetadata( res.data.list_metadata || { before: null, after: null } );
		setLoading( false );
	}, [ limit, search, cursor ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	// Debounce the search input → committed search term (300ms).
	useEffect( () => {
		const handle = window.setTimeout( () => {
			setSearch( ( current ) => {
				if ( current === searchInput ) {
					return current;
				}
				cursorStack.current = [];
				setCursor( {} );
				return searchInput;
			} );
		}, 300 );
		return () => window.clearTimeout( handle );
	}, [ searchInput ] );

	const handleNext = (): void => {
		if ( ! metadata.after ) {
			return;
		}
		cursorStack.current.push( metadata.after );
		setCursor( { after: metadata.after } );
	};

	const handlePrev = (): void => {
		// Drop the cursor that took us to the current page, then use the
		// previous one (or reset if we're at the start).
		cursorStack.current.pop();
		const prev = cursorStack.current[ cursorStack.current.length - 1 ];
		setCursor( prev ? { after: prev } : {} );
	};

	const handleLimitChange = ( value: number ): void => {
		cursorStack.current = [];
		setCursor( {} );
		setLimit( value );
	};

	const hasPrev = cursorStack.current.length > 0;
	const hasNext = Boolean( metadata.after );

	const columns = useMemo(
		() => [
			__( 'Email', 'integration-workos' ),
			__( 'Name', 'integration-workos' ),
			__( 'Last sign-in', 'integration-workos' ),
			__( 'Created', 'integration-workos' ),
			__( 'Actions', 'integration-workos' ),
		],
		[]
	);

	if ( ! cfg.pluginEnabled ) {
		return (
			<div className="workos-users-empty">
				<p>
					{ __(
						'WorkOS is not configured. Save your API credentials on the Settings page to enable the user listing.',
						'integration-workos'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="workos-users-app">
			<div className="workos-users-toolbar">
				<input
					type="search"
					className="workos-users-search"
					placeholder={ __( 'Search by email…', 'integration-workos' ) }
					value={ searchInput }
					onChange={ ( event ) => setSearchInput( event.target.value ) }
					aria-label={ __( 'Search WorkOS users by email', 'integration-workos' ) }
				/>
				<label className="workos-users-pagesize">
					<span>{ __( 'Per page', 'integration-workos' ) }</span>
					<select
						value={ limit }
						onChange={ ( event ) => handleLimitChange( Number( event.target.value ) ) }
					>
						{ PAGE_SIZE_OPTIONS.map( ( size ) => (
							<option key={ size } value={ size }>
								{ size }
							</option>
						) ) }
					</select>
				</label>
				<div className="workos-users-env">
					{ sprintf(
						__( 'Environment: %s', 'integration-workos' ),
						cfg.environment || __( 'unknown', 'integration-workos' )
					) }
				</div>
			</div>

			{ error && (
				<div className="notice notice-error workos-users-notice">
					<p>{ error }</p>
				</div>
			) }

			<div className="workos-users-table-wrap" aria-busy={ loading }>
				<table className="wp-list-table widefat striped workos-users-table">
					<thead>
						<tr>
							{ columns.map( ( label ) => (
								<th key={ label } scope="col">
									{ label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ loading && users.length === 0 && (
							<>
								{ Array.from( { length: 5 } ).map( ( _, i ) => (
									<tr key={ `skeleton-${ i }` } className="workos-users-row-skeleton">
										{ columns.map( ( _label, j ) => (
											<td key={ j }>
												<span className="workos-users-skeleton-bar" />
											</td>
										) ) }
									</tr>
								) ) }
							</>
						) }

						{ ! loading && users.length === 0 && ! error && (
							<tr>
								<td colSpan={ columns.length } className="workos-users-empty-row">
									{ search
										? sprintf(
												__( 'No users match "%s".', 'integration-workos' ),
												search
										  )
										: __(
												'No WorkOS users found in this environment.',
												'integration-workos'
										  ) }
								</td>
							</tr>
						) }

						{ users.map( ( user ) => (
							<tr key={ user.id }>
								<td>
									<strong>{ user.email || '—' }</strong>
									{ user.email_verified && (
										<span
											className="workos-users-verified"
											title={ __( 'Email verified in WorkOS', 'integration-workos' ) }
										>
											{ ' ' }✓
										</span>
									) }
									<div className="workos-users-id">
										<code>{ user.id }</code>
									</div>
								</td>
								<td>{ fullName( user ) }</td>
								<td title={ user.last_sign_in_at || '' }>
									{ formatRelative( user.last_sign_in_at ) }
								</td>
								<td title={ user.created_at || '' }>
									{ formatRelative( user.created_at ) }
								</td>
								<td>
									{ user.dashboard_url ? (
										<a
											className="button button-small"
											href={ user.dashboard_url }
											target="_blank"
											rel="noopener noreferrer"
											title={ __(
												'Open this user in the WorkOS Dashboard — use the Emails section to re-enable a suppressed email.',
												'integration-workos'
											) }
										>
											{ __( 'Open in WorkOS', 'integration-workos' ) }
										</a>
									) : (
										<span className="workos-users-no-link">
											{ __( '—', 'integration-workos' ) }
										</span>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div className="workos-users-pagination">
				<button
					type="button"
					className="button"
					onClick={ handlePrev }
					disabled={ ! hasPrev || loading }
				>
					{ __( '‹ Previous', 'integration-workos' ) }
				</button>
				<button
					type="button"
					className="button"
					onClick={ handleNext }
					disabled={ ! hasNext || loading }
				>
					{ __( 'Next ›', 'integration-workos' ) }
				</button>
				{ loading && (
					<span className="workos-users-loading">
						{ __( 'Loading…', 'integration-workos' ) }
					</span>
				) }
			</div>
		</div>
	);
}

const mount = document.getElementById( 'workos-users-admin-root' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
