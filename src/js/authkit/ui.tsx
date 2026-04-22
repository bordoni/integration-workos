/**
 * Minimal UI primitives for the AuthKit shell.
 *
 * Intentionally lightweight — a future pass can replace these with shadcn
 * without changing the rest of the flow code. Every primitive emits a
 * `wa-` prefixed class so stylesheet authors can reskin.
 */

import type { ReactNode } from 'react';

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
