<?php
/**
 * Login Profile value object.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable Login Profile.
 *
 * A Login Profile describes the auth experience for a given entry point
 * (wp-login.php takeover, shortcode, block, frontend route). It carries:
 * - Enabled first-factor methods (password / magic_code / oauth_* / passkey)
 * - Pinned organization (server-side org scoping — no URL params)
 * - Signup / invitation / password-reset toggles
 * - MFA policy and allowed factors (totp / sms / webauthn)
 * - Branding (logo, primary color, headings)
 * - Mode: "custom" (React UI) or "authkit_redirect" (legacy WorkOS hosted)
 */
class Profile {

	public const MODE_CUSTOM           = 'custom';
	public const MODE_AUTHKIT_REDIRECT = 'authkit_redirect';

	public const METHOD_PASSWORD        = 'password';
	public const METHOD_MAGIC_CODE      = 'magic_code';
	public const METHOD_OAUTH_GOOGLE    = 'oauth_google';
	public const METHOD_OAUTH_MICROSOFT = 'oauth_microsoft';
	public const METHOD_OAUTH_GITHUB    = 'oauth_github';
	public const METHOD_OAUTH_APPLE     = 'oauth_apple';
	public const METHOD_PASSKEY         = 'passkey';

	public const MFA_ENFORCE_NEVER       = 'never';
	public const MFA_ENFORCE_IF_REQUIRED = 'if_required';
	public const MFA_ENFORCE_ALWAYS      = 'always';

	public const FACTOR_TOTP     = 'totp';
	public const FACTOR_SMS      = 'sms';
	public const FACTOR_WEBAUTHN = 'webauthn';

	public const DEFAULT_SLUG = 'default';

	/**
	 * Post ID backing this profile, or 0 for unsaved.
	 *
	 * @var int
	 */
	private int $id = 0;

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Display title.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Enabled first-factor methods.
	 *
	 * @var string[]
	 */
	private array $methods;

	/**
	 * Pinned organization ID, or empty for none.
	 *
	 * @var string
	 */
	private string $organization_id;

	/**
	 * Signup config.
	 *
	 * @var array{enabled: bool, require_invite: bool}
	 */
	private array $signup;

	/**
	 * Whether invitation acceptance is enabled.
	 *
	 * @var bool
	 */
	private bool $invite_flow;

	/**
	 * Whether the password-reset flow is enabled.
	 *
	 * @var bool
	 */
	private bool $password_reset_flow;

	/**
	 * MFA config.
	 *
	 * @var array{enforce: string, factors: string[]}
	 */
	private array $mfa;

	/**
	 * Branding config.
	 *
	 * @var array{logo_attachment_id: int, primary_color: string, heading: string, subheading: string}
	 */
	private array $branding;

	/**
	 * Default redirect after successful login.
	 *
	 * @var string
	 */
	private string $post_login_redirect;

	/**
	 * Mode.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Constructor.
	 *
	 * Use {@see Profile::from_array()} or {@see Profile::defaults()} in most cases.
	 *
	 * @param array $data Pre-validated data.
	 */
	public function __construct( array $data ) {
		$this->id                  = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->slug                = (string) ( $data['slug'] ?? '' );
		$this->title               = (string) ( $data['title'] ?? '' );
		$this->methods             = array_values( array_filter( (array) ( $data['methods'] ?? [] ), 'is_string' ) );
		$this->organization_id     = (string) ( $data['organization_id'] ?? '' );
		$this->signup              = [
			'enabled'        => (bool) ( $data['signup']['enabled'] ?? false ),
			'require_invite' => (bool) ( $data['signup']['require_invite'] ?? false ),
		];
		$this->invite_flow         = (bool) ( $data['invite_flow'] ?? true );
		$this->password_reset_flow = (bool) ( $data['password_reset_flow'] ?? true );
		$this->mfa                 = [
			'enforce' => (string) ( $data['mfa']['enforce'] ?? self::MFA_ENFORCE_IF_REQUIRED ),
			'factors' => array_values( array_filter( (array) ( $data['mfa']['factors'] ?? [] ), 'is_string' ) ),
		];
		$this->branding            = [
			'logo_attachment_id' => (int) ( $data['branding']['logo_attachment_id'] ?? 0 ),
			'primary_color'      => (string) ( $data['branding']['primary_color'] ?? '' ),
			'heading'            => (string) ( $data['branding']['heading'] ?? '' ),
			'subheading'         => (string) ( $data['branding']['subheading'] ?? '' ),
		];
		$this->post_login_redirect = (string) ( $data['post_login_redirect'] ?? '' );
		$this->mode                = (string) ( $data['mode'] ?? self::MODE_CUSTOM );
	}

