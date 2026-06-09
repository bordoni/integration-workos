/**
 * Lightweight WP-admin-styled modal helper.
 *
 * Two entry points:
 *
 *   confirmModal({ title, message, confirmLabel, cancelLabel })
 *     → Promise<boolean>           resolves true on confirm, false on cancel
 *
 *   promptModal({ title, message, inputLabel, ... })
 *     → Promise<string | null>     resolves the trimmed input on confirm,
 *                                  null on cancel
 *
 * Implementation uses the native HTML `<dialog>` element which gives us
 * focus trapping, backdrop click handling, and Esc-to-close for free
 * across all evergreen browsers. The visual styling matches WP admin
 * (notice paddings, button classes) so it sits naturally inside
 * wp-admin/users.php and friends. Imported `./modal.css` ships the
 * styling alongside whichever bundle pulls this module in.
 */

import './modal.css';

export interface ConfirmOptions {
	title: string;
	message?: string;
	confirmLabel: string;
	cancelLabel: string;
	/** Visual variant for the confirm button. Defaults to 'primary'. */
	variant?: 'primary' | 'danger';
}

export interface PromptOptions extends ConfirmOptions {
	/** Visible label for the input field. */
	inputLabel: string;
	inputType?: 'email' | 'text';
	placeholder?: string;
	initialValue?: string;
	/**
	 * Optional sync validator. Return an error string to block submit, or
	 * null/empty to allow it. The error renders inline beneath the input.
	 */
	validate?: ( value: string ) => string | null;
}

/**
 * Build the dialog DOM and return a Promise that resolves with the user's
 * choice. Internal — `confirmModal` and `promptModal` are the public API.
 */
function openDialog( opts: ConfirmOptions, prompt: PromptOptions | null ): Promise< boolean | string | null > {
	return new Promise( ( resolve ) => {
		// Remember which element was focused before we open so we can
		// restore focus on close (recommended pattern for accessibility).
		const previouslyFocused = document.activeElement as HTMLElement | null;

		const dialog = document.createElement( 'dialog' );
		dialog.className = 'workos-modal';
		dialog.setAttribute( 'aria-labelledby', 'workos-modal-title' );

		const titleEl = document.createElement( 'h2' );
		titleEl.id = 'workos-modal-title';
		titleEl.className = 'workos-modal__title';
		titleEl.textContent = opts.title;
		dialog.appendChild( titleEl );

		if ( opts.message ) {
			const p = document.createElement( 'p' );
			p.className = 'workos-modal__message';
			p.textContent = opts.message;
			dialog.appendChild( p );
		}

		let input: HTMLInputElement | null = null;
		let errorEl: HTMLParagraphElement | null = null;
		if ( prompt ) {
			const label = document.createElement( 'label' );
			label.className = 'workos-modal__label';
			label.textContent = prompt.inputLabel;
			const inputId = 'workos-modal-input-' + Math.random().toString( 36 ).slice( 2, 9 );
			label.setAttribute( 'for', inputId );
			dialog.appendChild( label );

			input = document.createElement( 'input' );
			input.id = inputId;
			input.type = prompt.inputType || 'text';
			input.className = 'workos-modal__input regular-text';
			input.value = prompt.initialValue || '';
			if ( prompt.placeholder ) {
				input.placeholder = prompt.placeholder;
			}
			input.autocomplete = 'off';
			dialog.appendChild( input );

			errorEl = document.createElement( 'p' );
			errorEl.className = 'workos-modal__error';
			errorEl.setAttribute( 'aria-live', 'polite' );
			dialog.appendChild( errorEl );
		}

		const actions = document.createElement( 'div' );
		actions.className = 'workos-modal__actions';

		const cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'button workos-modal__cancel';
		cancelBtn.textContent = opts.cancelLabel;
		actions.appendChild( cancelBtn );

		const confirmBtn = document.createElement( 'button' );
		confirmBtn.type = 'button';
		const variantClass = opts.variant === 'danger' ? 'button-link-delete' : 'button-primary';
		confirmBtn.className = `button ${ variantClass } workos-modal__confirm`;
		confirmBtn.textContent = opts.confirmLabel;
		actions.appendChild( confirmBtn );

		dialog.appendChild( actions );
		document.body.appendChild( dialog );

		const cleanup = (): void => {
			try {
				if ( dialog.open ) {
					dialog.close();
				}
			} catch ( _ ) {
				// dialog may already be detached on rapid open/close cycles
			}
			dialog.remove();
			if ( previouslyFocused && typeof previouslyFocused.focus === 'function' ) {
				previouslyFocused.focus();
			}
		};

		const handleCancel = (): void => {
			cleanup();
			resolve( prompt ? null : false );
		};

		const handleConfirm = (): void => {
			if ( prompt && input ) {
				const value = input.value.trim();
				const error = prompt.validate ? prompt.validate( value ) : null;
				if ( error ) {
					if ( errorEl ) {
						errorEl.textContent = error;
					}
					input.focus();
					input.select();
					return;
				}
				cleanup();
				resolve( value );
				return;
			}
			cleanup();
			resolve( true );
		};

		cancelBtn.addEventListener( 'click', handleCancel );
		confirmBtn.addEventListener( 'click', handleConfirm );

		// Native <dialog> fires `cancel` on Esc; route it through our
		// cancel handler so the promise resolves consistently.
		dialog.addEventListener( 'cancel', ( event ) => {
			event.preventDefault();
			handleCancel();
		} );

		// Backdrop click — treat clicking the dialog itself (i.e. the
		// pseudo-backdrop area, since clicks land on the <dialog> when
		// they're outside the inner content rect) as a cancel.
		dialog.addEventListener( 'click', ( event ) => {
			if ( event.target === dialog ) {
				handleCancel();
			}
		} );

		if ( input ) {
			// Enter submits, with validation; Esc cancels (handled by <dialog>).
			input.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					handleConfirm();
				}
			} );
		}

		// Open as a true modal so the rest of the page becomes inert.
		try {
			dialog.showModal();
		} catch ( _ ) {
			// `showModal` throws if the element is already open or not
			// connected. Fall back to a non-modal show.
			dialog.show();
		}

		if ( input ) {
			input.focus();
		} else {
			confirmBtn.focus();
		}
	} );
}

/**
 * Show a confirm dialog. Resolves true on confirm, false on cancel.
 */
export function confirmModal( opts: ConfirmOptions ): Promise< boolean > {
	return openDialog( opts, null ) as Promise< boolean >;
}

/**
 * Show a prompt dialog. Resolves the trimmed value on confirm, null on cancel.
 */
export function promptModal( opts: PromptOptions ): Promise< string | null > {
	return openDialog( opts, opts ) as Promise< string | null >;
}
