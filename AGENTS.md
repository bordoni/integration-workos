# Integration with WorkOS - Agent Instructions

## Project Overview

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

- **Version:** 1.0.5
- **Namespace:** `WorkOS\`
- **PHP Requirement:** 7.4+
- **WordPress Requirement:** 5.9+
- **Text Domain:** `integration-workos`
- **Author:** Gustavo Bordoni

## Architecture

### Bootstrap Process

The plugin uses a deferred loading pattern:
1. `integration-workos.php` manually requires `src/WorkOS/Plugin.php` (no autoloader yet)
2. `Plugin::bootstrap(__FILE__)` stores the plugin file path, registers activation/deactivation hooks, and returns `[$plugin, 'init']` as the callback for `plugins_loaded`
3. On `plugins_loaded`, `Plugin::init()` defines constants, loads the Composer autoloader, requires global helpers, loads the text domain, initializes the DI container, and bootstraps the application

```php
// integration-workos.php
require_once __DIR__ . '/src/WorkOS/Plugin.php';
add_action( 'plugins_loaded', WorkOS\Plugin::bootstrap( __FILE__ ) );
```

### Plugin Singleton

`Plugin::instance()` is a getter that returns the current instance (or `null` if not yet initialized). `Plugin::init()` does the actual work:
- Sets plugin paths and defines constants
- Loads Composer autoloader and prefixed autoloader
- Requires global helper functions
- Calls `initializeContainer()` — sets up the DI container with singletons for `Api\Client`, Options classes, and the `App` facade
- Calls `bootstrapApp()` — runs schema upgrades and registers the main `Controller`

### Constants

Defined in `Plugin::init()`:
- `WORKOS_VERSION` — Plugin version string
- `WORKOS_FILE` — Absolute path to main plugin file
- `WORKOS_DIR` — Plugin directory path (trailing slash)
- `WORKOS_URL` — Plugin directory URL (trailing slash)
- `WORKOS_BASENAME` — Plugin basename (`integration-workos/integration-workos.php`)

### Configuration

`Config.php` centralizes settings with `wp-config.php` constant overrides:
- `WORKOS_API_KEY` — API key (overrides DB option)
- `WORKOS_CLIENT_ID` — Client ID (overrides DB option)
- `WORKOS_WEBHOOK_SECRET` — Webhook secret (overrides DB option)
- `WORKOS_ORGANIZATION_ID` — Organization ID (overrides DB option)
- `WORKOS_ENVIRONMENT_ID` — Environment ID (overrides DB option)
- `WORKOS_ENVIRONMENT` — Lock active environment (`production` or `staging`)

Per-environment constants (take priority over generic):
- `WORKOS_PRODUCTION_API_KEY`, `WORKOS_PRODUCTION_CLIENT_ID`, etc.
- `WORKOS_STAGING_API_KEY`, `WORKOS_STAGING_CLIENT_ID`, etc.

## Key Files

| File | Purpose |
|------|---------|
| `integration-workos.php` | Main plugin entry point (minimal — require + hook) |
| `src/WorkOS/Plugin.php` | Bootstrap, constants, container init, app boot |
| `src/WorkOS/App.php` | Static facade for the DI container |
| `src/WorkOS/Config.php` | Centralized config with constant overrides |
| `src/WorkOS/User.php` | Public read-only helper for WorkOS metadata on WP users (`is_sso`, `has_active_session`, `get_workos_id`, `get_access_token`, `snapshot`) |
| `src/WorkOS/Controller.php` | Main controller, registers all feature controllers |
| `src/WorkOS/Contracts/Container.php` | DI container (di52-based) |
| `src/WorkOS/Contracts/Controller.php` | Abstract base controller with `isActive()` |
| `src/WorkOS/Contracts/ServiceProvider.php` | Service provider contract |
| **Admin** | |
| `src/WorkOS/Admin/Controller.php` | Admin controller, registers settings/user list/onboarding/diagnostics |
| `src/WorkOS/Admin/Settings.php` | Admin settings page (tabs: Settings, Organization, Users) |
| `src/WorkOS/Admin/UserList.php` | Admin user list integration (WorkOS column). Exposes `workos_user_list_column_actions` filter so subsystems can add per-row WorkOS actions (slug-keyed; joined with pipe separators) — see PasswordResetAdmin `RowActions`. |
| `src/WorkOS/Admin/UserProfile.php` | User profile page WorkOS metadata |
| `src/WorkOS/Admin/AdminBar.php` | Admin bar environment badge |
| `src/WorkOS/Admin/DiagnosticsPage.php` | System diagnostics page |
| `src/WorkOS/Admin/OnboardingPage.php` | Onboarding wizard UI |
| `src/WorkOS/Admin/OnboardingAjax.php` | Onboarding wizard AJAX handlers |
| **Admin — Users** | |
| `src/WorkOS/Admin/Users/Controller.php` | Wires the WorkOS Users admin submenu + REST endpoint |
| `src/WorkOS/Admin/Users/AdminPage.php` | Admin submenu (WorkOS → Users) that mounts the React user list and enqueues the shared `workos-admin-password-reset` JS/CSS so the per-row trigger button works. |
| `src/WorkOS/Admin/Users/RestApi.php` | `GET /wp-json/workos/v1/admin/users` — proxies `Api\Client::list_users()` with sanitized pagination + filters, a server-computed `dashboard_url` per row, and a `wp_user_id` resolved via `_workos_user_id` meta so the React side can show the "Send password reset" trigger only for linked rows. |
| `src/js/admin-users/index.tsx` | React user list (search + cursor pagination + Open in WorkOS deep-link + per-row Send-password-reset button when `wp_user_id > 0`) |
| **Admin — Login Profiles (Custom AuthKit)** | |
| `src/WorkOS/Admin/LoginProfiles/Controller.php` | Wires Login Profile admin page + CRUD REST |
| `src/WorkOS/Admin/LoginProfiles/AdminPage.php` | Admin submenu that mounts the React editor |
| `src/WorkOS/Admin/LoginProfiles/RestApi.php` | `/wp-json/workos/v1/admin/profiles` CRUD (manage_options) |
| **Auth** | |
| `src/WorkOS/Auth/Controller.php` | Auth controller (login, registration, password reset, redirects) |
| `src/WorkOS/Auth/Login.php` | SSO login flow (redirect + headless modes) |
| `src/WorkOS/Auth/LoginBypass.php` | Login bypass (`?fallback=1`) when WorkOS is unavailable |
| `src/WorkOS/Auth/Registration.php` | User registration redirect |
| `src/WorkOS/Auth/PasswordReset.php` | Legacy guard — blocks WP's native password-reset flow for WorkOS-linked users so they're funneled to the WorkOS-backed reset (`PasswordResetAdmin/*`) instead. |
| **Auth — Password Reset Admin** ([`docs/password-reset.md`](docs/password-reset.md)) | |
| `src/WorkOS/Auth/PasswordResetAdmin/Controller.php` | DI controller — wires the REST endpoint, JS/CSS assets, WP user-row trigger, user-edit panel, and the `[workos:password-reset]` shortcode. |
| `src/WorkOS/Auth/PasswordResetAdmin/RestApi.php` | `POST /wp-json/workos/v1/admin/users/{id}/password-reset`. Gated by `current_user_can('edit_user', $id)` — the same route covers admin-of-other and self-service (WP grants `edit_user($self)` to any logged-in user). Per-IP (10/min) and per-target (5/min) rate limits via `Auth\AuthKit\RateLimiter`. Writes `password_reset.admin_sent` to the activity log. Builds the email URL via `FrontendRoute::url_for_profile()` so reset emails land on the React shell. |
| `src/WorkOS/Auth/PasswordResetAdmin/RedirectValidator.php` | Same-host validator for `redirect_url`. Rejects cross-origin, protocol-relative (`//host`), and non-`http(s)` schemes. Falls back to `Profile::get_post_login_redirect()` then `home_url('/')`. |
| `src/WorkOS/Auth/PasswordResetAdmin/RowActions.php` | "Send password reset" row action under the **WorkOS column** on `wp-admin/users.php` (hooks `workos_user_list_column_actions` filter exposed by `Admin\UserList`). |
| `src/WorkOS/Auth/PasswordResetAdmin/UserProfilePanel.php` | "Password Reset" panel + trigger button on the WP user-edit screen (`edit_user_profile` / `show_user_profile` at priority 20 so it renders after the existing read-only WorkOS panel). |
| `src/WorkOS/Auth/PasswordResetAdmin/Shortcode.php` | `[workos:password-reset]` — toggles between admin-of-other (`user="id-or-email"`) and self-service (no `user`) modes based on its attributes. |
| `src/WorkOS/Auth/PasswordResetAdmin/Assets.php` | Registers `workos-admin-password-reset` JS/CSS handles + localizes `workosPasswordReset` (REST URL, `wp_rest` nonce, masked-email-aware UI strings). Auto-enqueues on `users.php` / `user-edit.php` / `profile.php`. |
| `src/js/admin-password-reset/index.ts` | Delegated `.workos-pwreset-trigger` click handler — POSTs to the admin endpoint and surfaces a transient admin notice. Same handler powers every trigger surface (WP Users row, user-edit panel, shortcode, WorkOS Users admin page row). |
| `src/WorkOS/Auth/Redirect.php` | Role-based login redirects |
| `src/WorkOS/Auth/LogoutRedirect.php` | Role-based logout redirects |
| **Auth — Custom AuthKit (React shell)** | |
| `src/WorkOS/Auth/AuthKit/Controller.php` | Wires Login Profile CPT + takeover + shortcode + route |
| `src/WorkOS/Auth/AuthKit/Profile.php` | Immutable Login Profile value object. Carries the `password_reset_flow` and `auto_login_after_reset` toggles consumed by the password-reset endpoints. |
| `src/WorkOS/Auth/AuthKit/ProfileRepository.php` | CPT-backed CRUD + default seeding |
| `src/WorkOS/Auth/AuthKit/ProfileRouter.php` | Rule-based profile resolution |
| `src/WorkOS/Auth/AuthKit/LoginCompleter.php` | Post-auth finalizer (EntitlementGate + MFA policy) |
| `src/WorkOS/Auth/AuthKit/LoginTakeover.php` | wp-login.php `action=login` takeover, default-profile custom-path bounce, already-signed-in 302 |
| `src/WorkOS/Auth/AuthKit/LoginRedirector.php` | `for_visitor( Profile )` precedence (post_login_redirect → validated redirect_to → admin_url) + `forward_query_args` filter; mirrors `src/js/authkit/redirect.ts` allowlist |
| `src/WorkOS/Auth/AuthKit/FrontendRoute.php` | `/workos/login/{profile}` canonical rewrite + per-profile `custom_path` rewrites (signature-gated flush) + already-signed-in guard. Exposes `FrontendRoute::url_for_profile( Profile, $args )` so password-reset emails (and any future caller) build the same URL the rewrite resolves. |
| `src/WorkOS/Auth/AuthKit/Shortcode.php` | `[workos:login]` shortcode |
| `src/WorkOS/Auth/AuthKit/Renderer.php` | HTML shell + React bundle enqueue. Stapled to `password-strength-meter` (zxcvbn) so `wp.passwordStrength.meter` is available for the reset-confirm form. Emits a pre-hydration skeleton inside the mount `<div>` mirroring the React `FlowSkeleton` shape (1- or 2-input variant chosen from `initial_step` / `reset_token` / `invitation_token`) so the page never paints blank. Fires `workos_authkit_enqueue_assets` action and applies `workos_authkit_branding` / `workos_authkit_profile_data` / `workos_authkit_body_classes` filters — see `docs/extending-the-login-ui.md`. |
| `src/WorkOS/Auth/AuthKit/Nonce.php` | Profile-scoped CSRF nonces |
| `src/WorkOS/Auth/AuthKit/RateLimiter.php` | Per-IP / per-email transient buckets |
| `src/WorkOS/Auth/AuthKit/Radar.php` | WorkOS Radar site-key + request-header extraction |
| `src/WorkOS/Auth/AuthKit/ModeSyncer.php` | Keeps the global `login_mode` option aligned with the default Login Profile's `mode` whenever a profile is saved |
| **Organization** | |
| `src/WorkOS/Organization/Controller.php` | Organization controller |
| `src/WorkOS/Organization/Manager.php` | Organization CRUD and caching |
| `src/WorkOS/Organization/EntitlementGate.php` | Require org membership for login |
| **Activity Log** | |
| `src/WorkOS/ActivityLog/Controller.php` | Activity log controller |
| `src/WorkOS/ActivityLog/EventLogger.php` | Logs WordPress events to local DB table |
| `src/WorkOS/ActivityLog/AdminPage.php` | Activity log viewer in admin |
| **UI (Login Button)** | |
| `src/WorkOS/UI/Controller.php` | UI controller, registers block/widget |
| `src/WorkOS/UI/Block.php` | Gutenberg block registration |
| `src/WorkOS/UI/Widget.php` | Classic widget |
| `src/WorkOS/UI/Renderer.php` | Shared login button renderer |
| `src/WorkOS/UI/Ajax.php` | AJAX handlers for login button |
| **Options** | |
| `src/WorkOS/Options/Options.php` | Abstract options base class |
| `src/WorkOS/Options/Production.php` | Production environment options |
| `src/WorkOS/Options/Staging.php` | Staging environment options |
| `src/WorkOS/Options/Global_Options.php` | Environment-independent options |
| **API** | |
| `src/WorkOS/Api/Client.php` | WorkOS API client wrapper |
| **Sync** | |
| `src/WorkOS/Sync/Controller.php` | Sync controller |
| `src/WorkOS/Sync/UserSync.php` | User sync (inbound/outbound) |
| `src/WorkOS/Sync/RoleMapper.php` | WorkOS role → WP role mapping |
| `src/WorkOS/Sync/DirectorySync.php` | Directory sync (SCIM) |
| `src/WorkOS/Sync/AuditLog.php` | Forward WP events to WorkOS Audit Logs |
| `src/WorkOS/Sync/AuditLogController.php` | Audit log controller |
| **REST** | |
| `src/WorkOS/REST/Controller.php` | REST controller (registers TokenAuth + Auth) |
| `src/WorkOS/REST/TokenAuth.php` | REST API Bearer token authentication (no lazy refresh) |
| **REST — Public Auth (Custom AuthKit)** | |
| `src/WorkOS/REST/Auth/Controller.php` | Wires all public `/wp-json/workos/v1/auth/*` endpoints |
| `src/WorkOS/REST/Auth/BaseEndpoint.php` | Shared profile + nonce + rate-limit + Radar helpers |
| `src/WorkOS/REST/Auth/Password.php` | `password/authenticate`, `password/reset/{start,confirm}`. `reset_confirm` mirrors the new password into the linked WP user (`wp_set_password`) and, when `Profile::is_auto_login_after_reset_enabled()` is on, re-authenticates via WorkOS and runs the result through `LoginCompleter` so MFA / org-selection / entitlement gates still apply. `build_password_reset_url()` builds the email URL via `FrontendRoute::url_for_profile()` and `html_entity_decode()`s the final URL (the legacy regression `home_url` filters that escape `&` to `&amp;`). |
| `src/WorkOS/REST/Auth/MagicCode.php` | `magic/{send,verify}` |
| `src/WorkOS/REST/Auth/Session.php` | `nonce`, `session/{refresh,logout}` |
| `src/WorkOS/REST/Auth/Signup.php` | `signup/{create,verify}` |
| `src/WorkOS/REST/Auth/Invitation.php` | `invitation/{token}`, `invitation/accept` |
| `src/WorkOS/REST/Auth/OAuth.php` | `oauth/authorize-url` |
| `src/WorkOS/REST/Auth/Mfa.php` | `mfa/{challenge,verify,factors,totp/enroll,sms/enroll,factor/delete}` |
| **Webhook** | |
| `src/WorkOS/Webhook/Controller.php` | Webhook controller |
| `src/WorkOS/Webhook/Receiver.php` | Webhook event processing and signature verification |
| **CLI** | |
| `src/WorkOS/CLI/Controller.php` | CLI controller, registers WP-CLI commands |
| `src/WorkOS/CLI/StatusCommand.php` | `wp workos status` command |
| `src/WorkOS/CLI/UserCommand.php` | `wp workos user` commands |
| `src/WorkOS/CLI/OrgCommand.php` | `wp workos org` commands |
| `src/WorkOS/CLI/SyncCommand.php` | `wp workos sync` commands |
| **Database** | |
| `src/WorkOS/Database/Schema.php` | DB schema creation and upgrades |
| **Helpers** | |
| `src/includes/functions-helpers.php` | Global helpers: `workos()`, `workos_log()`, `workos_is_sso_user()`, `workos_has_active_session()`, `workos_get_user_id()`, `workos_get_access_token()` — the latter four proxy to `WorkOS\User` |
| **Browser — Custom AuthKit (TypeScript + TSX)** | |
| `src/js/authkit/index.tsx` | Entry + data-* hydration |
| `src/js/authkit/App.tsx` | Top-level step machine. Renders `FlowSkeleton` while `client.bootstrap()` is in-flight (replaces the prior `return null;` blank window). The `reset_confirm` step receives the `onSignedIn` + `onMfa` callbacks so the auto-login-after-reset path can navigate to the validated redirect or hand off to the MFA challenge step. |
| `src/js/authkit/api.ts` | Fetch client w/ nonce + 401 refresh + Radar header |
| `src/js/authkit/flows.tsx` | 11 flow components (password, magic, signup, reset, mfa, invitation, complete). `ResetConfirm` renders two password fields (new + confirm), scores via `window.wp.passwordStrength.meter` (zxcvbn ≥ 3 required), and disables submit until both match. On success it dispatches based on the response shape: `signed_in` → navigate to `redirect_to`; `mfa_required` → bubble to App's MFA step; otherwise → success card with manual continue. |
| `src/js/authkit/ui.tsx` | 11 primitives + `FlowSkeleton` placeholder card with shimmering rows (heights match the hydrated card one-to-one — heading 24px / label 16px / input 44px / button 40px). Used by App during the boot window and mirrored shape-for-shape in `Renderer::build_skeleton_markup()` for the pre-hydration window. |
| `src/js/authkit/radar.ts` | WorkOS Radar SDK loader (+ `window.WorkOSRadar` augmentation) |
| `src/js/authkit/redirect.ts` | `forwardQueryArgs( destination, originalQuery )` — strips internals (`redirect_to`, `_wpnonce`, `loggedout`, `wp_lang`, `workos_*`, …) and appends safe args; mirrors PHP `LoginRedirector::INTERNAL_QUERY_ARGS` |
| `src/js/authkit/slots.tsx` | SlotFill slot name constants (10 slots, including `workos.authkit.belowCard`) |
| `src/js/authkit/types.ts` | Shared interfaces (Profile, AuthResult, MfaRequired, Step, …) |
| `src/js/authkit/styles.css` | Scoped shell styles (CSS vars) |
| `src/js/admin-profiles/index.tsx` | Admin Login Profile editor (CRUD) |
| `src/js/admin-profiles/styles.css` | Scoped admin styles |
| **Build** | |
| `composer.json` | PHP dependencies |
| `package.json` | JS dependencies (bun; uses `@wordpress/scripts` v30 + TypeScript strict) |
| `bun.lock` | Locked dependency graph (committed) |
| `tsconfig.json` | TypeScript config (strict, `jsx: react-jsx`, `noEmit: true`) |
| `webpack.config.js` | Extends `@wordpress/scripts` default config with `authkit`, `admin-profiles`, `admin-users`, and `admin-password-reset` entries |
| `phpstan.neon.dist` | PHPStan config (level 5, scans `src/` + `integration-workos.php` + `uninstall.php`, `phpVersion: 70400`, Strauss vendor resolved via `vendor/autoload.php` in `scanFiles`) |
| `phpstan/stubs.php` | Symbol stubs for `WORKOS_*` runtime-defined constants so PHPStan can resolve them statically — never executed |

## Build System

### Requirements

- Node.js >= 20 (see `.nvmrc`)
- bun

### Commands

```bash
bun install           # Install dependencies
bun run build         # Production build (wp-scripts; transpiles .ts/.tsx via babel)
bun run start         # Development with watch
bun run lint:ts       # Type-check TypeScript (tsc --noEmit)
bun run lint:php      # Lint PHP via PHPCS
bun run lint:php:fix  # Auto-fix PHP lint issues
composer phpstan      # Static analysis (PHPStan level 5, --memory-limit=1G)
```

`@wordpress/scripts` v30 transpiles `.ts` / `.tsx` natively via its default
babel preset (no ts-loader, no custom webpack rules). `tsc` runs in
`noEmit` mode purely for strict type-checking and editor IntelliSense.

## Releasing / Version Bumps

When bumping the plugin version, update **every** location below in the same commit. Missing any of them causes WordPress, the runtime, and the `.org` listing to disagree about which version is installed.

| Location | What to change |
|----------|----------------|
| `integration-workos.php` | `Version:` header (line ~6) |
| `src/WorkOS/Plugin.php` | `private string $version = 'X.Y.Z';` (the `$version` property used by `WORKOS_VERSION`) |
| `readme.txt` | `Stable tag:` line |
| `AGENTS.md` | `**Version:**` line in Project Overview |

Also add a matching entry to the changelog in `readme.txt` (and `README.md` if its changelog is kept in sync).

## Testing

### Prerequisites

- **slic** installed and on PATH — clone [stellarwp/slic](https://github.com/stellarwp/slic) (located at `~/stellar/slic/`)
- **Docker** running (slic uses Docker containers for the WordPress + database environment)

### Setup & Running Tests

Use the global `/slic` skill for first-time setup, running tests, and all slic CLI commands.

### Test Structure

Tests use Codeception 5 with wp-browser 4.x (`lucatume\WPBrowser`):

```
tests/
├── _bootstrap.php                  # Global test bootstrap
├── _data/                          # Test data fixtures
├── _output/                        # Codeception output (gitignored)
├── _support/
│   ├── _generated/                 # Auto-generated support
│   ├── Helper/
│   │   └── Wpunit.php             # Custom wpunit helper
│   └── WpunitTester.php           # Codeception actor
├── wpunit.suite.dist.yml          # Suite configuration (WPLoader module)
└── wpunit/
    ├── _bootstrap.php                              # Suite-level bootstrap
    ├── ApiClientAuthKitTest.php                    # Api\Client Custom-AuthKit methods (magic, totp, reset, mfa, invitation grant, refresh)
    ├── AuthKitLoginCompleterMfaTest.php            # Profile MFA policy enforcement (enforce=always, factor allowlist)
    ├── AuthKitLoginProfilesRestApiTest.php         # /wp-json/workos/v1/admin/profiles CRUD
    ├── AuthKitNonceTest.php                        # Profile-scoped nonce mint/verify
    ├── AuthKitProfileRepositoryTest.php            # Profile CRUD, default seeding, slug uniqueness
    ├── AuthKitProfileRouterTest.php                # Rule matching (redirect_to / referrer_host / user_role)
    ├── AuthKitProfileTest.php                      # Profile value object + enum validation
    ├── AuthKitRadarTest.php                        # Radar site-key resolution + header extraction
    ├── AuthKitRateLimiterTest.php                  # Fixed-window IP + email bucket limits
    ├── AuthKitRendererTest.php                     # Renderer markup + shortcode + takeover hook wiring
    ├── AuthKitRestMagicSessionTest.php             # magic/{send,verify}, session/{refresh,logout}, /nonce
    ├── AuthKitRestMfaTest.php                      # mfa/{challenge,verify,factors,*/enroll,factor/delete}
    ├── AuthKitRestPasswordTest.php                 # password/{authenticate,reset/start,reset/confirm}
    ├── AuthKitRestSignupInvitationOAuthTest.php    # signup/{create,verify}, invitation/{token,accept}, oauth/authorize-url
    ├── ConfigTest.php                              # Config class tests
    ├── DirectorySyncTest.php                       # Directory sync tests
    ├── EntitlementGateTest.php                     # Entitlement gate tests
    ├── EventLoggerTest.php                         # Activity log event logger tests
    ├── LoginBypassTest.php                         # Login bypass tests
    ├── LoginLogoutTest.php                         # Login logout session revocation tests
    ├── LoginSessionTest.php                        # Login session tests
    ├── LoginTokensTest.php                         # Login tokens tests
    ├── LogoutRedirectTest.php                      # Logout redirect tests
    ├── OnboardingSyncTest.php                      # Onboarding sync tests
    ├── OptionsTest.php                             # Options classes tests
    ├── OrganizationManagerTest.php                 # Organization manager tests
    ├── PasswordResetTest.php                       # Legacy WP-side password reset guard tests
    ├── PasswordResetAdminRedirectValidatorTest.php  # Same-host redirect validator: accept absolute/relative same-host; reject cross-origin/protocol-relative/non-http; profile-default fallback chain
    ├── PasswordResetAdminRestApiTest.php            # `POST /admin/users/{id}/password-reset` — capability matrix, missing user, unlinked user, masked-email response, redirect_url validation, rate limit, activity-log row content
    ├── PasswordResetAdminRowActionsTest.php         # `workos_user_list_column_actions` filter + RowActions injection (capability check, self-service, defensive empty-workos_id)
    ├── PluginTest.php                              # Plugin singleton + constants tests
    ├── RedirectTest.php                            # Login redirect tests
    ├── RendererKsesTest.php                        # Shared renderer KSES allowlist tests
    ├── RoleMapperTest.php                          # Role mapper tests
    ├── SchemaTest.php                              # Database schema tests
    ├── TokenAuthRefreshTest.php                    # TokenAuth regression: rejects unsigned/expired Bearer JWTs
    ├── UserHelperTest.php                          # WorkOS\User + `workos_is_sso_user`/`workos_has_active_session` helpers
    ├── UserSyncDeprovisionTest.php                 # User deprovisioning tests
    ├── UserSyncFindOrCreateTest.php                # User find-or-create tests
    ├── UserSyncLinkTest.php                        # User linking tests
    ├── UserSyncPushTest.php                        # Outbound user sync tests
    ├── UserSyncResyncTest.php                      # User re-sync tests
    ├── WebhookReceiverTest.php                     # Webhook receiver tests
    └── WebhookSignatureTest.php                    # Webhook signature verification tests
