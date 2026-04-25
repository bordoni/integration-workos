/**
 * Public SlotFill insertion points for the AuthKit React shell.
 *
 * Centralized so the slot vocabulary stays in one file. Extender plugins
 * register `<Fill name="...">` elements at any of these names; each Slot
 * receives `fillProps` carrying the active step + profile context so a
 * Fill can render conditionally.
 *
 * Documented in docs/extending-the-login-ui.md.
 */

import { Slot } from '@wordpress/components';
import type { AuthMethod, Step } from './types';

export const SLOT_BEFORE_HEADER         = 'workos.authkit.beforeHeader';
export const SLOT_AFTER_HEADER          = 'workos.authkit.afterHeader';
export const SLOT_BEFORE_FORM           = 'workos.authkit.beforeForm';
export const SLOT_AFTER_FORM            = 'workos.authkit.afterForm';
export const SLOT_AFTER_PRIMARY_ACTION  = 'workos.authkit.afterPrimaryAction';
export const SLOT_BEFORE_FOOTER         = 'workos.authkit.beforeFooter';
export const SLOT_AFTER_FOOTER          = 'workos.authkit.afterFooter';
export const SLOT_BELOW_CARD            = 'workos.authkit.belowCard';
export const SLOT_PICKER_BEFORE_METHODS = 'workos.authkit.methodPicker.beforeMethods';
export const SLOT_PICKER_AFTER_METHODS  = 'workos.authkit.methodPicker.afterMethods';

export interface AuthKitSlotFillProps {
	step: Step;
	profileSlug: string;
	methods: AuthMethod[];
	flow?: string;
	[ key: string ]: unknown;
}

interface AuthKitSlotProps {
	name: string;
	fillProps: AuthKitSlotFillProps;
}

/**
 * Renders a named insertion point. Uses `bubblesVirtually` so a Fill
 * registered anywhere in the React tree (including PluginArea) can target
 * the slot — that is the standard WP block-editor pattern. No children
 * accepted: with `bubblesVirtually`, fills render directly into the slot
 * via portal.
 *
 * The `wa-slot` className tags the underlying `<div>` the slot renders —
 * even with no fills registered, `bubblesVirtually` still emits a div to
 * serve as a portal target, and without the `:empty { display: none }`
 * rule in styles.css it would show up as invisible extra flex-gap space
 * inside every card.
 */
export function AuthKitSlot( { name, fillProps }: AuthKitSlotProps ) {
	return (
		<Slot
			name={ name }
			fillProps={ fillProps }
			bubblesVirtually
			className="wa-slot"
		/>
	);
}
