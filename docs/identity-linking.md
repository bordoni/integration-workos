# Identity linking

A WordPress user is tied to its WorkOS user by the `_workos_user_id` user meta. Every path that writes it goes through `WorkOS\Sync\UserSync::link_user()`: sign-in (custom UI, AuthKit, the AJAX form), the `user.created` webhook, directory sync, `wp workos sync`/`wp workos user import`, onboarding, the password-reset flow, and new WordPress users pushed to WorkOS.

## Re-link Protection

Without it, a sign-in or event whose WorkOS ID isn't linked anywhere falls back to matching the WordPress user by email and links it, even when that user is already linked to a different WorkOS user. The account silently moves to the new identity, and anything keyed on the old one (a license, a subscription, an audit trail) now points at the wrong person.

With **Re-link Protection** on, that match is refused:

- The WordPress user keeps its current link; nothing about it changes.
- `find_or_create_wp_user()` returns a `workos_link_conflict` error (HTTP 409), so a sign-in fails with "This account is already connected to a different sign-in" instead of logging in.
- The attempt is written to the user (`_workos_link_refused`: attempted WorkOS ID, email, source, time), shown on their profile under **WorkOS**, logged to the Activity Log as `link_refused` (when the Activity Log is on), and sent to the debug log.
- `workos_user_link_refused` fires.

Linking a user that has no link yet, or writing the same link again, is unaffected.

One WorkOS user maps to one WordPress user. With protection on (or `--force`), `link_user()` also refuses a WorkOS ID that is already linked to a different WordPress user.

