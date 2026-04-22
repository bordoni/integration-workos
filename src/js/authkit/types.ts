/**
 * Shared types for the AuthKit React shell.
 *
 * Mirrors the authoritative server-side shape defined by
 * `WorkOS\Auth\AuthKit\Profile::to_array()` in PHP.
 */

export type AuthMethod =
	| 'password'
	| 'magic_code'
	| 'oauth_google'
	| 'oauth_microsoft'
	| 'oauth_github'
	| 'oauth_apple'
	| 'passkey';

export type MfaFactor = 'totp' | 'sms' | 'webauthn';
export type MfaEnforce = 'never' | 'if_required' | 'always';
export type ProfileMode = 'custom' | 'authkit_redirect';

export interface ProfileSignup {
	enabled: boolean;
	require_invite: boolean;
}

export interface ProfileMfa {
	enforce: MfaEnforce;
	factors: MfaFactor[];
}

export interface ProfileBranding {
	logo_attachment_id: number;
	logo_url?: string;
	primary_color: string;
	heading: string;
	subheading: string;
}

/**
 * The shape hydrated from `data-profile` + the derived client fields
 * (`restBaseUrl`, `redirectTo`) attached by `parseConfig()` in index.tsx.
 */
export interface Profile {
	id?: number;
	slug: string;
	title: string;
	methods: AuthMethod[];
	organization_id: string;
	signup: ProfileSignup;
	invite_flow: boolean;
	password_reset_flow: boolean;
	mfa: ProfileMfa;
	branding: ProfileBranding;
	post_login_redirect: string;
	mode: ProfileMode;
	// Client-side derived fields — not part of the server payload.
	restBaseUrl: string;
	redirectTo: string;
}

export interface AuthUser {
	id: number;
	email: string;
	display_name: string;
}

export interface LoginSuccess {
	user: AuthUser;
	redirect_to: string;
}

export interface AuthFactor {
	id: string;
	type: string;
	[key: string]: unknown;
}

export interface MfaRequired {
	mfa_required: true;
	pending_authentication_token: string;
	factors: AuthFactor[];
}

export type AuthResult = LoginSuccess | MfaRequired;

export function isMfaRequired( result: AuthResult ): result is MfaRequired {
	return ( result as MfaRequired ).mfa_required === true;
}

export interface NonceResponse {
	nonce: string;
	radar_site_key: string;
}

export interface AuthorizeUrlResponse {
	authorize_url: string;
}

export interface InvitationLookup {
	id: string;
	email: string;
	organization_id: string;
	state: string;
	expires_at: string;
}

export interface MagicSendResponse {
	ok: true;
	message: string;
}

export interface SignupCreateResponse {
	user: { id: string; email: string; email_verified: boolean };
	verification_needed: boolean;
}

/**
 * Shape of a generic JSON error response from /wp-json/workos/v1/auth/*.
 */
export interface ApiError {
	code?: string;
	message?: string;
	data?: { status?: number; [key: string]: unknown };
}

export interface ApiResponse<T> {
	ok: boolean;
	status: number;
	data: T | ApiError;
}

/**
 * Top-level step slugs the AuthKit step machine transitions between.
 */
export type Step =
	| 'pick'
	| 'password'
	| 'magic_send'
	| 'magic_verify'
	| 'mfa'
	| 'signup'
	| 'signup_verify'
	| 'reset'
	| 'reset_sent'
	| 'reset_confirm'
	| 'invitation'
	| 'complete';
