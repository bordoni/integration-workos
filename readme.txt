=== Integration with WorkOS ===
Contributors: bordoni
Donate link: https://github.com/sponsors/bordoni
Tags: sso, identity, workos, authentication, directory-sync
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

== Description ==

Integration with WorkOS connects your WordPress site with [WorkOS](https://workos.com) for enterprise-grade identity management.

= Requirements =

* WordPress 6.2 or higher
* PHP 7.4 or higher
* A [WorkOS](https://workos.com) account with API credentials

= Custom AuthKit =

* **WordPress-hosted React login** — no redirect to WorkOS for password, magic code, signup, invitation, or MFA. Mounts on wp-login.php, a shortcode (`[workos_login_v2]`), a Gutenberg block, and a dedicated `/workos/login/{profile}` route.
* **Login Profiles** — admin-defined presets (enabled sign-in methods, pinned organization, signup/invite toggles, MFA policy, branding) edited from **WorkOS → Login Profiles**.
* **Sign-in methods** — email + password, magic code, social OAuth (Google, Microsoft, GitHub, Apple), and passkey. Each profile chooses its own subset.
* **MFA** — TOTP, SMS, and WebAuthn/passkey with in-app enrollment + challenge. Profile-level `mfa.enforce` (`never`/`if_required`/`always`) and factor allowlist are applied at login time.
* **Self-serve sign-up + invitation acceptance + in-app password reset** — all handled by the React shell; no third-party pages.
* **WorkOS Radar** anti-fraud integration optional via `WORKOS_RADAR_SITE_KEY`.
* **Profile routing rules** — send incoming logins to a specific profile based on `redirect_to`, referrer host, or user role.

= Authentication =

* **Single Sign-On (SSO)** — legacy AuthKit redirect mode, per-profile selectable for SAML/OIDC connections.
* **Headless mode** — intercept WordPress's `authenticate` filter for custom login forms.
* **Legacy Login Button** — Shortcode (`[workos_login]`), Gutenberg block, and classic widget (AuthKit-redirect flow).
* **Login Bypass** — Access the native WordPress login form via `?fallback=1` when WorkOS is unavailable.
* **Password Reset Integration** — Redirect password reset to WorkOS or fall back to WordPress.
* **Registration Redirect** — Redirect registration to WorkOS AuthKit.
* **REST API Authentication** — Verify WorkOS access tokens for headless/API usage.

= User & Organization Management =

* **Directory Sync** — Automatic user provisioning and deprovisioning via SCIM.
* **Role Mapping** — Map WorkOS organization roles to WordPress roles.
* **Organization Management** — Multi-tenant organization support.
* **Entitlement Gate** — Require organization membership to log in.

= Redirects =

* **Role-Based Login Redirects** — Send users to different URLs after login based on their WordPress role.
* **Role-Based Logout Redirects** — Send users to different URLs after logout based on their WordPress role.

= Admin Tools =

* **Activity Logging** — Local database table with admin viewer for tracking authentication and sync events.
* **Audit Logging** — Forward WordPress events to WorkOS Audit Logs.
* **Diagnostics Page** — System health checks, configuration status, and connectivity tests.
* **Onboarding Wizard** — Guided setup for initial plugin configuration and user sync.
* **Admin Bar Badge** — Shows the active WorkOS environment in the admin bar.
* **WP-CLI Commands** — Full CLI access for scripting, bulk operations, and diagnostics.

= Privacy & Security =

This plugin transmits user data (email, name) to WorkOS for authentication and directory sync. No data is sent until you configure API credentials and users authenticate. API keys are stored in the WordPress database or can be defined as constants in wp-config.php. See the "External services" section for full details on data transmitted.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin and search for "Integration with WorkOS".
2. Click **Install Now**, then **Activate**.
3. Go to **Settings > WorkOS** and enter your API Key and Client ID from the [WorkOS Dashboard](https://dashboard.workos.com).
4. Configure your webhook endpoint in the WorkOS Dashboard using the URL shown on the settings page.
5. (Optional) Run the Onboarding Wizard at **Settings > WorkOS > Onboarding** for guided setup.

== Frequently Asked Questions ==

= Where do I get my API credentials? =

Sign up at [workos.com](https://workos.com) and find your API Key and Client ID in the dashboard.

= Can users still log in with passwords? =

Yes, if "Password Fallback" is enabled in settings. Users can access the standard login form via `?fallback=1`.

= How do I add a login button to my site? =

Use the `[workos_login]` shortcode, add the "WorkOS Login" Gutenberg block, or use the "WorkOS Login" classic widget. All three render a styled login button that redirects to WorkOS AuthKit.

= How do I show the new WordPress-hosted login (Custom AuthKit) on a page? =

Use `[workos_login_v2 profile="your-profile-slug"]`, add the "WorkOS Login Form" block, or link to `/workos/login/{profile}`. All three mount the same React shell. The reserved `default` Login Profile automatically takes over wp-login.php.

= Can different login pages offer different sign-in methods? =

Yes. Each Login Profile (WorkOS → Login Profiles) picks its own set of enabled methods (password, magic code, any subset of social providers, passkey), pins an organization, and sets its own MFA policy and branding. Reference a profile by slug in the shortcode or URL.

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

== Support ==

* [Documentation & Source Code](https://github.com/bordoni/integration-workos)
* [Report a Bug](https://github.com/bordoni/integration-workos/issues)
* [WorkOS Documentation](https://workos.com/docs)

== Screenshots ==

1. Settings page — configure API credentials and login mode.
2. Onboarding wizard — guided setup for first-time configuration.
3. Activity log — view authentication and sync events.
4. Diagnostics page — system health checks.

== External services ==

This plugin connects to the [WorkOS API](https://workos.com) (`https://api.workos.com`) to provide enterprise identity management features for WordPress.

= Authentication (SSO) =

When a user logs in via WorkOS AuthKit or headless mode, the plugin sends an authorization code (and, in headless mode, the user's email and password) to WorkOS to exchange for user identity data and access tokens. This happens each time a user authenticates through WorkOS.

= User Management =

When the site administrator creates, updates, or syncs users between WordPress and WorkOS, the plugin sends user profile data (email, first name, last name) to the WorkOS API.

= Directory Sync =

The plugin receives incoming webhook requests from WorkOS containing directory and user data for automatic provisioning and deprovisioning. The webhook endpoint URL is registered with WorkOS by the site administrator.

= Organization Management =

When managing organizations, the plugin sends and retrieves organization data (name, membership details, role assignments) to and from the WorkOS API.

= Audit Logging =

When audit logging is enabled, the plugin sends WordPress event data (action performed, actor, target, and metadata) to the WorkOS Audit Logs API on each tracked event.

= Token Verification =

When REST API authentication is enabled, the plugin fetches JSON Web Key Sets (JWKS) from WorkOS (`https://api.workos.com/sso/jwks/{client_id}`) to verify access tokens. The JWKS response is cached locally for one hour.

= Service links =

WorkOS is provided by WorkOS, Inc.

* [Terms of Service](https://workos.com/legal/terms)
* [Privacy Policy](https://workos.com/legal/privacy)

== Changelog ==

= 1.0.0 - 2026-04-14 =

Custom AuthKit (WordPress-hosted login):
* React login shell on wp-login.php, `[workos_login_v2]` shortcode, `workos/login-form` block, and `/workos/login/{profile}` route.
* Login Profiles — admin-defined presets for enabled methods, pinned organization, signup/invite/reset flows, MFA policy, and branding, managed at WorkOS → Login Profiles.
* Sign-in methods: email + password, magic code, social OAuth (Google, Microsoft, GitHub, Apple), passkey.
* Full MFA support — TOTP, SMS, WebAuthn/passkey with in-app enrollment + challenge.
* Self-serve sign-up, invitation acceptance, and in-app password reset.
* Profile routing rules (redirect_to glob / referrer host / user role).
* WorkOS Radar anti-fraud integration (set `WORKOS_RADAR_SITE_KEY`).
* Public REST at `/wp-json/workos/v1/auth/*` with profile-scoped nonces, per-IP/per-email rate limits, and signature-verified tokens.

Base platform:
* SSO login via WorkOS AuthKit (legacy redirect mode, per-profile selectable).
* Headless authentication via WorkOS API.
* Directory Sync (SCIM) for automatic user provisioning and deprovisioning.
* Role mapping between WorkOS organization roles and WordPress roles.
* Organization management with local caching and multisite support.
* Entitlement gate — require organization membership to log in.
* Webhook processing for user, organization, directory, membership, and connection events.
* REST API Bearer token authentication using WorkOS access tokens.
* Legacy login button shortcode (`[workos_login]`), Gutenberg block, and classic widget.
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

== Upgrade Notice ==

= 1.0.0 =
Initial stable release: WordPress-hosted Custom AuthKit (React login with Login Profiles, MFA, and passkeys), plus SSO, Directory Sync, role mapping, organization management, and full admin tooling.
