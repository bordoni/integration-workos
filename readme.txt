=== Integration with WorkOS ===
Contributors: developer-starter, bordoni
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
* **Role-Based Logout Redirects** — Send users to different URLs after logout based on their WordPress role.
* **Organization Management** — Multi-tenant organization support.
* **Entitlement Gate** — Require organization membership to log in.
* **Login Button** — Shortcode (`[workos_login]`), Gutenberg block, and classic widget for embedding a login button anywhere.
* **Login Bypass** — Access the native WordPress login form via `?fallback=1` when WorkOS is unavailable.
* **Activity Logging** — Local database table with admin viewer for tracking authentication and sync events.
* **Audit Logging** — Forward WordPress events to WorkOS Audit Logs.
* **Password Reset Integration** — Redirect password reset to WorkOS or fall back to WordPress.
* **Registration Redirect** — Redirect registration to WorkOS AuthKit.
* **REST API Authentication** — Verify WorkOS access tokens for headless/API usage.
* **Admin Bar Badge** — Shows the active WorkOS environment (production/staging) in the admin bar.
* **Diagnostics Page** — System health checks, configuration status, and connectivity tests.
* **Onboarding Wizard** — Guided setup for initial plugin configuration and user sync.
* **WP-CLI Commands** — Full CLI access for scripting, bulk operations, and diagnostics.

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

= How do I add a login button to my site? =

Use the `[workos_login]` shortcode, add the "WorkOS Login" Gutenberg block, or use the "WorkOS Login" classic widget. All three render a styled login button that redirects to WorkOS AuthKit.

= What happens if WorkOS is down? =

Users can bypass the WorkOS redirect by appending `?fallback=1` to the login URL (e.g., `wp-login.php?fallback=1`). This loads the standard WordPress login form with native password authentication.

= Can I require organization membership to log in? =

Yes. The Entitlement Gate feature restricts login to users who belong to the configured WorkOS organization. Users without a membership are denied access with a customizable error message.

= How do I sync existing WordPress users to WorkOS? =

Use the Onboarding Wizard (Settings > WorkOS > Onboarding) for a guided walkthrough, or use the WP-CLI command `wp workos sync push` to bulk-push users to WorkOS.

= Does this plugin support WordPress multisite? =

Yes. Organizations can be mapped to specific sites in a multisite network, and the plugin stores organization-to-site mappings in a dedicated table.

= How do I run diagnostics? =

Go to **Tools > WorkOS Diagnostics** in the WordPress admin. The diagnostics page checks API connectivity, configuration completeness, database schema status, and other health indicators.

== Screenshots ==

1. Settings page — configure API credentials and login mode.
2. Onboarding wizard — guided setup for first-time configuration.
3. Activity log — view authentication and sync events.
4. Diagnostics page — system health checks.

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

**`workos_logout_redirect_urls`**

Filter the full role-to-logout-redirect URL map from settings. Unlike login redirects, each entry is a simple URL string (no `first_login_only` option).

Parameters:

* `array $map` — Associative array of WordPress role slug to logout redirect URL (string).

Example:

`add_filter( 'workos_logout_redirect_urls', function ( $map ) {
    $map['subscriber'] = '/goodbye';
    $map['editor']     = '/editor-farewell';
    return $map;
} );`

**`workos_logout_redirect_url`**

Filter the final logout redirect URL for a specific user. Return an empty string to skip the role-based logout redirect.

Parameters:

* `string $url` — The role-based logout redirect URL (empty if no match).
* `WP_User $user` — The authenticated user.
* `string $role` — The user's primary WordPress role.

Example:

`add_filter( 'workos_logout_redirect_url', function ( $url, $user, $role ) {
    if ( $role === 'administrator' ) {
        return '/admin-logged-out';
    }
    return $url;
}, 10, 3 );`

**`workos_logout_redirect_should_apply`**

Whether the role-based logout redirect should apply at all for this request. Return `false` to skip entirely.

Parameters:

* `bool $should_apply` — Whether to apply the role-based logout redirect (default `true`).
* `WP_User $user` — The authenticated user.
* `string $redirect_to` — The current logout redirect URL.

Example:

`add_filter( 'workos_logout_redirect_should_apply', function ( $should_apply, $user ) {
    // Never redirect administrators on logout.
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return false;
    }
    return $should_apply;
}, 10, 2 );`

= Actions =

**`workos_user_created`**

Fires when a brand-new WordPress user is created via WorkOS authentication. Does NOT fire for email-match auto-links (existing users matched by email).

Parameters:

* `int $user_id` — WordPress user ID.
* `array $workos_user` — WorkOS user data array.

**`workos_redirect_before`**

Fires just before a role-based login redirect is applied.

Parameters:

* `string $url` — The redirect URL.
* `WP_User $user` — The authenticated user.
* `bool $is_first_login` — Whether this is the user's first login via WorkOS.

**`workos_redirect_skipped`**

Fires when a role-based login redirect is skipped. Useful for logging or debugging redirect behavior.

Parameters:

* `WP_User $user` — The authenticated user.
* `string $reason` — Reason the redirect was skipped. One of: `filtered_out`, `explicit_redirect`, `not_first_login`, `no_matching_role_url`.

**`workos_logout_redirect_before`**

Fires just before a role-based logout redirect is applied.

Parameters:

* `string $url` — The logout redirect URL.
* `WP_User $user` — The authenticated user.

**`workos_logout_redirect_skipped`**

Fires when a role-based logout redirect is skipped.

Parameters:

* `WP_User $user` — The authenticated user.
* `string $reason` — Reason the logout redirect was skipped. One of: `filtered_out`, `no_matching_role_url`.

== Changelog ==

= 1.0.0-dev =
* SSO login via WorkOS AuthKit (redirect and headless modes).
* Directory Sync (SCIM) for automatic user provisioning and deprovisioning.
* Role mapping between WorkOS organization roles and WordPress roles.
* Organization management with local caching and multisite support.
* Entitlement gate — require organization membership to log in.
* Webhook processing for user, organization, directory, membership, and connection events.
* REST API Bearer token authentication using WorkOS access tokens.
* Login button shortcode (`[workos_login]`), Gutenberg block, and classic widget.
* Login bypass via `?fallback=1` for native WordPress login when WorkOS is unavailable.
* Activity logging with local database table and admin viewer.
* Audit logging — forward WordPress events to WorkOS Audit Logs.
* Role-based login redirects with per-role URL configuration.
* Role-based logout redirects with per-role URL configuration.
* Password reset integration with WorkOS.
* Registration redirect to WorkOS AuthKit.
* Admin bar badge showing active environment (production/staging).
* Diagnostics page with health checks and connectivity tests.
* Onboarding wizard for guided first-time setup.
* WP-CLI commands for status, user management, organization management, and bulk sync.
* Dual-environment support (production and staging) with separate credentials.
* Comprehensive test suite with 23 test files covering all major features.
