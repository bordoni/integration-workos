# Integration with WorkOS - Agent Instructions

## Project Overview

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

- **Version:** 1.0.0
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
| `src/WorkOS/Admin/UserList.php` | Admin user list integration (WorkOS columns) |
| `src/WorkOS/Admin/UserProfile.php` | User profile page WorkOS metadata |
| `src/WorkOS/Admin/AdminBar.php` | Admin bar environment badge |
| `src/WorkOS/Admin/DiagnosticsPage.php` | System diagnostics page |
| `src/WorkOS/Admin/OnboardingPage.php` | Onboarding wizard UI |
| `src/WorkOS/Admin/OnboardingAjax.php` | Onboarding wizard AJAX handlers |
| **Admin — Login Profiles (Custom AuthKit)** | |
| `src/WorkOS/Admin/LoginProfiles/Controller.php` | Wires Login Profile admin page + CRUD REST |
| `src/WorkOS/Admin/LoginProfiles/AdminPage.php` | Admin submenu that mounts the React editor |
| `src/WorkOS/Admin/LoginProfiles/RestApi.php` | `/wp-json/workos/v1/admin/profiles` CRUD (manage_options) |
| **Auth** | |
| `src/WorkOS/Auth/Controller.php` | Auth controller (login, registration, password reset, redirects) |
| `src/WorkOS/Auth/Login.php` | SSO login flow (redirect + headless modes) |
| `src/WorkOS/Auth/LoginBypass.php` | Login bypass (`?fallback=1`) when WorkOS is unavailable |
| `src/WorkOS/Auth/Registration.php` | User registration redirect |
| `src/WorkOS/Auth/PasswordReset.php` | Password reset flow |
| `src/WorkOS/Auth/Redirect.php` | Role-based login redirects |
| `src/WorkOS/Auth/LogoutRedirect.php` | Role-based logout redirects |
| **Auth — Custom AuthKit (React shell)** | |
| `src/WorkOS/Auth/AuthKit/Controller.php` | Wires Login Profile CPT + takeover + shortcode + route |
| `src/WorkOS/Auth/AuthKit/Profile.php` | Immutable Login Profile value object |
| `src/WorkOS/Auth/AuthKit/ProfileRepository.php` | CPT-backed CRUD + default seeding |
| `src/WorkOS/Auth/AuthKit/ProfileRouter.php` | Rule-based profile resolution |
| `src/WorkOS/Auth/AuthKit/LoginCompleter.php` | Post-auth finalizer (EntitlementGate + MFA policy) |
| `src/WorkOS/Auth/AuthKit/LoginTakeover.php` | wp-login.php `action=login` takeover |
| `src/WorkOS/Auth/AuthKit/FrontendRoute.php` | `/workos/login/{profile}` rewrite + static `register_rewrite()` |
| `src/WorkOS/Auth/AuthKit/Shortcode.php` | `[workos_login_v2]` shortcode |
| `src/WorkOS/Auth/AuthKit/Renderer.php` | HTML shell + React bundle enqueue |
| `src/WorkOS/Auth/AuthKit/Nonce.php` | Profile-scoped CSRF nonces |
| `src/WorkOS/Auth/AuthKit/RateLimiter.php` | Per-IP / per-email transient buckets |
| `src/WorkOS/Auth/AuthKit/Radar.php` | WorkOS Radar site-key + request-header extraction |
| **Organization** | |
| `src/WorkOS/Organization/Controller.php` | Organization controller |
| `src/WorkOS/Organization/Manager.php` | Organization CRUD and caching |
| `src/WorkOS/Organization/EntitlementGate.php` | Require org membership for login |
| **Activity Log** | |
| `src/WorkOS/ActivityLog/Controller.php` | Activity log controller |
| `src/WorkOS/ActivityLog/EventLogger.php` | Logs WordPress events to local DB table |
| `src/WorkOS/ActivityLog/AdminPage.php` | Activity log viewer in admin |
| **UI (Login Button)** | |
| `src/WorkOS/UI/Controller.php` | UI controller, registers shortcode/block/widget |
| `src/WorkOS/UI/Shortcode.php` | `[workos_login]` shortcode |
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
| `src/WorkOS/REST/Auth/Password.php` | `password/authenticate`, `password/reset/{start,confirm}` |
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
| `src/js/authkit/App.tsx` | Top-level step machine |
| `src/js/authkit/api.ts` | Fetch client w/ nonce + 401 refresh + Radar header |
| `src/js/authkit/flows.tsx` | 11 flow components (password, magic, signup, reset, mfa, invitation, complete) |
| `src/js/authkit/ui.tsx` | 11 primitives (Button, Input, Card, …) |
| `src/js/authkit/radar.ts` | WorkOS Radar SDK loader (+ `window.WorkOSRadar` augmentation) |
| `src/js/authkit/types.ts` | Shared interfaces (Profile, AuthResult, MfaRequired, Step, …) |
| `src/js/authkit/styles.css` | Scoped shell styles (CSS vars) |
| `src/js/admin-profiles/index.tsx` | Admin Login Profile editor (CRUD) |
| `src/js/admin-profiles/styles.css` | Scoped admin styles |
| **Build** | |
| `composer.json` | PHP dependencies |
| `package.json` | JS dependencies (bun; uses `@wordpress/scripts` v30 + TypeScript strict) |
| `bun.lock` | Locked dependency graph (committed) |
| `tsconfig.json` | TypeScript config (strict, `jsx: react-jsx`, `noEmit: true`) |
| `webpack.config.js` | Extends `@wordpress/scripts` default config with authkit + admin-profiles entries |

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
```

`@wordpress/scripts` v30 transpiles `.ts` / `.tsx` natively via its default
babel preset (no ts-loader, no custom webpack rules). `tsc` runs in
`noEmit` mode purely for strict type-checking and editor IntelliSense.

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
    ├── PasswordResetTest.php                       # Password reset tests
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
