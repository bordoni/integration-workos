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