```

### Writing Tests

Use the global `/slic` skill for comprehensive guidance on test structure, environment setup tiers, HTTP mocking patterns, assertions, factories, and advanced patterns. Always invoke `/slic` before writing or modifying tests.

- **Base class:** Extend `lucatume\WPBrowser\TestCase\WPTestCase`
- **Namespace:** `WorkOS\Tests\Wpunit`
- **Pattern:** AAA (Arrange, Act, Assert)
- **HTTP mocking:** Use the `pre_http_request` filter to intercept outbound HTTP calls
- **WordPress factories:** Use `static::factory()` to create test posts, users, etc.

### Existing Config Files

| File | Purpose |
|------|---------|
| `codeception.dist.yml` | Main Codeception config (paths, extensions) |
| `tests/wpunit.suite.dist.yml` | WPLoader module config (DB, plugins) |
| `.env.testing.slic` | Environment variables for slic containers |
| `slic.json` | slic config (`phpVersion: 8.2`) |

## Coding Standards

- PHP follows WordPress Coding Standards via PHPCS/WPCS
- Run `composer lint` / `composer lint:fix` for PHP linting

## Static Analysis (PHPStan)

PHPStan analyses the plugin source at **level 5** and is gated in CI as
a required check on PRs to `main`. Config: `phpstan.neon.dist`.

### Stack

- `phpstan/phpstan` ^2 — analyzer, in `composer require-dev`
- `szepeviktor/phpstan-wordpress` — WordPress core stubs + WP-aware
  inference for `apply_filters`, `wp_remote_request`, hook signatures
- `php-stubs/wp-cli-stubs` — `WP_CLI`, `WP_CLI_Command`,
  `WP_CLI\Formatter` for `src/WorkOS/CLI/*`
- `phpstan/extension-installer` — auto-registers neon files from
  installed extensions (no manual `includes:` wiring)

### Scope

- Scanned: `src/`, `integration-workos.php`, `uninstall.php`
- `phpVersion: 70400` so PHP 7.4 syntax mistakes can't slip past on
  PHP 8.x runners
- `treatPhpDocTypesAsCertain: false` — narrowing is required when a
  hook caller may pass something other than the documented type
- Strauss-prefixed `WorkOS\Vendor\…` resolved via `vendor/autoload.php`
  in `scanFiles`. The PHPStan CI job therefore must run
  `composer install` WITH scripts (so Strauss runs and prefixed
  classes exist on disk) — that's the key difference from the PHPCS
  CI job, which uses `--no-scripts` + a stub `vendor/prefixed/autoload.php`.
- WP-CLI stubs (`vendor/php-stubs/wp-cli-stubs/wp-cli-*.php`) and
  `phpstan/stubs.php` (project-local `WORKOS_*` constants) are loaded
  via `scanFiles`. Not bootstrap files — just symbol discovery.

### Policy

- **No baseline.** Findings get fixed in the PR that introduces them.
  `composer phpstan:baseline` exists as a safety hatch for
  exceptional cases but the resulting `phpstan-baseline.neon` should
  not be committed without discussion.
- **Tests are not analyzed yet.** Codeception's wp-browser stubs are
  partial; revisit once `phpstan/phpstan-phpunit` integration is
  worth the noise.
- **Strict-rules extension is intentionally not enabled.** Level 5 is
  the floor we're committing to first; reconsider strict-rules as a
  follow-up when this is stable.
