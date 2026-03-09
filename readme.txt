=== Integration with WorkOS ===
Contributors: liquidweb
Tags: sso, identity, workos, authentication, directory-sync
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

== Description ==

Integration with WorkOS connects your WordPress site with [WorkOS](https://workos.com) for enterprise-grade identity management:

* **Single Sign-On (SSO)** — AuthKit redirect and headless API authentication flows.
* **Directory Sync** — Automatic user provisioning and deprovisioning via SCIM.
* **Role Mapping** — Map WorkOS organization roles to WordPress roles.
* **Role-Based Login Redirects** — Send users to different URLs after login based on their WordPress role.
* **Organization Management** — Multi-tenant organization support.
* **Audit Logging** — Forward WordPress events to WorkOS Audit Logs.
* **REST API Authentication** — Verify WorkOS access tokens for headless/API usage.

== Installation ==

1. Upload the `integration-workos` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WorkOS > Settings** and enter your API Key and Client ID from the [WorkOS Dashboard](https://dashboard.workos.com).
4. Configure your webhook endpoint in the WorkOS Dashboard using the URL shown on the settings page.

== Frequently Asked Questions ==

= Where do I get my API credentials? =

Sign up at [workos.com](https://workos.com) and find your API Key and Client ID in the dashboard.

= Can users still log in with passwords? =

Yes, if "Password Fallback" is enabled in settings. Users can access the standard login form via `?fallback=1`.

== Hooks Reference ==

= Filters =

**`workos_redirect_urls`**

Filter the full role-to-redirect entry map from settings. Each entry is an array with `url` (string) and `first_login_only` (bool). Allows adding, removing, or overriding entries programmatically.

Parameters:

* `array $map` — Associative array of WordPress role slug to redirect entry (`['url' => string, 'first_login_only' => bool]`).

Example:

`add_filter( 'workos_redirect_urls', function ( $map ) {
    $map['subscriber'] = [ 'url' => '/welcome', 'first_login_only' => true ];
    return $map;
} );`

**`workos_redirect_url`**

Filter the final redirect URL for a specific user. Return an empty string to skip the role-based redirect.

Parameters:

* `string $url` — The role-based redirect URL (empty if no match).
* `WP_User $user` — The authenticated user.
* `string $role` — The user's primary WordPress role.
* `bool $is_first_login` — Whether this is the user's first login via WorkOS.

Example:

`add_filter( 'workos_redirect_url', function ( $url, $user, $role, $is_first_login ) {
    if ( $role === 'editor' ) {
        return '/editor-guide';
    }
    return $url;
}, 10, 4 );`

**`workos_redirect_should_apply`**

Whether the role-based redirect should apply at all for this request. Return `false` to skip entirely.

Parameters:

* `bool $should_apply` — Whether to apply the role-based redirect (default `true`).
* `WP_User $user` — The authenticated user.
* `string $requested_redirect` — The current redirect URL.

Example:

`add_filter( 'workos_redirect_should_apply', function ( $should_apply, $user ) {
    // Never redirect administrators.
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return false;
    }
    return $should_apply;
}, 10, 2 );`

**`workos_redirect_is_explicit`**

Whether the current `redirect_to` value is considered "explicit" (user-initiated). By default, any `redirect_to` that is not `admin_url()` or empty is treated as explicit, meaning the role-based redirect is skipped in favor of the user's intended destination.

Parameters:

* `bool $is_explicit` — Whether the redirect is explicit.
* `string $redirect_to` — The redirect URL.
* `WP_User $user` — The authenticated user.

**`workos_redirect_first_login_only`**

Override the per-entry "first login only" setting programmatically.

Parameters:

* `bool $first_login_only` — Whether to redirect only on first login.
* `string $role` — The user's primary WordPress role.
* `WP_User $user` — The authenticated user.

= Actions =

**`workos_user_created`**

Fires when a brand-new WordPress user is created via WorkOS authentication. Does NOT fire for email-match auto-links (existing users matched by email).

Parameters:

* `int $user_id` — WordPress user ID.
* `array $workos_user` — WorkOS user data array.

**`workos_redirect_before`**

Fires just before a role-based redirect is applied.

Parameters:

* `string $url` — The redirect URL.
* `WP_User $user` — The authenticated user.
* `bool $is_first_login` — Whether this is the user's first login via WorkOS.

**`workos_redirect_skipped`**

Fires when a role-based redirect is skipped. Useful for logging or debugging redirect behavior.

Parameters:

* `WP_User $user` — The authenticated user.
* `string $reason` — Reason the redirect was skipped. One of: `filtered_out`, `explicit_redirect`, `not_first_login`, `no_matching_role_url`.

== Changelog ==

= 1.0.0-dev =
* Initial release with SSO, directory sync, role mapping, organizations, audit logging, and REST token auth.
