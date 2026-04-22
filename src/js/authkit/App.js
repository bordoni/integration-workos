/**
 * Top-level AuthKit React app — drives the step machine.
 *
 * Profile + runtime config arrive through `props` (hydrated from data-*
 * attributes on the root div by index.js). Every step renders a flow
 * component from flows.js; transitions are plain setStep() calls.
 */

import { createElement as h, useEffect, useRef, useState } from '@wordpress/element';
import { createClient } from './api';
import * as Radar from './radar';
import {
	Complete,
	InvitationAccept,
	MagicSend,
	MagicVerify,
	MethodPicker,
	MfaChallenge,
	Password,
	ResetConfirm,
	ResetRequest,
	Signup,
	SignupVerify,
} from './flows';

export function App( props ) {
	const { profile, initialStep = 'pick', invitationToken, resetToken } = props;

	const [ step, setStep ] = useState( invitationToken ? 'invitation' : resetToken ? 'reset_confirm' : initialStep );
	const [ redirectTo, setRedirectTo ] = useState( '' );
	const [ mfaState, setMfaState ] = useState( null );
	const [ magicEmail, setMagicEmail ] = useState( '' );
	const [ signupContext, setSignupContext ] = useState( null );
	const [ booted, setBooted ] = useState( false );
	const radarTokenRef = useRef( null );
	const [ client ] = useState( () => createClient( {
		profile: profile.slug,
		baseUrl: profile.restBaseUrl,
		radarToken: () => radarTokenRef.current,
	} ) );

	useEffect( () => {
		let cancelled = false;

		( async () => {
			const bootstrap = await client.bootstrap().catch( () => null );

			// Start Radar once we know the site key. Fire-and-forget; tokens
			// become available whenever the SDK finishes loading.
			const siteKey = bootstrap?.radar_site_key || client.radarSiteKey;
			if ( siteKey ) {
				Radar.load( siteKey ).then( async () => {
					if ( cancelled ) {
						return;
					}
					const token = await Radar.getActionToken( 'authkit_signin' );
					radarTokenRef.current = token;
				} );
			}

			if ( ! cancelled ) {
				setBooted( true );
			}
		} )();

		return () => {
			cancelled = true;
		};
	}, [ client ] );

	if ( ! booted ) {
		return null;
	}

	const handleSuccess = ( data ) => {
		setRedirectTo( data.redirect_to || '/' );
		setStep( 'complete' );
	};

	const handleMfa = ( data ) => {
		setMfaState( {
			pending: data.pending_authentication_token,
			factors: data.factors || [],
		} );
		setStep( 'mfa' );
	};

	switch ( step ) {
		case 'pick':
			return h( MethodPicker, {
				profile,
				onChoose: ( next ) => setStep( next ),
				onError: ( _msg ) => setStep( 'pick' ),
			} );

		case 'password':
			return h( Password, {
				client,
				profile,
				onMfa: handleMfa,
				onSuccess: handleSuccess,
				onBack: ( target ) => setStep( target || 'pick' ),
			} );

		case 'magic_send':
			return h( MagicSend, {
				client,
				onCodeSent: ( email ) => {
					setMagicEmail( email );
					setStep( 'magic_verify' );
				},
				onBack: () => setStep( 'pick' ),
			} );

		case 'magic_verify':
			return h( MagicVerify, {
				client,
				profile,
				email: magicEmail,
				onMfa: handleMfa,
				onSuccess: handleSuccess,
				onBack: () => setStep( 'magic_send' ),
			} );

		case 'mfa':
			return h( MfaChallenge, {
				client,
				profile,
				pendingAuthToken: mfaState?.pending,
				factors: mfaState?.factors,
				onSuccess: handleSuccess,
			} );

		case 'signup':
			return h( Signup, {
				client,
				onVerify: ( ctx ) => {
					setSignupContext( ctx );
					setStep( 'signup_verify' );
				},
				onBack: () => setStep( 'pick' ),
			} );

		case 'signup_verify':
			return h( SignupVerify, {
				client,
				userId: signupContext?.userId,
				email: signupContext?.email,
				onDone: () => setStep( 'pick' ),
			} );

		case 'reset':
			return h( ResetRequest, {
				client,
				onSent: ( _email ) => setStep( 'reset_sent' ),
				onBack: () => setStep( 'password' ),
			} );

		case 'reset_sent':
			return h(
				'div',
				{ className: 'wa-card' },
				h( 'h1', { className: 'wa-heading' }, 'Check your email' ),
				h( 'p', { className: 'wa-subheading' },
					"If an account exists for that email, we've sent a reset link." ),
				h(
					'button',
					{ className: 'wa-linkbtn', onClick: () => setStep( 'pick' ) },
					'Back to sign in'
				)
			);

		case 'reset_confirm':
			return h( ResetConfirm, {
				client,
				token: resetToken,
				onDone: () => setStep( 'pick' ),
			} );

		case 'invitation':
			return h( InvitationAccept, {
				client,
				profile,
				invitationToken,
				onSuccess: handleSuccess,
			} );

		case 'complete':
			return h( Complete, { redirectTo } );

		default:
			return h( MethodPicker, {
				profile,
				onChoose: ( next ) => setStep( next ),
				onError: () => {},
			} );
	}
}
