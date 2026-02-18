# WorkOS Identity

Enterprise identity management for WordPress powered by [WorkOS](https://workos.com). SSO, directory sync, MFA, and user management.

**Requires PHP:** 7.4+
**Requires WordPress:** 5.9+
**License:** GPL-2.0-or-later

## Features

- **Single Sign-On (SSO)** via WorkOS AuthKit — redirect or headless login modes
- **Directory Sync (SCIM)** — automatic user provisioning and deprovisioning from your identity provider
- **Role Mapping** — map WorkOS organization roles to WordPress roles
- **Organization Management** — local caching of WorkOS organizations with multisite support
- **Webhook Processing** — real-time sync of user, organization, and directory events
- **REST API Authentication** — Bearer token auth for headless/decoupled WordPress
- **Audit Logging** — forward WordPress events (login, logout, post changes, user changes) to WorkOS Audit Logs
- **WP-CLI Commands** — full CLI access for scripting, bulk operations, and diagnostics

## Installation

1. Upload the plugin to `wp-content/plugins/workos/`.
2. Run `composer install` in the plugin directory.
3. Activate the plugin in WordPress admin.
4. Navigate to **Settings > WorkOS** to configure.

## Configuration

### Admin UI

Go to **Settings > WorkOS** in the WordPress admin. The settings page has three tabs:

| Tab | Contents |
|---|---|
| **Settings** | API Key, Client ID, Environment ID, webhook secret, login mode, password fallback, audit logging toggle |
| **Organization** | Select or create a WorkOS organization |
| **Users** | Deprovision action, content reassignment, role mapping table |

The plugin supports two environments — **Production** and **Staging** — with separate credentials for each. An admin bar badge shows which environment is active.

### wp-config.php Constants

All credentials can be set via constants in `wp-config.php`, which take precedence over database values:

```php
// Generic (used for any environment)
define( 'WORKOS_API_KEY', 'sk_live_...' );
define( 'WORKOS_CLIENT_ID', 'client_...' );
define( 'WORKOS_WEBHOOK_SECRET', 'whsec_...' );
define( 'WORKOS_ORGANIZATION_ID', 'org_...' );
define( 'WORKOS_ENVIRONMENT_ID', 'environment_...' );
define( 'WORKOS_ENVIRONMENT', 'production' ); // Lock active environment

// Per-environment (takes priority over generic)
define( 'WORKOS_PRODUCTION_API_KEY', 'sk_live_...' );
define( 'WORKOS_STAGING_API_KEY', 'sk_test_...' );
```

## Authentication

### Redirect Mode (Default)

Users visiting `wp-login.php` are redirected to WorkOS AuthKit. After authentication, WorkOS redirects back to `/workos/callback` where the plugin exchanges the authorization code for user data and tokens.

### Headless Mode

The plugin intercepts WordPress's `authenticate` filter and validates credentials directly against the WorkOS API using email and password. This mode is useful for custom login forms.

### Password Fallback

When enabled, WordPress native password authentication remains available alongside WorkOS. Password reset and registration forms fall back to WordPress defaults.

## Webhooks

Configure your WorkOS dashboard to send webhooks to:

```
https://yoursite.com/wp-json/workos/v1/webhook
```

The plugin processes these event types:

| Category | Events |
|---|---|
| **Users** | `user.created`, `user.updated`, `user.deleted` |
| **Directory Sync** | `dsync.user.created`, `dsync.user.updated`, `dsync.user.deleted`, `dsync.group.user_added`, `dsync.group.user_removed` |
| **Organizations** | `organization.created`, `organization.updated` |
| **Memberships** | `organization_membership.created`, `organization_membership.updated`, `organization_membership.deleted` |
| **Connections** | `connection.activated`, `connection.deactivated`, `connection.deleted` |
| **Auth** | `authentication.email_verification_succeeded` |

All events are verified against the webhook signing secret before processing.

## REST API Authentication

The plugin adds Bearer token authentication to the WordPress REST API. Send the WorkOS access token in the `Authorization` header:

```
Authorization: Bearer <workos_access_token>
```

The token is verified using WorkOS JWKS and mapped to a WordPress user via their linked WorkOS ID.

## Database Tables

The plugin creates three custom tables on activation:

| Table | Purpose |
|---|---|
| `{prefix}_workos_organizations` | Cached WorkOS organization data (name, slug, domains) |
| `{prefix}_workos_org_memberships` | User-to-organization memberships with roles |
| `{prefix}_workos_org_sites` | Organization-to-site mapping (multisite) |

User linking is stored in standard WordPress usermeta (`_workos_user_id`, `_workos_org_id`, `_workos_last_synced_at`, `_workos_deactivated`).

## WP-CLI Commands

All commands are registered under the `wp workos` namespace.

### Status

```bash
# Show plugin configuration and health
wp workos status

# Output as JSON
wp workos status --format=json
```

Displays: environment, API key (masked), client ID, organization ID, environment ID, enabled status, login mode, database version, and plugin version. The `source` column shows whether each value comes from a `constant` or the `database`.

---

### User Management

```bash
# Get a user with WorkOS metadata
wp workos user get <id> [--by=<id|email|workos_id>] [--format=<format>]

# List users with WorkOS link status
wp workos user list [--linked] [--unlinked] [--role=<role>] [--format=<format>] [--fields=<fields>]

# Get user IDs for piping to other commands
wp workos user list --unlinked --format=ids

# Link a WP user to a WorkOS user (validates via API)
wp workos user link <wp_user_id> <workos_user_id>

# Remove WorkOS link from a user
wp workos user unlink <wp_user_id> [--yes]

# Sync a single user: push to WorkOS (default) or pull from WorkOS
wp workos user sync <wp_user_id> [--direction=<push|pull>]

# Import a single WorkOS user into WordPress
wp workos user import <workos_user_id> [--porcelain] [--yes]
```

**Examples:**

```bash
# Find a user by email and show their WorkOS metadata
wp workos user get admin@example.com --by=email

# Look up a user by their WorkOS ID
wp workos user get user_01HXYZ --by=workos_id --format=json

# List all users that haven't been synced to WorkOS yet
wp workos user list --unlinked --role=subscriber

# Pull latest profile data from WorkOS for a linked user
wp workos user sync 42 --direction=pull

# Import a WorkOS user and get just the WP user ID
wp workos user import user_01HXYZ --porcelain --yes
```

---

### Organization Management

```bash
# List local organizations
wp workos org list [--source=<local|remote>] [--format=<format>]

# Get a single organization
wp workos org get <id> [--by=<id|workos_id|remote>] [--format=<format>]

# Sync an organization from WorkOS API to local database
wp workos org sync <workos_org_id>

# List organization members
wp workos org members <id> [--by=<id|workos_id>] [--format=<format>]

# Add a user to a local organization
wp workos org add-member <org_id> <user_id> [--role=<role>]

# Remove a user from a local organization
wp workos org remove-member <org_id> <user_id> [--yes]
```

**Examples:**

```bash
# List organizations from the WorkOS API
wp workos org list --source=remote --format=json

# Fetch an org directly from WorkOS without needing a local record
wp workos org get org_01HXYZ --by=remote

# Sync an organization and view its members
wp workos org sync org_01HXYZ
wp workos org members org_01HXYZ --by=workos_id

# Add a user to an org as admin
wp workos org add-member 1 42 --role=admin
```

---

### Bulk Sync Operations

All bulk commands support `--dry-run` to preview changes, `--yes` to skip confirmation, `--limit` to cap the number of items processed, and display progress bars during execution.

```bash
# Push all unlinked WP users to WorkOS
wp workos sync push [--role=<role>] [--limit=<n>] [--dry-run] [--yes]

# Re-sync all linked users from WorkOS
wp workos sync pull [--limit=<n>] [--dry-run] [--yes]

# Import WorkOS users into WordPress
wp workos sync import [--organization_id=<id>] [--limit=<n>] [--dry-run] [--yes]

# Import all organizations from WorkOS
wp workos sync orgs [--limit=<n>] [--dry-run] [--yes]
```

**Examples:**

```bash
# Preview what a full user push would do
wp workos sync push --dry-run

# Push only subscribers, 50 at a time
wp workos sync push --role=subscriber --limit=50 --yes

# Re-sync all linked users from WorkOS
wp workos sync pull --yes

# Import the first 10 WorkOS users from a specific organization
wp workos sync import --organization_id=org_01HXYZ --limit=10 --yes

# Import all organizations
wp workos sync orgs --yes
```

**Output format:** All bulk commands report a summary on completion:

```
Success: 45 synced, 2 failed, 3 skipped.
```

Non-fatal errors are shown as warnings during execution and counted in the summary.

---

### Common Options

All commands that display data support these output formats:

| Flag | Format |
|---|---|
| `--format=table` | ASCII table (default) |
| `--format=json` | JSON array |
| `--format=yaml` | YAML |
| `--format=csv` | CSV |
| `--format=ids` | Space-separated IDs (user list only) |

## Development

### Requirements

- PHP 7.4+
- Composer
- [slic](https://github.com/developer-starter/slic) (for running tests)

### Setup

```bash
composer install
```

### Running Tests

```bash
# Using slic (Docker-based)
cd wp-content/plugins
slic here
slic use workos
slic run wpunit

# Using Composer
composer test:wpunit
```

### Code Standards

```bash
# Check
composer lint

# Auto-fix
composer lint:fix
```

### Architecture

The plugin uses a DI container (di52) with a feature-controller pattern:

```
workos.php                    # Entry point
src/WorkOS/Plugin.php         # Bootstrap, container init
src/WorkOS/Controller.php     # Main controller, registers feature controllers
src/WorkOS/Config.php         # Centralized config with constant overrides
src/WorkOS/
  Admin/Controller.php        # Settings UI, user list enhancements
  Auth/Controller.php         # Login, registration, password reset
  Sync/Controller.php         # UserSync, RoleMapper, DirectorySync, AuditLog
  REST/Controller.php         # Bearer token authentication
  Webhook/Controller.php      # Webhook receiver
  Organization/Controller.php # Organization management
  CLI/Controller.php          # WP-CLI commands
```

Each controller extends `Contracts\Controller` and implements `isActive()` for conditional activation (e.g., `Admin\Controller` only activates in `is_admin()`, `CLI\Controller` only activates under `WP_CLI`).
