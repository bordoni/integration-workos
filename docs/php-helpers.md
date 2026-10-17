# PHP helpers

Global functions for themes and plugins that build their own account pages on top of integration-workos. Each one is a thin wrapper over code the plugin already runs, so it uses the plugin's API client, keys and active environment. Don't call the WorkOS API yourself with the client secret, don't hard-code `https://api.workos.com`, and don't decode the access token to find its expiry or session ID.

| Function | Returns | What it does |
| --- | --- | --- |
| `workos_get_user_id( int $user_id = 0 )` | `string` | WorkOS user ID (`user_…`) linked to the WP user, or `''` |
| `workos_get_access_token( int $user_id = 0 )` | `string` | Access token as stored. May be expired. Never calls WorkOS |
| `workos_get_valid_access_token( int $user_id = 0 )` | `string\|WP_Error` | Access token that is good for at least 60 more seconds, refreshed first if needed |
| `workos_send_password_reset( int $user_id, array $args = [] )` | `true\|WP_Error` | Sends the WorkOS reset email through the admin "Send password reset" path |
| `workos_get_logout_url( int $user_id = 0, string $return_to = '' )` | `string` | WorkOS logout URL for the current session, or `wp_logout_url()` |

`$user_id = 0` (or omitted) means the current user. Each function has a `WorkOS\User` counterpart except `workos_send_password_reset()`, which calls `WorkOS\Auth\PasswordResetAdmin\RestApi::send_for_user()`.

The other helpers (`workos_is_sso_user()`, `workos_has_active_session()`) are covered in the README under "Helpers for Third-Party Integrations".

## `workos_get_valid_access_token()`

Returns the stored access token while it has more than `WorkOS\User::ACCESS_TOKEN_REFRESH_LEEWAY` (60) seconds left. Otherwise it exchanges the stored refresh token with WorkOS and stores the new pair, the same calls `POST /wp-json/workos/v1/auth/session/refresh` makes. A refresh rotates both tokens, so any copy you kept of the old ones stops working.

```php
$token = workos_get_valid_access_token();
if ( is_wp_error( $token ) ) {
	return $token;
}

$response = wp_remote_get(
	'https://example.com/api/me',
	[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
);
```

Errors:

| Code | When |
| --- | --- |
| `workos_user_not_linked` | No user, or the user has no WorkOS link |
| `workos_no_session` | The user signed out (no tokens stored), or the token expired and there is no refresh token |
| `workos_refresh_failed` | WorkOS answered the refresh without an access token |
| `workos_api_error` (or a transport error) | WorkOS refused the refresh, for example because the session was revoked. The stored tokens are left as they were |

If two requests refresh at once, the second one's refresh token is already spent. When that happens and the first one has stored a fresh token, the helper returns that token instead of the error.

The expiry is read from the stored token without checking its signature. That's fine here: the token was stored server-side from a WorkOS response, and the result only decides whether to refresh. Use `workos()->api()->verify_access_token()` when you need to trust a token, and only pass a user ID your own code has authenticated.

## `workos_send_password_reset()`

Runs the same code as `POST /wp-json/workos/v1/admin/users/{id}/password-reset` (see [password-reset.md](password-reset.md)):

- The user must exist, be linked to WorkOS and have a valid email.
- The Login Profile must have the password reset flow on.
- The per-IP (10 a minute) and per-user (5 a minute) limits apply, shared with the REST route.
- `redirect_url` goes through `RedirectValidator`: a URL on another host falls back to the profile's post-login redirect, then the home page.
- A `password_reset.admin_sent` row is written to the activity log.

`$args`:

| Key | Default | |
| --- | --- | --- |
| `redirect_url` | `''` | Where the user lands after setting the new password |
| `profile` | `'default'` | Login Profile slug whose reset page the email links to |

The helper does not check capabilities; the REST route does. Check `edit_user` yourself when the call comes from a request:

```php
add_action( 'admin_post_my_account_reset_password', static function () {
	check_admin_referer( 'my_account_reset_password' );

	$user_id = get_current_user_id();
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( 'Not allowed.', 403 );
	}

	$sent = workos_send_password_reset( $user_id, [ 'redirect_url' => home_url( '/account/' ) ] );
	if ( is_wp_error( $sent ) ) {
		wp_die( esc_html( $sent->get_error_message() ), 400 );
	}

	wp_safe_redirect( home_url( '/account/?reset=sent' ) );
	exit;
} );
```

It returns the REST route's errors: `workos_user_not_found`, `workos_user_not_linked`, `workos_user_missing_email`, `workos_profile_not_found`, `workos_reset_disabled`, `workos_rate_limited`, or the WorkOS API error.

## `workos_get_logout_url()`

With a stored WorkOS session, returns WorkOS's logout URL for it:

```
https://api.workos.com/user_management/sessions/logout?session_id=session_…&return_to=…
```

`return_to` is left out when empty. WorkOS only redirects to URLs on the environment's sign-out redirect list (WorkOS dashboard, Redirects). With no stored session (not linked, or already signed out) it returns `wp_logout_url( $return_to )`.

The two URLs end different things:

- `wp_logout_url()` signs the user out of WordPress. The plugin then revokes the WorkOS session server-side and clears the stored tokens, so this ends both.
- The WorkOS URL sends the browser to WorkOS to end the WorkOS session. It doesn't clear the WordPress auth cookie.

```php
// Ends the WorkOS session, then comes back to the home page.
printf(
	'<a href="%s">%s</a>',
	esc_url( workos_get_logout_url( 0, home_url( '/' ) ) ),
	esc_html__( 'Sign out', 'my-theme' )
);
```

Building the URL never calls WorkOS.
