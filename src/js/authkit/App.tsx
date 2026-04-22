/**
 * Top-level AuthKit React app — drives the step machine.
 *
 * Profile + runtime config arrive through `props` (hydrated from data-*
 * attributes on the root div by index.tsx). Every step renders a flow
 * component from flows.tsx; transitions are plain setStep() calls.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { createClient } from './api';
import type { AuthKitClient } from './api';
import * as Radar from './radar';
import type {
	LoginSuccess,
	MfaRequired,
	Profile,
	Step,
} from './types';
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

export interface AppProps {
	profile: Profile;
	initialStep?: Step;
	invitationToken?: string;
	resetToken?: string;
}

interface MfaState {
	pending: string;
	factors: MfaRequired[ 'factors' ];
}

interface SignupContext {
	userId: string;
	email: string;
}

export function App( props: AppProps ) {
	const { profile, initialStep = 'pick', invitationToken, resetToken } = props;

	const initial: Step = invitationToken
		? 'invitation'
		: resetToken
		? 'reset_confirm'
		: initialStep;

	const [ step, setStep ] = useState< Step >( initial );
	const [ redirectTo, setRedirectTo ] = useState( '' );
	const [ mfaState, setMfaState ] = useState< MfaState | null >( null );
	const [ magicEmail, setMagicEmail ] = useState( '' );
	const [ signupContext, setSignupContext ] = useState< SignupContext | null >(
		null
	);
	const [ booted, setBooted ] = useState( false );
	const radarTokenRef = useRef< string | null >( null );
	const [ client ] = useState< AuthKitClient >( () =>
		createClient( {
			profile: profile.slug,
			baseUrl: profile.restBaseUrl,
			radarToken: () => radarTokenRef.current,
		} )
	);

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

	const handleSuccess = ( data: LoginSuccess ): void => {
		setRedirectTo( data.redirect_to || '/' );
		setStep( 'complete' );
	};

	const handleMfa = ( data: MfaRequired ): void => {
		setMfaState( {
			pending: data.pending_authentication_token,
			factors: data.factors || [],
		} );
		setStep( 'mfa' );
	};

	switch ( step ) {
		case 'pick':
			return (
				<MethodPicker
					profile={ profile }
					onChoose={ ( next ) => setStep( next ) }
					onError={ () => setStep( 'pick' ) }
				/>
			);

		case 'password':
			return (
				<Password
					client={ client }
					profile={ profile }
					onMfa={ handleMfa }
					onSuccess={ handleSuccess }
					onBack={ ( target ) => setStep( target || 'pick' ) }
				/>
			);

		case 'magic_send':
			return (
				<MagicSend
					client={ client }
					onCodeSent={ ( email ) => {
						setMagicEmail( email );
						setStep( 'magic_verify' );
					} }
					onBack={ () => setStep( 'pick' ) }
				/>
			);

		case 'magic_verify':
			return (
				<MagicVerify
					client={ client }
					profile={ profile }
					email={ magicEmail }
					onMfa={ handleMfa }
					onSuccess={ handleSuccess }
					onBack={ () => setStep( 'magic_send' ) }
				/>
			);

		case 'mfa':
			return (
				<MfaChallenge
					client={ client }
					profile={ profile }
					pendingAuthToken={ mfaState?.pending ?? '' }
					factors={ mfaState?.factors ?? [] }
					onSuccess={ handleSuccess }
				/>
			);

		case 'signup':
			return (
				<Signup
					client={ client }
					onVerify={ ( ctx ) => {
						setSignupContext( ctx );
						setStep( 'signup_verify' );
					} }
					onBack={ () => setStep( 'pick' ) }
				/>
			);

		case 'signup_verify':
			return (
				<SignupVerify
					client={ client }
					userId={ signupContext?.userId ?? '' }
					email={ signupContext?.email ?? '' }
					onDone={ () => setStep( 'pick' ) }
				/>
			);

		case 'reset':
			return (
				<ResetRequest
					client={ client }
					onSent={ () => setStep( 'reset_sent' ) }
					onBack={ () => setStep( 'password' ) }
				/>
			);

		case 'reset_sent':
			return (
				<div className="wa-card">
					<h1 className="wa-heading">Check your email</h1>
					<p className="wa-subheading">
						If an account exists for that email, we&apos;ve sent a reset link.
					</p>
					<button className="wa-linkbtn" onClick={ () => setStep( 'pick' ) }>
						Back to sign in
					</button>
				</div>
			);

		case 'reset_confirm':
			return (
				<ResetConfirm
					client={ client }
					token={ resetToken ?? '' }
					onDone={ () => setStep( 'pick' ) }
				/>
			);

		case 'invitation':
			return (
				<InvitationAccept
					client={ client }
					profile={ profile }
					invitationToken={ invitationToken ?? '' }
					onSuccess={ handleSuccess }
				/>
			);

		case 'complete':
			return <Complete redirectTo={ redirectTo } />;

		default:
			return (
				<MethodPicker
					profile={ profile }
					onChoose={ ( next ) => setStep( next ) }
					onError={ () => {} }
				/>
			);
	}
}
