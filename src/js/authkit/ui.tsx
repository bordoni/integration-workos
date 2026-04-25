/**
 * Minimal UI primitives for the AuthKit shell.
 *
 * Intentionally lightweight — a future pass can replace these with shadcn
 * without changing the rest of the flow code. Every primitive emits a
 * `wa-` prefixed class so stylesheet authors can reskin.
 */

import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import {
	AuthKitSlot,
	SLOT_AFTER_FOOTER,
	SLOT_AFTER_HEADER,
	SLOT_BEFORE_FOOTER,
	SLOT_BEFORE_HEADER,
	SLOT_BELOW_CARD,
} from './slots';
import type { AuthKitSlotFillProps } from './slots';
import type { Profile, Step } from './types';

interface ButtonProps {
	children?: ReactNode;
	variant?: 'primary' | 'secondary';
	disabled?: boolean;
	type?: 'button' | 'submit' | 'reset';
	onClick?: () => void;
}

export function Button( {
	children,
	variant = 'primary',
	disabled,
	type = 'button',
	onClick,
}: ButtonProps ) {
	return (
		<button
			type={ type }
			className={ `wa-btn wa-btn-${ variant }` }
			disabled={ disabled }
			onClick={ onClick }
		>
			{ children }
		</button>
	);
}

interface InputProps {
	id?: string;
	type?: string;
	value?: string;
	onChange?: ( value: string ) => void;
	autoComplete?: string;
	required?: boolean;
	placeholder?: string;
	autoFocus?: boolean;
	disabled?: boolean;
}

export function Input( {
	id,
	type = 'text',
	value,
	onChange,
	autoComplete,
	required,
	placeholder,
	autoFocus,
	disabled,
}: InputProps ) {
	return (
		<input
			id={ id }
			type={ type }
			className="wa-input"
			value={ value ?? '' }
			onChange={ ( event ) => onChange && onChange( event.target.value ) }
			autoComplete={ autoComplete }
			required={ required }
			placeholder={ placeholder }
			autoFocus={ autoFocus }
			disabled={ disabled }
		/>
	);
}

interface LabelProps {
	htmlFor?: string;
	children?: ReactNode;
}

export function Label( { htmlFor, children }: LabelProps ) {
	return (
		<label className="wa-label" htmlFor={ htmlFor }>
			{ children }
		</label>
	);
}

interface FieldProps {
	label?: string;
	htmlFor?: string;
	error?: string;
	children?: ReactNode;
}

export function Field( { label, htmlFor, error, children }: FieldProps ) {
	return (
		<div className="wa-field">
			{ label && <Label htmlFor={ htmlFor }>{ label }</Label> }
			{ children }
			{ error && <p className="wa-field-error">{ error }</p> }
		</div>
	);
}

interface AlertProps {
	variant?: 'info' | 'error' | 'success';
	children?: ReactNode;
}

export function Alert( { variant = 'info', children }: AlertProps ) {
	return (
		<div className={ `wa-alert wa-alert-${ variant }` } role="alert">
			{ children }
		</div>
	);
}

export function Card( { children }: { children?: ReactNode } ) {
	return <div className="wa-card">{ children }</div>;
}

interface LogoProps {
	url?: string;
	alt: string;
}

export function Logo( { url, alt }: LogoProps ) {
	if ( ! url ) {
		return null;
	}
	return <img className="wa-logo" src={ url } alt={ alt } />;
}

interface FlowCardProps {
	logoUrl?: string;
	logoAlt: string;
	fillProps: AuthKitSlotFillProps;
	header?: ReactNode;
	footer?: ReactNode;
	children?: ReactNode;
}

/**
 * Standard wrapper for every AuthKit flow card.
 *
 * Hosts the per-profile logo plus the four card-level Slots
 * (beforeHeader, afterHeader, beforeFooter, afterFooter). Flow components
 * pass their heading/subheading via `header`, the form/buttons as
 * `children`, and any back-links / secondary actions via `footer` so the
 * slot ordering stays consistent across every step.
 */
