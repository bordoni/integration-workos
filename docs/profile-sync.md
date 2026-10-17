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
