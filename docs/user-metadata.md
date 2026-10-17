# User metadata

Every linked WorkOS user carries a `puid:{site}` metadata key holding the WordPress user ID on that site (`{site}` is the home URL without the scheme, e.g. `puid:example.com` → `"42"`). Several sites can share one WorkOS environment, and each keeps its own key. The plugin writes it from `WorkOS\Sync\UserSync::update_platform_metadata()` when an account is linked (new WordPress user pushed to WorkOS, `wp workos sync`, the `user.created` webhook) and on every sign-in.

Sites can add keys of their own, such as a customer number from a billing system or a legacy account ID, so other apps that sign in through the same WorkOS environment can read them.

## Adding keys

```php
add_filter( 'workos_user_metadata', function ( array $metadata, int $wp_user_id, string $workos_id ): array {
	$customer_id = get_user_meta( $wp_user_id, 'billing_customer_id', true );
	if ( $customer_id ) {
		$metadata['billing_customer_id'] = (string) $customer_id;
	}
	return $metadata;
}, 10, 3 );
```

- `$metadata` starts with the plugin's own key: `[ 'puid:example.com' => '42' ]`.
- What you return is merged with the metadata already on the WorkOS user. Keys written by other sites and apps are kept.
- The `puid:{site}` key can't be changed or removed: whatever the filter returns, the plugin writes its own value. Other `puid:` keys belong to other sites and are rejected.
- Values must be strings or integers. Integers are sent as strings.
- Keys you stop returning stay on the WorkOS user. The plugin never deletes metadata.

The filter runs on the same paths as the `puid:` key (link and sign-in), and also when a profile is saved (`profile_update`). A site that doesn't use the filter makes no extra call on profile save.

## When something changes outside a sign-in

Changing a user meta value that the filter reads doesn't fire `profile_update`. Push the change yourself:

```php
update_user_meta( $user_id, 'billing_customer_id', $customer_id );
workos_sync_user_metadata( $user_id );
```

`workos_sync_user_metadata( int $wp_user_id ): bool|WP_Error` returns `true` when it wrote, `false` when WorkOS already had the values, and a `WP_Error` when the user isn't linked (`workos_not_linked`), WorkOS isn't configured (`workos_not_configured`) or the API call failed. It always reads the user from WorkOS first, so it also puts back keys that were removed outside WordPress.

## How writes are skipped

Each sync reads the WorkOS user, merges the keys, and sends an update only when a value differs.

Once the filter adds at least one key, the plugin also stores a hash of the WorkOS user ID and the keys it sent in the `_workos_metadata_hash` user meta. When the next sync builds the same set for the same WorkOS user, it stops there, with no API call, so sign-ins don't pay for the feature. The hash changes when a value changes or the account is linked to a different WorkOS user, and is cleared on unlink. A failed write stores no hash, so the next sync tries again.

The trade-off: if something outside WordPress deletes or overwrites one of your keys, a sign-in won't notice until your values change. `workos_sync_user_metadata()` ignores the hash and repairs it.

Sites that don't use the filter work as before: every sync reads the user and writes only when the `puid:` key is missing or wrong, and no hash is stored.

## WorkOS limits

From the WorkOS metadata docs (https://workos.com/docs/authkit/metadata, checked 2026-10-17):

| Limit | Value |
|---|---|
| Key-value pairs per user | 10 |
| Key length | 40 characters, ASCII only |
| Value length | 600 characters, ASCII only |

Updates are partial (keys you don't send are left alone), and metadata isn't returned in authentication responses; apps read it from the user, or through a JWT template claim.

The plugin checks these before writing (`UserSync::METADATA_MAX_KEYS`, `METADATA_MAX_KEY_LEN`, `METADATA_MAX_VALUE_LEN`):

- An entry with a key over 40 characters, a value over 600, non-ASCII text, a numeric key, or a value that isn't a string or integer is skipped.
- The 10-key limit counts every key on the WorkOS user, including other sites' `puid:` keys and anything other apps wrote. Your keys that are already on the user can always change. A new key that would make 11 is skipped. The plugin's own `puid:` key is never held back.
- Skipped entries are logged as errors (`[WorkOS][ERROR] Skipped WorkOS metadata key …`, written when `WORKOS_DEBUG` or `WP_DEBUG` is on). The rest of the keys are still written.

A key skipped for the 10-key limit isn't retried on sign-in while your keys stay the same (the hash matches). Once room frees up, call `workos_sync_user_metadata()`.

## Existing users

Keys reach a user on their next link, sign-in or profile save, or when `workos_sync_user_metadata()` runs for them. A bulk backfill command for existing users is planned for 1.1.0.
