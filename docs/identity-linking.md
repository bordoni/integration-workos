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

Reassigning an account on purpose stays possible, and is explicit:

```bash
wp workos user link <wp_user_id> <workos_user_id> --force
```

It asks for confirmation (`--yes` skips it), refuses when that WorkOS user is already linked to another WordPress user, and clears the refusal notice.

### Turning it on

**WorkOS → Users → Identity Linking → Re-link Protection**, per environment. Off by default.

Or in `wp-config.php`, which locks the checkbox:

```php
define( 'WORKOS_RELINK_PROTECTION', true );          // both environments
define( 'WORKOS_PRODUCTION_RELINK_PROTECTION', true ); // one environment
```

The constant is read on every call, not only when it's synced into the options row, so the settings page can't switch off a site that sets it.

The `workos_relink_protection_enabled` filter has the last word.

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

- **Pages and wp-admin** show only a standalone "Connect your account" page (HTTP 403, no theme, navigation or admin bar). Its **Connect account** button signs the unlinked session out and sends the user through WorkOS sign-in with the Login Profile chosen in **Connect With**, returning them to the page they asked for. Sign-in links the identity by verified email.
- **REST** requests get `403 workos_identity_required` before the route runs, except the plugin's own `workos/v1` routes (sign-in, webhooks).
- **admin-ajax** requests get `403` JSON with the same code; only `heartbeat` goes through.
- WooCommerce's `wc-ajax` and checkout are front-end requests, so they're covered: a signed-in unlinked user can't reach checkout, and a guest who finishes checkout without an identity lands on the connect page instead of their account.
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
| `workos_identity_gate_allow_request` | WorkOS callback and login routes | Let a blocked user reach another front-end URL |
| `workos_identity_gate_allow_rest_route` | `workos/v1` | Let a blocked user reach another REST route |
| `workos_identity_gate_ajax_actions` | `[ 'heartbeat' ]` | Let a blocked user call another admin-ajax action |
| `workos_identity_gate_heading` | "Connect your account" | Page heading |
| `workos_identity_gate_message` | "Your account isn't connected to a sign-in yet…" | Page text and the REST/AJAX error message |
