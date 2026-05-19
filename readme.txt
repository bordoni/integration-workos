=== Integration with WorkOS ===
Contributors: bordoni
Donate link: https://github.com/sponsors/bordoni
Tags: sso, identity, workos, authentication, directory-sync
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.5
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

* **WordPress-hosted React login** — no redirect to WorkOS for password, magic code, signup, invitation, or MFA. Mounts on wp-login.php, a shortcode (`[workos:login]`), and a dedicated `/workos/login/{profile}` route.
* **Login Profiles** — admin-defined presets (enabled sign-in methods, pinned organization, signup/invite toggles, MFA policy, branding) edited from **WorkOS → Login Profiles**. The organization picker loads live from WorkOS so admins pick an org by name instead of pasting raw IDs.
* **Per-profile custom URL paths** — assign any profile its own URL (e.g. `/members`, `/team/login`) on top of the canonical `/workos/login/{profile}` rewrite. When the default profile owns a custom path, `/wp-login.php` 302s to it (preserving every inbound query arg). Reserved core paths can't be claimed.
* **Already-signed-in handling** — visitors who hit any AuthKit surface while logged in are 302'd to their post-login destination (or, in the shortcode, see an inline "You're already signed in" notice with a Continue link).
* **`forward_query_args` per-profile toggle** — opt-in passing of marketing/analytics query args (`utm_*`, `ref`, etc.) onto the post-login destination. WP and plugin internals are always stripped.
* **Sign-in methods** — email + password, magic code, social OAuth (Google, Microsoft, GitHub, Apple), and passkey. Each profile chooses its own subset.
* **MFA** — TOTP, SMS, and WebAuthn/passkey with in-app enrollment + challenge. Profile-level `mfa.enforce` (`never`/`if_required`/`always`) and factor allowlist are applied at login time.
* **Self-serve sign-up + invitation acceptance + in-app password reset** — all handled by the React shell; no third-party pages.
* **Branding controls** — per-profile heading, subheading, primary color (with WordPress admin-color presets), and logo with a three-mode toggle (`default` falls back to the Site Icon then a bundled WP logo, `custom` uses the chosen image, `none` hides the logo).
* **Embed & URLs in the editor** — every Login Profile shows copyable input fields for its canonical URL, optional custom-path URL, and shortcode so admins can paste them into pages or share them with users.
* **WorkOS Radar** anti-fraud integration optional via `WORKOS_RADAR_SITE_KEY`.
* **Profile routing rules** — send incoming logins to a specific profile based on `redirect_to`, referrer host, or user role.

= Authentication =

* **Single Sign-On (SSO)** — legacy AuthKit redirect mode, per-profile selectable for SAML/OIDC connections.
* **Headless mode** — intercept WordPress's `authenticate` filter for custom login forms.
* **Legacy Login Button** — Gutenberg block and classic widget (AuthKit-redirect flow).
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

Add the "WorkOS Login" Gutenberg block or use the "WorkOS Login" classic widget. Both render a styled login button that redirects to WorkOS AuthKit.

= How do I show the new WordPress-hosted login (Custom AuthKit) on a page? =

Use `[workos:login profile="your-profile-slug"]` or link to `/workos/login/{profile}`. Both mount the same React shell. The reserved `default` Login Profile automatically takes over wp-login.php.

= Can different login pages offer different sign-in methods? =

Yes. Each Login Profile (WorkOS → Login Profiles) picks its own set of enabled methods (password, magic code, any subset of social providers, passkey), pins an organization, and sets its own MFA policy and branding. Reference a profile by slug in the shortcode or URL.

= Can I host a Login Profile at a custom URL like `/members`? =

Yes. Edit any profile and tick **Use a custom URL path**, then fill in the path (e.g. `members` or `team/login`). The plugin registers an extra rewrite rule that mounts the same React shell at `https://yoursite.com/members/`. The canonical `/workos/login/{slug}` URL keeps working too. Reserved core paths (`wp-admin`, `wp-includes`, `wp-content`, `wp-json`, `workos`, `feed`, etc.) are blocked at save time. If you set a custom path on the **default** profile, `/wp-login.php?action=login` 302s to it for everyone (with all `redirect_to` / `interim-login` / language / nonce args preserved).

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