export function FlowCard( {
	logoUrl,
	logoAlt,
	fillProps,
	header,
	footer,
	children,
}: FlowCardProps ) {
	// Logo sits outside the card so the layout mirrors wp-login.php: a
	// floating mark above a bordered card. Keeping the beforeHeader slot
	// adjacent to the logo lets extenders inject site-level chrome there
	// (badges, banners) without leaking into the card's padded interior.
	return (
		<>
			<AuthKitSlot name={ SLOT_BEFORE_HEADER } fillProps={ fillProps } />
			<Logo url={ logoUrl } alt={ logoAlt } />
			<Card>
				{ header }
				<AuthKitSlot name={ SLOT_AFTER_HEADER } fillProps={ fillProps } />
				{ children }
				<AuthKitSlot name={ SLOT_BEFORE_FOOTER } fillProps={ fillProps } />
				{ footer }
				<AuthKitSlot name={ SLOT_AFTER_FOOTER } fillProps={ fillProps } />
			</Card>
		</>
	);
}

export function Heading( { children }: { children?: ReactNode } ) {
	return <h1 className="wa-heading">{ children }</h1>;
}

export function Subheading( { children }: { children?: ReactNode } ) {
	return <p className="wa-subheading">{ children }</p>;
}

export function Divider( { children }: { children?: ReactNode } ) {
	return (
		<div className="wa-divider" role="separator">
			{ children && <span className="wa-divider-label">{ children }</span> }
		</div>
	);
}

export function Spinner() {
	return <span className="wa-spinner" aria-hidden={ true } />;
}

interface LinkButtonProps {
	onClick?: () => void;
	children?: ReactNode;
}

export function LinkButton( { onClick, children }: LinkButtonProps ) {
	return (
		<button type="button" className="wa-linkbtn" onClick={ onClick }>
			{ children }
		</button>
	);
}

interface BelowCardProps {
	profile: Profile;
	step: Step;
	fillProps: AuthKitSlotFillProps;
	onNavigate: ( step: Step ) => void;
}

/**
 * Site-level links rendered below the card, mirroring wp-login.php.
 *
 * Renders a `SLOT_BELOW_CARD` fill point so extenders can inject or
 * override links (e.g. terms-of-service, support, privacy). Defaults
 * show:
 *
 * - "Lost your password?" when the profile enables password_reset_flow
 *   and the active step is not already one of the reset screens.
 *   Routing to the reset step deliberately keeps the same code path
 *   the in-card "Forgot password?" link used, so behavior is
 *   unchanged.
 * - "← Go to {site name}" when `showChrome` is true (set by the
 *   server for full-page renders — takeover and the frontend route).
 *   Shortcode and block renders skip this link because the user is
 *   already on the site.
 */
export function BelowCard( {
	profile,
	step,
	fillProps,
	onNavigate,
}: BelowCardProps ) {
	const onResetScreen =
		step === 'reset' || step === 'reset_sent' || step === 'reset_confirm';

	const showLostPassword =
		profile.password_reset_flow &&
		profile.methods.includes( 'password' ) &&
		! onResetScreen;

	const showBackToSite =
		profile.showChrome && profile.siteUrl && profile.siteName;

	return (
		<div className="wa-below-card">
			<AuthKitSlot name={ SLOT_BELOW_CARD } fillProps={ fillProps } />
			{ showLostPassword && (
				<p className="wa-below-card-link">
					<LinkButton onClick={ () => onNavigate( 'reset' ) }>
						{ __( 'Lost your password?', 'integration-workos' ) }
					</LinkButton>
				</p>
			) }
			{ showBackToSite && (
				<p className="wa-below-card-link">
					<a className="wa-below-card-back" href={ profile.siteUrl }>
						{ sprintf(
							/* translators: %s: site name. */
							__( '← Go to %s', 'integration-workos' ),
							profile.siteName
						) }
					</a>
				</p>
			) }
		</div>
	);
}
