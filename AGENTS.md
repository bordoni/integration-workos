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
| **Auth** | |
| `src/WorkOS/Auth/Controller.php` | Auth controller (login, registration, password reset, redirects) |
| `src/WorkOS/Auth/Login.php` | SSO login flow (redirect + headless modes) |
| `src/WorkOS/Auth/LoginBypass.php` | Login bypass (`?fallback=1`) when WorkOS is unavailable |
| `src/WorkOS/Auth/Registration.php` | User registration redirect |
| `src/WorkOS/Auth/PasswordReset.php` | Password reset flow |
| `src/WorkOS/Auth/Redirect.php` | Role-based login redirects |
| `src/WorkOS/Auth/LogoutRedirect.php` | Role-based logout redirects |
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
| `src/WorkOS/REST/Controller.php` | REST controller |
| `src/WorkOS/REST/TokenAuth.php` | REST API Bearer token authentication |
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
| `src/includes/functions-helpers.php` | Global `workos()` and `workos_log()` helpers |
| **Build** | |
| `composer.json` | PHP dependencies |
| `package.json` | JS dependencies (bun) |

## Build System

### Requirements

- Node.js >= 20 (see `.nvmrc`)
- bun

### Commands

```bash
bun install          # Install dependencies
bun run build        # Production build (wp-scripts)
bun run start        # Development with watch
bun run lint:php     # Lint PHP via PHPCS
bun run lint:php:fix # Auto-fix PHP lint issues
```

## Testing

### Prerequisites

- **slic** installed and on PATH — clone [stellarwp/slic](https://github.com/stellarwp/slic) (located at `~/stellar/slic/`)
- **Docker** running (slic uses Docker containers for the WordPress + database environment)

### First-Time Setup

Run once from the WordPress plugins directory:

```bash
cd ~/workspace/srv/wp-content/plugins
~/stellar/slic/slic here
~/stellar/slic/slic use integration-workos
~/stellar/slic/slic composer install
```

- `slic here` registers the current directory as the plugins root
- `slic use integration-workos` selects the plugin as the active target
- `slic composer install` installs PHP dependencies inside the container

### Running Tests

```bash
~/stellar/slic/slic use integration-workos
~/stellar/slic/slic run wpunit
```

### Running Specific Tests

```bash
# Run a single test file:
~/stellar/slic/slic run tests/wpunit/PluginTest.php

# Run a single test method:
~/stellar/slic/slic run tests/wpunit/PluginTest.php:test_version_constant_is_defined
```

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
    ├── _bootstrap.php             # Suite-level bootstrap
    ├── ConfigTest.php             # Config class tests
    ├── DirectorySyncTest.php      # Directory sync tests
    ├── EntitlementGateTest.php    # Entitlement gate tests
    ├── EventLoggerTest.php        # Activity log event logger tests
    ├── LoginBypassTest.php        # Login bypass tests
    ├── LoginLogoutTest.php        # Login logout session revocation tests
    ├── LoginSessionTest.php       # Login session tests
    ├── LoginTokensTest.php        # Login tokens tests
    ├── LogoutRedirectTest.php     # Logout redirect tests
    ├── OnboardingSyncTest.php     # Onboarding sync tests
    ├── OptionsTest.php            # Options classes tests
    ├── OrganizationManagerTest.php # Organization manager tests
    ├── PasswordResetTest.php      # Password reset tests
    ├── PluginTest.php             # Plugin singleton + constants tests
    ├── RedirectTest.php           # Login redirect tests
    ├── RoleMapperTest.php         # Role mapper tests
    ├── SchemaTest.php             # Database schema tests
    ├── UserSyncDeprovisionTest.php # User deprovisioning tests
    ├── UserSyncFindOrCreateTest.php # User find-or-create tests
    ├── UserSyncLinkTest.php       # User linking tests
    ├── UserSyncPushTest.php       # Outbound user sync tests
    ├── UserSyncResyncTest.php     # User re-sync tests
    ├── WebhookReceiverTest.php    # Webhook receiver tests
    └── WebhookSignatureTest.php   # Webhook signature verification tests
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