1. Branded Custom AuthKit login shown to site visitors — driven by a Login Profile, with logo, heading, brand color, and the sign-in methods (SSO, magic code, passkey, password) you enable.
2. Login Profiles editor — pick sign-in methods, pin an organization, set the MFA policy, customize the URL path, and brand the card with a logo and color, all without code.
3. WorkOS settings — switch between Production and Staging, manage API credentials and the webhook secret, and choose between Custom AuthKit and AuthKit Redirect login modes.
4. Role mapping and redirects — map WorkOS organization roles to WordPress roles, route users to role-specific URLs after login and logout, and choose what happens to deprovisioned users.

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

= 1.0.5 - 2026-05-18 =

* New: WorkOS → Users admin page. Paginated, searchable React list of WorkOS users for the active environment, with a per-row "Open in WorkOS" deep-link straight to the user's Dashboard page. Lets admins triage WorkOS users (including re-enabling a suppressed email under the Dashboard's Emails tab) without bouncing through the Dashboard's own user picker. Requires `manage_options`. No bulk re-enable yet — WorkOS does not expose a public REST endpoint for the "Re-enable email" action. ([CONS-273](https://linear.app/nexcess/issue/CONS-273/re-enable-workos-emails-for-affected-portal-users))
* New: Admin-triggered WorkOS password reset. A user with `edit_user` capability on a linked target (which includes self-service, since WP grants `edit_user` on one's own ID) can send a WorkOS reset email via three surfaces — a row action on `wp-admin/users.php`, a "Password Reset" panel on the user-edit screen, and the new `[workos:password-reset]` shortcode. The shortcode supports both admin-of-other (`user="…"`) and self-service (no `user` attr) modes. (#21)
* New: `redirect_url` parameter on the admin REST endpoint and on the existing public reset endpoints. The value is validated against `home_url()` host, threaded through the WorkOS-hosted email link, and used by the AuthKit React shell to send the user to the chosen page after a successful reset. Fixes the CONS-287 regression where the post-reset URL was unconfigurable.
* New: WorkOS reset emails now point at the in-site React reset page (`/workos/login/{slug}?token=…&redirect_to=…`) instead of `wp-login.php`. The old `wp-login.php?workos_action=reset-password` URL still resolves cleanly so any reset emails already in users' inboxes keep working.
* New: Password strength + confirmation on the reset-confirm step. Users must enter the new password twice; the value is scored in real time via WordPress's `wp.passwordStrength.meter` (zxcvbn) and the submit button stays disabled until the fields match and the score reaches Strong. Site name and common words are passed as the zxcvbn disallowed list.
* New: Per-profile `auto_login_after_reset` toggle (default on). When enabled, a successful password reset signs the user in (via the shared `LoginCompleter`, so MFA / organization selection / entitlement gates still apply) and lands them on the validated post-reset redirect URL. With the toggle off the user lands on the existing "Password reset — Continue to sign in" card.

= 1.0.4 - 2026-05-14 =

* Fix: `wp-login.php?loggedout=true` is now claimed by the AuthKit takeover instead of rendering native wp-login. The "you have been logged out" screen advertised the wp-login username/password field, which legacy customers misread as a still-working classic sign-in. The URL now 302s to `/login/?loggedout=true` (or the configured custom path) so the React form handles it. `?fallback=1`, `?workos=0`, and `action=logout|lostpassword|rp|...` bypasses are unchanged. (#18)
* Fix: WorkOS password reset emails arrived with HTML-escaped query separators (`?workos_action=…&amp;profile=…&amp;token=…`) when a `home_url`/`login_url` filter on the host ran the URL through `esc_url()`. The plugin now decodes HTML entities in `build_password_reset_url()` before posting to WorkOS so the emailed link is valid. (#17)
* Fix: prevent infinite login redirect loops by sending `Cache-Control: no-store` / `Pragma: no-cache` headers on auth redirects so cached responses do not trap the browser in a redirect cycle when returning to a previously visited URL. (#15)
* Fix: unchecking an auth method or MFA factor on a Login Profile now persists. `update_profile`'s `array_replace_recursive($existing, $params)` merge preserved trailing entries from the existing list when the incoming list was shorter, so removed methods reappeared after save. The REST update now explicitly overwrites `methods` and `mfa.factors` from the payload (using `array_key_exists()` so an empty array is honored). Other scalar/associative fields are unaffected. (#14)

= 1.0.3 - 2026-05-12 =

* Fix: AuthKit login flows now recover transparently from WorkOS `organization_selection_required`. When the Login Profile has an organization pinned (with `Config::get_organization_id()` as a fallback), the plugin re-authenticates via the `organization-selection` grant instead of surfacing "The user must choose an organization to finish their authentication." to the user.
* Fix: pre-existing WordPress users who joined before an organization was pinned are now auto-enrolled into the pinned WorkOS organization. The plugin creates the WorkOS membership and retries the authenticate call when (and only when) a matching local WP user exists and the WorkOS error body carries the authenticated `user_id`. Membership creation and the `entity_already_exists` short-circuit are logged via `workos_log()` (visible under `WP_DEBUG` / `WORKOS_DEBUG`). Strangers and ambiguous lookups still get a clean `pinned_org_mismatch` error — no email-lookup guessing.
* Fix: the legacy OAuth callback at `/workos/callback` now routes through `LoginCompleter`, so it shares the same `organization_selection_required` recovery, MFA gating, and post-login bookkeeping as the AuthKit REST endpoints. The callback no longer short-circuits on the WorkOS error and discards the OAuth code. Legacy AuthKit-redirect callbacks (no profile slug in `state`) keep their original redirect contract — the state-supplied `redirect_to` still wins over the default profile's `post_login_redirect`.

= 1.0.2 - 2026-05-11 =

* New: WordPress password fallback — if WorkOS rejects a password, the auth endpoint can retry against WordPress's own `wp_authenticate()` to cover users whose passwords were never synced to WorkOS, then link the user to WorkOS and (by default) write the password through so future logins authenticate directly. A new "Require Email Confirmation on Fallback" setting switches the post-fallback step to a magic-code email instead of syncing the plaintext password. Gated by the existing `allow_password_fallback` toggle.
* New: wp-config.php constant seeder — defining `WORKOS_*` (or env-scoped `WORKOS_{PRODUCTION|STAGING}_*`) constants now seeds those values into the database on boot, so the admin UI reflects them. Covers string credentials, the new boolean toggles, and `WORKOS_REDIRECT_URLS` arrays. Hash-skipped when nothing has changed — one autoloaded option read per request in steady state.
* Fix: Auth REST endpoints under `/wp-json/workos/v1/auth/*` now read the nonce from `X-WorkOS-Nonce` instead of `X-WP-Nonce` to avoid a header collision with WordPress core and other plugins. The bundled React shell is updated; external clients hitting these endpoints directly must rename the header.

= 1.0.1 - 2026-05-01 =

* New: Organization tab — manual Refresh button next to the organization dropdown re-fetches organizations from WorkOS on demand via the admin REST endpoint (no admin-ajax), bypassing the 5-minute cache. The dropdown is blocked with a spinner during the refresh and the selected organization is preserved when it still exists.
* New: `?refresh=1` query parameter on `GET /wp-json/workos/v1/admin/profiles/organizations` to drop the shared transient before fetching.
* Fix: Organization tab — "Save Settings" was blocked by a hidden, required `org_name` input. The Create Organization modal is now rendered at `admin_footer` so its inner `<form>` is no longer nested inside the settings form.
* Fix: Active environment is now stored in a single place. The admin Settings UI wrote to `workos_active_environment` while the runtime auth flow read from `workos_global['active_environment']`, so picking "Production" still loaded staging credentials and redirected to the staging AuthKit. The runtime now reads/writes the standalone option, with a one-time migration (db_version 2 → 3) that moves any legacy value out of `workos_global`.

= 1.0.0 - 2026-04-23 =

Custom AuthKit (WordPress-hosted login):
* React login shell on wp-login.php, `[workos:login]` shortcode, and `/workos/login/{profile}` route.
* Login Profiles — admin-defined presets for enabled methods, pinned organization, signup/invite/reset flows, MFA policy, and branding, managed at WorkOS → Login Profiles.
* Per-profile custom URL paths (e.g. `/members`, `/team/login`) on top of the canonical `/workos/login/{slug}` rewrite. The default profile can claim a custom path so `/wp-login.php` bounces to it. Reserved core paths are blocked.
* Already-signed-in visitors are 302'd to their post-login destination on every AuthKit surface (or shown an inline "You're already signed in" notice in the shortcode).
* Per-profile `forward_query_args` toggle to pass marketing/analytics args onto the post-login destination (internals always stripped).
* Pinned-organization picker in the Profile editor reads live from WorkOS (with a "Custom ID…" fallback for legacy or unlisted orgs), and the Profiles list renders organization names instead of raw IDs.
* Embed & URLs section in the editor exposes copyable input fields for the canonical URL, the optional custom-path URL, and the `[workos:login profile="…"]` shortcode.
* Sign-in methods: email + password, magic code, social OAuth (Google, Microsoft, GitHub, Apple), passkey.
* Full MFA support — TOTP, SMS, WebAuthn/passkey with in-app enrollment + challenge.
* Self-serve sign-up, invitation acceptance, and in-app password reset.
* Branding — heading, subheading, primary color (defaults to WordPress admin-color palette), and three-mode logo control (`default` falls back to Site Icon → bundled WP logo, `custom` uses the chosen attachment, `none` hides the logo).
* SlotFill extensibility — ten named slots (including `workos.authkit.belowCard`, which renders standard wp-login.php links by default) for plugins to inject React elements into the login UI.
* Profile routing rules (redirect_to glob / referrer host / user role).
* WorkOS Radar anti-fraud integration (set `WORKOS_RADAR_SITE_KEY`).
* Public REST at `/wp-json/workos/v1/auth/*` with profile-scoped nonces, per-IP/per-email rate limits, and signature-verified tokens.
* Full browser internationalization — every user-facing React/TS/JS string ships through `@wordpress/i18n` with the `integration-workos` text domain and `wp_set_script_translations()` wiring.

Base platform:
* SSO login via WorkOS AuthKit (legacy redirect mode, per-profile selectable).
* Headless authentication via WorkOS API.
* Directory Sync (SCIM) for automatic user provisioning and deprovisioning.
* Role mapping between WorkOS organization roles and WordPress roles.
* Organization management with local caching and multisite support.
* Entitlement gate — require organization membership to log in.
* Webhook processing for user, organization, directory, membership, and connection events.
* REST API Bearer token authentication using WorkOS access tokens.
* Legacy login button Gutenberg block and classic widget (AuthKit-redirect flow).
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

= 1.0.5 =
Adds the WorkOS → Users admin page (read-only, paginated, searchable, with deep-links into the WorkOS Dashboard for re-enabling a user's suppressed email), admin-triggered WorkOS password resets (Users list row action, user-edit panel, and `[workos:password-reset]` shortcode), and a `redirect_url` parameter that lands users on the chosen page after they finish resetting. WorkOS reset emails now point at the in-site React reset page instead of `wp-login.php`.

= 1.0.4 =
Fixes the "you have been logged out" screen leaking the native wp-login form, password-reset emails arriving with HTML-encoded `&amp;` in the link, an infinite redirect loop caused by cached redirect responses, and a Login Profile editor bug where unchecking an auth method or MFA factor did not persist on save.

= 1.0.3 =
Fixes "The user must choose an organization to finish their authentication." for AuthKit logins and the `/workos/callback` flow. When a Login Profile has an organization pinned, the plugin completes the authenticate call via the `organization-selection` grant transparently, and auto-enrolls pre-existing WordPress users into the pinned WorkOS organization (matching emails only — strangers still get rejected).

= 1.0.2 =
Adds a WordPress-password fallback for the AuthKit password flow (with an optional email-confirmation step) so accounts that pre-date the WorkOS integration can keep logging in, and adds a `wp-config.php` constant seeder for all major settings. Also renames the auth REST nonce header from `X-WP-Nonce` to `X-WorkOS-Nonce` — external clients calling `/wp-json/workos/v1/auth/*` directly need to update the header name.

= 1.0.1 =
Adds a manual Refresh button next to the Organization dropdown, fixes a regression that prevented saving the Organization tab, and fixes the active-environment selector so picking "Production" actually loads production credentials instead of staging.

= 1.0.0 =
Initial stable release: WordPress-hosted Custom AuthKit (React login with Login Profiles, MFA, and passkeys), plus SSO, Directory Sync, role mapping, organization management, and full admin tooling.
