# Capabilities

The plugin checks its own capabilities for WorkOS user management. Each one maps to the core capability the same check used before, so out of the box nothing changes: admins can do everything they could, and everyone else can do only what they could. A site that wants a narrower role, such as support staff who can look up WorkOS users and send a password reset without being able to edit users, grants the plugin capabilities to that role.

Code: [`src/WorkOS/Capabilities.php`](../src/WorkOS/Capabilities.php). Tests: [`tests/wpunit/CapabilitiesTest.php`](../tests/wpunit/CapabilitiesTest.php).

## The capabilities

| Capability | Maps to | Checked per user | What it gates |
| --- | --- | --- | --- |
| `workos_view_users` | `manage_options` | No | The **WorkOS → Users** screen and `GET /wp-json/workos/v1/admin/users` |
| `workos_send_password_reset` | `edit_user` | Yes | `POST /wp-json/workos/v1/admin/users/{id}/password-reset`, the "Send password reset" row action, the user-edit panel button, the `[workos:password-reset]` shortcode, and the button on the WorkOS Users screen |
| `workos_change_email` | `edit_user` | Yes | `POST /wp-json/workos/v1/users/{id}/email-change` (initiate), the capability path of `…/email-change/cancel`, the "Change email" row action, the user-edit panel, the `[workos:change-email]` shortcode, and the button on the WorkOS Users screen |
| `workos_manage_lockouts` | `edit_users` | No | Reserved for clearing sign-in lockouts. Nothing checks it yet |
| `workos_verify_email` | `edit_users` | No | `POST /wp-json/workos/v1/admin/users/{id}/verify-email` and the "Mark email verified" buttons on the WorkOS Users screen and the user-edit WorkOS panel. Not self-service: nobody can verify their own email unless they have `edit_users` or are granted it |

`edit_user` is per user: WordPress gives every logged-in user `edit_user` on their own account. That is what keeps the self-service password reset and email change working for subscribers, and it still does.

The PHP helper `workos_send_password_reset()` runs the same code as the route but checks no capability. Check `workos_send_password_reset` before calling it from a request; see [php-helpers.md](php-helpers.md#workos_send_password_reset).

## What stays on core capabilities

- **Settings and the other WorkOS admin pages** (Settings, Organization, Login Profiles, Activity Log, Diagnostics, Onboarding) stay on `manage_options`. None of the plugin capabilities opens them.
- **The WorkOS panel on the user-edit screen** stays on `edit_users`. Its "Mark email verified" button also needs `workos_verify_email`, so a support role without `edit_users` marks emails verified from **WorkOS → Users**.
- **Sync and re-sync** on `wp-admin/users.php` stay on `edit_users`.
- **Immediate email change.** An email change for another account commits immediately only when the caller has `edit_users`. A role granted just `workos_change_email` can start a change for someone else, but it goes through the emailed verification step, like self-service. See [change-email.md](change-email.md#admin-direct-vs-self-service).

## How the mapping works

The plugin hooks `map_meta_cap`:

- If the user's roles or their own account don't mention the capability, it resolves to the core capability in the table, with the same arguments. Default roles always take this path.
- If the user holds the capability (granted through a role or on the account), that grant decides. `true` allows the action for any user; `false` denies it, even when the core capability would allow it.
- On multisite, a per-user capability granted directly still can't target a super admin unless the caller is one, the same guard core applies to `edit_user`.

The grant is read from stored role and user capabilities. Capabilities added on the fly through the `user_has_cap` filter don't switch a check to the grant path.

## Granting them to a custom role

Run this once, for example on plugin activation or from a one-off WP-CLI command. Roles are stored in the database, so there is no need to run it on every request.

```php
add_role(
	'workos_support',
	'WorkOS Support',
	[
		'read'                       => true,
		'workos_view_users'          => true,
		'workos_send_password_reset' => true,
	]
);
```

A user with this role can open **WorkOS → Users**, search for a WorkOS user, and send a password reset. They can't open the plugin settings, change anyone's email, or edit users.

To add a capability to an existing role:

```php
get_role( 'editor' )->add_cap( 'workos_send_password_reset' );
```

Or with WP-CLI:

```bash
wp cap add editor workos_send_password_reset
```

To take an action away from a role that would otherwise have it:

```php
get_role( 'administrator' )->add_cap( 'workos_change_email', false );
```

### Notes for support roles

- The **WorkOS** top-level menu stays registered on `manage_options`. A user who has `workos_view_users` but not `manage_options` sees the menu with only the **Users** entry, and the menu link goes straight to it.
- The row actions on `wp-admin/users.php` only show to users who can open that screen, which needs `list_users`. Without it, a support role works from **WorkOS → Users** instead.
- The WorkOS Users screen shows each row's "Send password reset", "Change email" and "Mark email verified" buttons only when the current user has the matching capability for that row's WordPress user. Rows with no linked WordPress user have none of them.