	/**
	 * Build a Profile from a raw array, applying defaults and validation.
	 *
	 * Unknown enum values are dropped; unknown keys are ignored.
	 *
	 * @param array $data Raw data, typically from $_POST or a stored JSON blob.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$allowed_methods = [
			self::METHOD_PASSWORD,
			self::METHOD_MAGIC_CODE,
			self::METHOD_OAUTH_GOOGLE,
			self::METHOD_OAUTH_MICROSOFT,
			self::METHOD_OAUTH_GITHUB,
			self::METHOD_OAUTH_APPLE,
			self::METHOD_PASSKEY,
		];

		$allowed_factors = [
			self::FACTOR_TOTP,
			self::FACTOR_SMS,
			self::FACTOR_WEBAUTHN,
		];

		$allowed_enforce = [
			self::MFA_ENFORCE_NEVER,
			self::MFA_ENFORCE_IF_REQUIRED,
			self::MFA_ENFORCE_ALWAYS,
		];

		$allowed_modes = [
			self::MODE_CUSTOM,
			self::MODE_AUTHKIT_REDIRECT,
		];

		// Slug is normalized to WP sanitize_title conventions.
		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );

		$title = isset( $data['title'] ) ? (string) $data['title'] : '';
		if ( '' === $title ) {
			$title = '' === $slug ? __( 'Untitled', 'integration-workos' ) : ucwords( str_replace( '-', ' ', $slug ) );
		}

		$methods = array_values(
			array_intersect(
				$allowed_methods,
				array_map( 'strval', (array) ( $data['methods'] ?? [] ) )
			)
		);
		if ( empty( $methods ) ) {
			$methods = [ self::METHOD_PASSWORD, self::METHOD_MAGIC_CODE ];
		}

		$mfa_enforce = (string) ( $data['mfa']['enforce'] ?? self::MFA_ENFORCE_IF_REQUIRED );
		if ( ! in_array( $mfa_enforce, $allowed_enforce, true ) ) {
			$mfa_enforce = self::MFA_ENFORCE_IF_REQUIRED;
		}

		$mfa_factors = array_values(
			array_intersect(
				$allowed_factors,
				array_map( 'strval', (array) ( $data['mfa']['factors'] ?? [] ) )
			)
		);
		if ( empty( $mfa_factors ) ) {
			$mfa_factors = [ self::FACTOR_TOTP ];
		}

		$mode = (string) ( $data['mode'] ?? self::MODE_CUSTOM );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = self::MODE_CUSTOM;
		}

		// WorkOS organization ids follow `org_` + Crockford-style base32.
		// Drop anything that doesn't match the shape rather than letting
		// it reach the API layer and surface as a confusing remote error.
		$organization_id = sanitize_text_field( (string) ( $data['organization_id'] ?? '' ) );
		if ( '' !== $organization_id && ! preg_match( '/^org_[A-Za-z0-9]+$/', $organization_id ) ) {
			$organization_id = '';
		}

		$primary_color = (string) ( $data['branding']['primary_color'] ?? '' );
		if ( '' !== $primary_color && ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $primary_color ) ) {
			$primary_color = '';
		}

		return new self(
			[
				'id'                  => (int) ( $data['id'] ?? 0 ),
				'slug'                => $slug,
				'title'               => $title,
				'methods'             => $methods,
				'organization_id'     => $organization_id,
				'signup'              => [
					'enabled'        => (bool) ( $data['signup']['enabled'] ?? false ),
					'require_invite' => (bool) ( $data['signup']['require_invite'] ?? false ),
				],
				'invite_flow'         => (bool) ( $data['invite_flow'] ?? true ),
				'password_reset_flow' => (bool) ( $data['password_reset_flow'] ?? true ),
				'mfa'                 => [
					'enforce' => $mfa_enforce,
					'factors' => $mfa_factors,
				],
				'branding'            => [
					'logo_attachment_id' => (int) ( $data['branding']['logo_attachment_id'] ?? 0 ),
					'primary_color'      => $primary_color,
					'heading'            => sanitize_text_field( (string) ( $data['branding']['heading'] ?? '' ) ),
					'subheading'         => sanitize_text_field( (string) ( $data['branding']['subheading'] ?? '' ) ),
				],
				'post_login_redirect' => sanitize_text_field( (string) ( $data['post_login_redirect'] ?? '' ) ),
				'mode'                => $mode,
			]
		);
	}

	/**
	 * Build the reserved "default" profile used for wp-login.php takeover.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return self::from_array(
			[
				'slug'                => self::DEFAULT_SLUG,
				'title'               => __( 'Default Login', 'integration-workos' ),
				'methods'             => [
					self::METHOD_PASSWORD,
					self::METHOD_MAGIC_CODE,
					self::METHOD_OAUTH_GOOGLE,
				],
				'organization_id'     => '',
				'signup'              => [
					'enabled'        => false,
					'require_invite' => false,
				],
				'invite_flow'         => true,
				'password_reset_flow' => true,
				'mfa'                 => [
					'enforce' => self::MFA_ENFORCE_IF_REQUIRED,
					'factors' => [ self::FACTOR_TOTP ],
				],
				'branding'            => [
					'logo_attachment_id' => 0,
					'primary_color'      => '',
					'heading'            => __( 'Sign in', 'integration-workos' ),
					'subheading'         => '',
				],
				'post_login_redirect' => '',
				'mode'                => self::MODE_CUSTOM,
			]
		);
	}

	/**
	 * Return a new Profile with the given post ID.
	 *
	 * Used by the repository after persisting to capture the assigned CPT ID.
	 *
	 * @param int $id Post ID.
	 *
	 * @return self
	 */
	public function with_id( int $id ): self {
		$clone     = clone $this;
		$clone->id = $id;
		return $clone;
	}

