# Integration with WorkOS - Agent Instructions

## Project Overview

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

- **Version:** 1.0.0-dev
- **Namespace:** `WorkOS\`
- **PHP Requirement:** 7.4+
- **WordPress Requirement:** 5.9+
- **Text Domain:** `integration-workos`
- **Author:** LiquidWeb Software

## Architecture

### Bootstrap Process

The plugin uses a deferred loading pattern:
1. `integration-workos.php` manually requires `Bootstrap.php` (no autoloader yet)
2. `Bootstrap::set_plugin_file()` registers activation/deactivation hooks and returns a callback for `plugins_loaded`
3. On `plugins_loaded`, `Bootstrap::load_plugin()` defines constants, loads Composer autoloader, requires global helpers, and boots `Plugin::instance()`

```php
// integration-workos.php
require_once __DIR__ . '/src/WorkOS/Bootstrap.php';
add_action( 'plugins_loaded', WorkOS\Bootstrap::set_plugin_file( __FILE__ ) );
```

### Plugin Singleton

`Plugin::instance()` is the main orchestrator. Its constructor calls:
- `init_hooks()` — registers core WordPress hooks (textdomain loading)
- `init_components()` — boots all subsystems (Admin, Auth, REST, Sync, etc.)

### Constants

Defined in `Bootstrap::load_plugin()`:
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

## Key Files

| File | Purpose |
|------|---------|
| `integration-workos.php` | Main plugin entry point (minimal — require + hook) |
| `src/WorkOS/Bootstrap.php` | Constants, autoloader, lifecycle hooks |
| `src/WorkOS/Plugin.php` | Singleton orchestrator, boots all subsystems |
| `src/WorkOS/Config.php` | Centralized config with constant overrides |
| `src/WorkOS/Admin/Settings.php` | Admin settings page |
| `src/WorkOS/Admin/UserList.php` | Admin user list integration |
| `src/WorkOS/Auth/Login.php` | SSO login flow |
| `src/WorkOS/Auth/Registration.php` | User registration |
| `src/WorkOS/Auth/PasswordReset.php` | Password reset flow |
| `src/WorkOS/REST/TokenAuth.php` | REST API token authentication |
| `src/WorkOS/Webhook/Receiver.php` | Webhook event processing |
| `src/WorkOS/Sync/UserSync.php` | User sync (inbound/outbound) |
| `src/WorkOS/Sync/RoleMapper.php` | WorkOS role → WP role mapping |
| `src/WorkOS/Sync/DirectorySync.php` | Directory sync |
| `src/WorkOS/Sync/AuditLog.php` | Audit logging |
| `src/WorkOS/Organization/Manager.php` | Organization management |
| `src/WorkOS/Database/Schema.php` | DB schema creation and upgrades |
| `src/includes/functions-helpers.php` | Global `workos()` and `workos_log()` helpers |
| `composer.json` | PHP dependencies |
| `package.json` | Node.js dependencies |

## Build System

### Requirements

- Node.js >= 20 (see `.nvmrc`)
- npm

### Commands

```bash
npm install          # Install dependencies
npm run build        # Production build (wp-scripts)
npm run start        # Development with watch
npm run lint:php     # Lint PHP via PHPCS
npm run lint:php:fix # Auto-fix PHP lint issues
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
- `slic use workos` selects the workos plugin as the active target
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
    ├── PluginTest.php             # Plugin singleton + constants tests
    └── UserSyncPushTest.php       # Outbound user sync tests
```

### Writing Tests

- **Base class:** Extend `lucatume\WPBrowser\TestCase\WPTestCase`
- **Namespace:** `WorkOS\Tests\Wpunit`
- **Pattern:** AAA (Arrange, Act, Assert)
- **HTTP mocking:** Use the `pre_http_request` filter to intercept outbound HTTP calls
- **WordPress factories:** Use `static::factory()` to create test posts, users, etc.

Example:

```php
namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;

class ExampleTest extends WPTestCase {

    public function test_something(): void {
        // Arrange
        $user_id = static::factory()->user->create();

        // Act
        $result = some_function( $user_id );

        // Assert
        $this->assertTrue( $result );
    }
}
```

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