The checks are read-then-write with no lock. Two first links that run at the same moment for one WorkOS user (a sign-in and the `user.created` webhook, say) can both pass them. When both match the same WordPress user by email, the same link is written twice and nothing is lost. When neither finds one, both can create a WordPress user (WordPress's own email check is read-then-write too), leaving two accounts linked to one WorkOS user; lookups then return the first by login. It needs both requests inside the same few milliseconds, and `wp workos user unlink` plus deleting the spare account clears it.

Reassigning an account on purpose stays possible, and is explicit:

```bash
wp workos user link <wp_user_id> <workos_user_id> --force
```

It asks for confirmation (`--yes` skips it), refuses when that WorkOS user is already linked to another WordPress user, and clears the refusal notice.

### A WorkOS user deleted and created again

`user.deleted` deprovisions the WordPress account (deactivate, demote or delete, per **Deprovision Action**) and leaves it linked to the deleted WorkOS ID. When a new WorkOS user later signs in with the same email, protection refuses the match: the address may belong to someone else now, so the old account, with its orders and licenses, isn't handed over automatically. The sign-in fails with the conflict message until an administrator decides.

Giving the old account to the new identity is a deliberate step, and `--force` alone isn't enough when the account was deactivated:

```bash
wp workos user link <wp_user_id> <new_workos_user_id> --force --reactivate
```

`--reactivate` clears the deactivation. A demoted account keeps the role it was demoted to; set the role yourself.

### Turning it on

**WorkOS → Users → Identity Linking → Re-link Protection**, per environment. On for new installs since 1.0.10; sites upgrading from an earlier version keep it off until they turn it on, so an update never changes who can sign in.

Or in `wp-config.php`, which locks the checkbox:

```php
define( 'WORKOS_RELINK_PROTECTION', true );          // both environments
define( 'WORKOS_PRODUCTION_RELINK_PROTECTION', true ); // one environment
```

The constant is read on every call, not only when it's synced into the options row, so the settings page can't switch off a site that sets it.

The `workos_relink_protection_enabled` filter has the last word.

## New WordPress users whose email is already in WorkOS

A WordPress user created on the site (guest checkout, an import, an administrator adding an account) is pushed to WorkOS, and so is one synced by hand (`wp workos sync`, `wp workos user sync --direction=push`, the WorkOS sync actions on the Users screen). When that email already belongs to a WorkOS user, WorkOS refuses the create with `email_not_available`. The plugin never links by email alone, so the account stays unlinked until the person signs in. Then:

- The existing WorkOS user is looked up by email and stored on the WordPress user as a **pending match** (`_workos_pending_match`: WorkOS ID, source, time). It is not a link: `_workos_user_id` stays empty, and Re-link Protection still applies. When that WorkOS user is already linked to a different WordPress user, nothing is stored.
- `workos_user_push_failed` fires with the existing WorkOS ID, so the site can tell the customer to sign in with their existing account. It fires for every other failed create too, with `null`.
- The debug log records it.

When the person signs in with that WorkOS account, sign-in matches the WordPress user by email and links it (the WordPress user has no link yet, so Re-link Protection allows it), the pending match is cleared, and `workos_user_linked` fires.

Both paths send `email_verified => true` to WorkOS by default. `workos_push_user_email_verified` changes that per user.

## Hooks

```php
/**
 * A WP user is now linked to a WorkOS user it wasn't linked to before
 * (first link or re-link; not when the same link is written again).
 */
do_action( 'workos_user_linked', int $wp_user_id, string $workos_id, string $previous_id, string $source );

/**
 * Re-link Protection kept a WP user on its current WorkOS user.
 */
do_action( 'workos_user_link_refused', int $wp_user_id, string $current_id, array $workos_user, string $source );

/**
 * A WP user couldn't be created in WorkOS. $existing_id is the WorkOS user
 * that already has the email, or null.
 */
do_action( 'workos_user_push_failed', int $user_id, WP_Error $error, ?string $existing_id );

/**
 * `email_verified` sent when a WP user is created in WorkOS. Default true.
 */
apply_filters( 'workos_push_user_email_verified', bool $verified, int $user_id, array $userdata );
```

`$source` is one of `login`, `authkit`, `ajax_login`, `webhook`, `directory_sync`, `cli_import`, `cli_link`, `onboarding`, `password_reset`, `user_register`, `manual_sync`.

`workos_user_linked` is the place to resume work that waited for an identity, e.g. re-running a licensing sync that was skipped because the customer had no WorkOS user yet:

```php
add_action( 'workos_user_linked', function ( $wp_user_id ) {
	foreach ( my_skipped_syncs_for( $wp_user_id ) as $subscription_id ) {
		my_queue_sync( $subscription_id );
	}
} );
```

## Require Linked Identity

With Re-link Protection on, links can be trusted, and the site can require one. **Require Linked Identity** keeps a signed-in user who has no linked WorkOS user out of the site until they connect one:

- **Pages and wp-admin** show only a standalone "Connect your account" page (HTTP 403, no theme, navigation or admin bar). The page names the account's email ("Sign in with name@example.com to connect…"): signing in with a different address creates a second account and leaves this one unconnected. Its **Connect account** button signs the unlinked session out and sends the user through WorkOS sign-in with the Login Profile chosen in **Connect With**, returning them to the page they asked for. Sign-in links the identity by verified email.
- **REST** requests get `403 workos_identity_required` before the route runs, except the sign-in routes under `workos/v1/auth`. The rest of `workos/v1` acts on accounts (email change, password reset), so it's blocked too; webhooks don't need an exception, since WorkOS calls them signed out.
- **admin-ajax** requests get `403` JSON with the same code; only `heartbeat` goes through.
- WooCommerce's `wc-ajax` and checkout are front-end requests, so they're covered: a signed-in unlinked user can't reach checkout. The **order-received** and **order-pay** pages stay reachable (the order key guards both), so a customer whose account was created at checkout but couldn't be linked (creating the WorkOS user failed, e.g. the email already exists there) still sees their order; the next page they open asks them to connect.
- Users who can `manage_options` are exempt. If the identity check itself fails, the user counts as unlinked.

### Turning it on

**WorkOS → Users → Identity Linking → Require Linked Identity**, shown once Re-link Protection is saved on. Turning the protection off turns the gate off too, whatever its own setting says.

```php
define( 'WORKOS_RELINK_PROTECTION', true );
define( 'WORKOS_REQUIRE_LINKED_IDENTITY', true );   // or WORKOS_{ENV}_REQUIRE_LINKED_IDENTITY
```

### Filters

| Filter | Default | Use |
|---|---|---|
| `workos_require_linked_identity` | the setting | Switch the gate per request (only while Re-link Protection is on) |
| `workos_identity_gate_exempt` | `user_can( $user, 'manage_options' )` | Exempt more users, e.g. support staff |
| `workos_identity_gate_allow_request` | WorkOS callback and login routes; WooCommerce order-received and order-pay | Let a blocked user reach another front-end URL |
| `workos_identity_gate_allow_rest_route` | `workos/v1/auth` | Let a blocked user reach another REST route |
| `workos_identity_gate_ajax_actions` | `[ 'heartbeat' ]` | Let a blocked user call another admin-ajax action |
| `workos_identity_gate_heading` | "Connect your account" | Page heading |
| `workos_identity_gate_message` | "Your account isn't connected to a sign-in yet. Sign in with {email}…" | Page text and the REST/AJAX error message (receives the user) |