	/**
	 * Serialize to array (stable shape for REST + storage).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'slug'                => $this->slug,
			'title'               => $this->title,
			'methods'             => $this->methods,
			'organization_id'     => $this->organization_id,
			'signup'              => $this->signup,
			'invite_flow'         => $this->invite_flow,
			'password_reset_flow' => $this->password_reset_flow,
			'mfa'                 => $this->mfa,
			'branding'            => $this->branding,
			'post_login_redirect' => $this->post_login_redirect,
			'mode'                => $this->mode,
		];
	}

	/**
	 * Check whether a given first-factor method is enabled.
	 *
	 * @param string $method One of the METHOD_* constants.
	 *
	 * @return bool
	 */
	public function has_method( string $method ): bool {
		return in_array( $method, $this->methods, true );
	}

	/**
	 * Check whether a given MFA factor is allowed.
	 *
	 * @param string $factor One of the FACTOR_* constants.
	 *
	 * @return bool
	 */
	public function allows_factor( string $factor ): bool {
		return in_array( $factor, $this->mfa['factors'], true );
	}

	/**
	 * Whether this profile uses the custom React UI (vs legacy AuthKit redirect).
	 *
	 * @return bool
	 */
	public function is_custom_mode(): bool {
		return self::MODE_CUSTOM === $this->mode;
	}

	/**
	 * Post ID backing this profile, or 0 for unsaved profiles.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Profile slug (URL-safe identifier).
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Display title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Enabled first-factor methods.
	 *
	 * @return string[]
	 */
	public function get_methods(): array {
		return $this->methods;
	}

	/**
	 * Pinned WorkOS organization ID, or empty string when unpinned.
	 *
	 * @return string
	 */
	public function get_organization_id(): string {
		return $this->organization_id;
	}

	/**
	 * Signup configuration.
	 *
	 * @return array{enabled: bool, require_invite: bool}
	 */
	public function get_signup(): array {
		return $this->signup;
	}

	/**
	 * Whether the invitation acceptance flow is enabled.
	 *
	 * @return bool
	 */
	public function is_invite_flow_enabled(): bool {
		return $this->invite_flow;
	}

	/**
	 * Whether the in-app password-reset flow is enabled.
	 *
	 * @return bool
	 */
	public function is_password_reset_flow_enabled(): bool {
		return $this->password_reset_flow;
	}

	/**
	 * MFA configuration.
	 *
	 * @return array{enforce: string, factors: string[]}
	 */
	public function get_mfa(): array {
		return $this->mfa;
	}

	/**
	 * Branding configuration.
	 *
	 * @return array{logo_attachment_id: int, primary_color: string, heading: string, subheading: string}
	 */
	public function get_branding(): array {
		return $this->branding;
	}

	/**
	 * Default post-login redirect URL, or empty string for the WP default.
	 *
	 * @return string
	 */
	public function get_post_login_redirect(): string {
		return $this->post_login_redirect;
	}

	/**
	 * Profile mode (`custom` or `authkit_redirect`).
	 *
	 * @return string
	 */
	public function get_mode(): string {
		return $this->mode;
	}
}
