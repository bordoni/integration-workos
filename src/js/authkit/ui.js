/**
 * Minimal UI primitives for the AuthKit shell.
 *
 * Intentionally lightweight — a future pass can replace these with shadcn
 * without changing the rest of the flow code. Every primitive emits a
 * `wa-` prefixed class so stylesheet authors can reskin.
 */

import { createElement as h } from '@wordpress/element';

export function Button( { children, variant = 'primary', disabled, type = 'button', onClick } ) {
	return h(
		'button',
		{
			type,
			className: `wa-btn wa-btn-${ variant }`,
			disabled,
			onClick,
		},
		children
	);
}

export function Input( { id, type = 'text', value, onChange, autoComplete, required, placeholder, autoFocus, disabled } ) {
	return h( 'input', {
		id,
		type,
		className: 'wa-input',
		value: value ?? '',
		onChange: ( event ) => onChange && onChange( event.target.value ),
		autoComplete,
		required,
		placeholder,
		autoFocus,
		disabled,
	} );
}

export function Label( { htmlFor, children } ) {
	return h( 'label', { className: 'wa-label', htmlFor }, children );
}

export function Field( { label, htmlFor, error, children } ) {
	return h(
		'div',
		{ className: 'wa-field' },
		label && h( Label, { htmlFor }, label ),
		children,
		error && h( 'p', { className: 'wa-field-error' }, error )
	);
}

export function Alert( { variant = 'info', children } ) {
	return h( 'div', { className: `wa-alert wa-alert-${ variant }`, role: 'alert' }, children );
}

export function Card( { children } ) {
	return h( 'div', { className: 'wa-card' }, children );
}

export function Heading( { children } ) {
	return h( 'h1', { className: 'wa-heading' }, children );
}

export function Subheading( { children } ) {
	return h( 'p', { className: 'wa-subheading' }, children );
}

export function Divider( { children } ) {
	return h(
		'div',
		{ className: 'wa-divider', role: 'separator' },
		children && h( 'span', { className: 'wa-divider-label' }, children )
	);
}

export function Spinner() {
	return h( 'span', { className: 'wa-spinner', 'aria-hidden': true } );
}

export function LinkButton( { onClick, children } ) {
	return h( 'button', { type: 'button', className: 'wa-linkbtn', onClick }, children );
}
