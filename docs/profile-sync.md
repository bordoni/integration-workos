# Profile sync (WordPress to WorkOS)

When a linked WordPress user's first name, last name or email changes, `WorkOS\Sync\UserSync` sends the changed fields to WorkOS with one `update_user` call. Unchanged fields are left out.

## When it runs

| Change made with | Sent |
|---|---|
| The profile screen in wp-admin, `wp_update_user()`, `wp_insert_user()` | On `profile_update`, names and email together |
| `update_user_meta( $id, 'first_name' \| 'last_name', … )` | On `shutdown`, all name changes for that user in the request together |

Names live in user meta, which `wp_insert_user()` writes before `profile_update` fires, so the old user object passed to that hook already holds the new name. The plugin records name changes on `added_user_meta` / `updated_user_meta` instead (they only fire when the value changed) and sends them from `profile_update`, or from `shutdown` when no `profile_update` follows.

## When it doesn't

- The user isn't linked (no `_workos_user_id`).
- The change came from WorkOS: sign-in, the `user.updated` webhook, directory sync and re-sync write the profile while `UserSync::is_syncing()` is true, so nothing echoes back.
- The email-change confirm flow is committing (`ChangeEmail\RestApi` pushes to WorkOS itself).
- The new email is a placeholder (see below). Only the email is dropped; name changes in the same save still go.
- A `workos_pre_push_profile` callback returned false or an empty array.

## Placeholder emails

Sites that clean up or merge accounts often park an account on an address that can't receive mail (`someone@sanitized.fake`). `workos_is_deliverable_email( $email )` returns false for:

- the `.fake`, `.invalid`, `.test`, `.example` and `.localhost` TLDs;
- `example.com`, `example.net` and `example.org`, and their subdomains;
- anything without a domain.

The plugin uses it in two places:

- **Profile push.** An undeliverable email isn't sent to WorkOS, so the WorkOS login email never moves to a placeholder. It's written to the debug log; the other changed fields are still sent.
- **User creation.** `UserSync::find_or_create_wp_user()` won't create a new WordPress user for a WorkOS user whose email is undeliverable. It returns a `workos_undeliverable_email` error (HTTP 403) and writes to the debug log. This covers sign-in, the `user.created` webhook and directory sync. A WorkOS user already linked to a WordPress user by WorkOS ID still signs in.

Real addresses aren't affected.

## When WorkOS rejects it

The common case is an email change to an address another WorkOS user already holds: WorkOS answers `email_not_available`, WordPress keeps the new email and WorkOS keeps the old one. On any error, including transport errors:

- `_workos_last_synced_at` isn't updated, so the user stays marked as out of sync.
- The error is stored on the user in `_workos_sync_error` (`UserSync::META_SYNC_ERROR`): `code` (the WorkOS code, e.g. `email_not_available`, or the `WP_Error` code for transport errors), `message`, `fields` and `time`. The WorkOS panel on the user-edit screen shows it.
- It's written to the debug log and, when the Activity Log is on, logged as `profile_push_failed`.
- `workos_profile_push_failed` fires.

The next successful push clears `_workos_sync_error`. Unlinking the user clears it too.

## Hooks

```php
/**
 * A profile push to WorkOS failed. WordPress keeps its change.
 *
 * $update holds the fields that were sent (`first_name`, `last_name`, `email`).
 */
do_action( 'workos_profile_push_failed', int $user_id, string $workos_id, array $update, WP_Error $error );

/**
 * Whether mail to an address can be delivered. Return true to allow a
 * reserved domain, or false to block more (e.g. your own parking domain).
 */
apply_filters( 'workos_is_deliverable_email', bool $deliverable, string $email );

/**
 * The fields about to be pushed to WorkOS for a profile change.
 * Return false or an empty array to skip the push, or a changed array.
 */
apply_filters( 'workos_pre_push_profile', array $update, int $user_id, string $workos_id );
```
