# Multi-factor authentication (MFA)

WorkOS holds every factor and its secret. WordPress mirrors two flags per
user and decides, per site, who has to use a second factor. Everything the
plugin does with MFA goes through one PHP service, so REST, the PHP helpers,
WP-CLI and the admin screens behave the same and fire the same hooks.

Only TOTP (authenticator apps) is supported. WorkOS's built-in MFA is TOTP
only, and its SMS factors belong to a separate API that isn't tied to
WorkOS users.

## At a glance

| Surface | Where | Who |
|---|---|---|
| Mode | `mfa_mode` option per environment, filter `workos_mfa_mode` | Site |
| Who must use MFA | Require flag per user, `mfa_required_roles` option, filter `workos_mfa_required_for_user` | Admin, code |
| What a sign-in needs | `WorkOS\Mfa\Policy::decide()`, filter `workos_mfa_decision`, helper `workos_mfa_decision()` | Code |
| Sign-in step | `POST /auth/mfa/enroll`, `/auth/mfa/challenge`, `/auth/mfa/verify` and the React shell | Visitors |
| Password-only sign-ins | Refused for users with MFA (`workos_mfa_allow_password_fallback`) | Site |
| Account panel | Profile screen, `[workos:mfa]` shortcode, `/users/{id}/mfa…` routes | Users |
| Factors | `WorkOS\Mfa\FactorService`, helpers `workos_mfa_*` | Code |
| Activity log | `mfa.*` events | Admin |

## Modes

WorkOS's own MFA is one switch per environment (**Authentication →
Multi-factor auth → Required**). Once it's on, every non-SSO user must
enroll at their next sign-in. That suits a staff-only site, not a customer
portal where only some accounts should use MFA. So the plugin has three
modes:

| Mode | Who decides | Use it when |
|---|---|---|
| `plugin` (default) | The plugin: per user, role and Login Profile, using the table below | WorkOS MFA is off and only some users need MFA |
| `workos` | WorkOS, for every user in the environment | WorkOS MFA is set to Required |
| `off` | Nobody in WordPress | You don't want the plugin to challenge anyone |

In every mode, a step WorkOS asks for (`mfa_challenge`, `mfa_enrollment`)
still runs. WordPress can't skip a factor WorkOS requires.

```php
// Lock the mode in code, e.g. per environment.
add_filter( 'workos_mfa_mode', fn() => 'workos' );
```

## Who must use MFA

`Policy::is_required()` is true when any of these is:

1. The user's require flag (`_workos_mfa_required`), set by an admin or
   `workos_mfa_set_required()`.
2. The user has a role listed in the `mfa_required_roles` option.
3. The `workos_mfa_required_for_user` filter returns true.

```php
// Everyone who can edit others' posts must use MFA.
add_filter( 'workos_mfa_required_for_user', function ( bool $required, int $user_id ) {
    return $required || user_can( $user_id, 'edit_others_posts' );
}, 10, 2 );
```

## What a sign-in needs

After the first factor succeeds, `Policy::decide( $user_id, $profile )`
returns one of four answers. Only `plugin` mode decides anything; the other
modes always answer `none`.

| Profile `mfa.enforce` | Has a factor | Must use MFA | Decision |
|---|---|---|---|
| `never`, or TOTP not in `mfa.factors` | yes | any | `block` |
| `never`, or TOTP not in `mfa.factors` | no | yes | `block` |
| `never`, or TOTP not in `mfa.factors` | no | no | `none` |
| `if_required` | yes | any | `challenge` |
| `if_required` | no | yes | `enroll` |
| `if_required` | no | no | `none` |
| `always` | yes | any | `challenge` |
| `always` | no | any | `enroll` |

A profile that turns MFA off never lets a user with MFA sign in without it.
The sign-in is blocked instead, so the conflict is visible.

The `workos_mfa_decision` filter gets the last word:

```php
// Skip the challenge for requests from the office network.
add_filter( 'workos_mfa_decision', function ( string $decision, int $user_id, $profile, array $context ) {
    if ( 'challenge' === $decision && office_ip() ) {
        return 'none';
    }
    return $decision;
}, 10, 4 );
```

## Factors

`WorkOS\Mfa\FactorService` is the only code that touches a user's factors.
None of its methods check capabilities, so callers do.

| Method | Does |
|---|---|
| `status( $user_id )` | `{ enabled, required, factors }`, reconciled with WorkOS |
| `list_factors( $user_id )` | Confirmed factors from WorkOS. Also fixes the mirrored flag when factors were added or removed outside WordPress |
| `start_enrollment( $user_id )` | Creates a TOTP factor and returns `{ factor_id, challenge_id, qr_code, secret, otpauth_uri }`. The factor stays unconfirmed, and doesn't count, until confirmed |
| `confirm_enrollment( $user_id, $factor_id, $code, $challenge_id = '' )` | Checks the first code and turns MFA on |
| `verify_code( $factor_id, $code, $challenge_id = '' )` | Checks a code; no hooks |
| `delete_factor( $user_id, $factor_id )` | Removes one of the user's factors (404 for anyone else's) |
| `reset( $user_id )` | Removes every factor, for a lost authenticator |
| `set_required( $user_id, $required )` | Sets or clears the require flag |

An unconfirmed factor is deleted from WorkOS when the user starts over, or
after a day.

### PHP helpers

```php
workos_mfa_get_status( $user_id = 0 );          // array|WP_Error — { enabled, required, factors }
workos_mfa_is_enabled( $user_id = 0 );          // bool — mirrored flag, no WorkOS call
workos_mfa_is_required( $user_id = 0 );         // bool — flag, role or filter
workos_mfa_set_required( $user_id, $required ); // bool|WP_Error — whether it changed
workos_mfa_list_factors( $user_id = 0 );        // array|WP_Error
workos_mfa_reset( $user_id );                   // int|WP_Error — factors removed
workos_mfa_decision( $user_id = 0, $profile );  // 'none'|'challenge'|'enroll'|'block'
```

`$user_id = 0` means the current user. Like the service, the helpers don't
check capabilities.

### Errors

| Code | Status | Cause |
|---|---|---|
| `workos_mfa_user_not_linked` | 409 | The account has no WorkOS user |
| `workos_mfa_factor_not_found` | 404 | The factor isn't the user's, or isn't the one being enrolled |
| `workos_mfa_invalid_code` | 400 | Wrong, expired or malformed code |
| `workos_mfa_invalid_user` | 404 | `workos_mfa_set_required()` got an unknown user |
| `workos_mfa_invalid_response` | 502 | WorkOS answered an enrollment without a factor |

## Sign-in

Every AuthKit sign-in (password, magic code, OAuth, the hosted-AuthKit
callback) ends in `LoginCompleter::complete()`, which hands the browser one of
three MFA steps:

| Who asked | Response | The browser then |
|---|---|---|
| WorkOS (`mfa_challenge`) | `{ mfa_required, pending_authentication_token, factors }` | Challenges a factor and verifies with `pending_authentication_token` |
| The plugin's policy (`challenge`) | `{ mfa_required, mfa_token, enroll: false, factors }` | Challenges a factor and verifies with `mfa_token` |
| The plugin's policy (`enroll`), or WorkOS (`mfa_enrollment`) | `{ mfa_required, mfa_token, enroll: true, factors: [] }` | Enrolls, then verifies the first code with `mfa_token` |

With an `mfa_token`, nothing reaches the browser but that token. WorkOS's
response (tokens included) or its pending token waits server-side in
`WorkOS\Mfa\PendingLogin`: bound to the Login Profile, used once, capped at 5
wrong codes, and gone after 10 minutes (filter `workos_mfa_pending_ttl`). No
WordPress session exists until the code checks out.

A profile the policy blocks answers `403 workos_mfa_blocked` (message filter
`workos_mfa_block_message`, action `workos_mfa_blocked`).

### Routes

All three take the profile and its nonce (`X-WorkOS-Nonce`), like every
`/auth` route.

| Route | Body | Returns |
|---|---|---|
| `POST /auth/mfa/enroll` | `mfa_token` | `201 { factor_id, challenge_id, qr_code, secret, otpauth_uri }`. Calling it again replaces the unfinished factor |
| `POST /auth/mfa/challenge` | `factor_id`, plus `mfa_token` when there is one | `{ challenge_id, expires_at }`. With `mfa_token`, only the sign-in's own factors |
| `POST /auth/mfa/verify` | `authentication_challenge_id`, `code`, and `mfa_token` or `pending_authentication_token` | `{ user, redirect_to }` with the session set. With `mfa_token`, only challenges this sign-in asked for |

| Code | Status | Cause |
|---|---|---|
| `workos_authkit_mfa_expired` | 403 | Unknown, used or expired `mfa_token`, or another profile's |
| `workos_authkit_factor_not_found` | 404 | The factor isn't one this sign-in may challenge |
| `workos_authkit_challenge_not_found` | 404 | The challenge wasn't issued for this sign-in |
| `workos_mfa_invalid_code` | 400 | Wrong code |
| `workos_authkit_mfa_attempts_exceeded` | 403 | Fifth wrong code; start over |
| `workos_mfa_blocked` | 403 | The profile can't satisfy the user's MFA |
| `workos_authkit_mfa_enrollment_required` | 403 | WorkOS asked for an enrollment without saying which user |

Rate limits: `mfa_enroll_ip` / `mfa_enroll_token`, `mfa_challenge_ip` /
`mfa_challenge_factor`, `mfa_verify_ip` / `mfa_verify_token` (see
[`rate-limiting.md`](rate-limiting.md)).

### Hosted AuthKit

The hosted page runs WorkOS's own MFA, but not the plugin's policy. When the
policy wants a code after the callback, the sign-in is parked and the visitor
lands on `/workos/login/{profile}/?workos_mfa=<token>`, which renders only the
MFA step, even for a profile in redirect mode.

### Password-only sign-ins

`wp-login.php` with `?fallback=1`, `wp_signon()`, headless mode and the login
button take a username and password only, so they can't ask for a code. In
`plugin` mode they refuse (`403 workos_mfa_step_unsupported`) a user who has a
factor or must use MFA, and an MFA error from WorkOS never falls back to the
WordPress password. Application passwords are exempt. To allow it anyway:

```php
add_filter( 'workos_mfa_allow_password_fallback', fn( $allow, $user_id ) => user_can( $user_id, 'manage_options' ), 10, 2 );
```

In `workos` mode a WordPress password never reaches WorkOS, so turn the
password fallback off.

## Account panel

Users turn two-step verification on and off themselves:

- **Profile screen:** a "Two-step verification" section on `profile.php` for
  linked users.
- **`[workos:mfa]`:** the same panel on any page, e.g. a front-end account
  screen. It renders nothing for visitors, unlinked accounts, or when
  `workos_mfa_can_manage` says no.

Turning it on shows a QR code and setup key, and the factor only counts once
the first code checks out. Removing a factor needs a current code from it, so
a session someone else got hold of can't quietly turn MFA off.

### Routes

Cookie-authenticated with the core `wp_rest` nonce (`X-WP-Nonce`), so any UI
can use them.

| Route | Who | Body | Returns |
|---|---|---|---|
| `GET /users/{id}/mfa` | The owner, or `edit_user` (`workos_mfa_can_manage_user`) | — | `{ enabled, required, factors }` |
| `POST /users/{id}/mfa/enroll` | The owner (`workos_mfa_can_manage`) | — | `201 { factor_id, challenge_id, qr_code, secret, otpauth_uri }` |
| `POST /users/{id}/mfa/confirm` | The owner | `factor_id`, `code`, `challenge_id` | Status |
| `DELETE /users/{id}/mfa/factors/{factor_id}` | The owner | `code` | Status |

Rate limits per user: `mfa_enroll_user`, `mfa_confirm_user`, `mfa_delete_user`.
Errors add `workos_mfa_not_logged_in` (401) and `workos_mfa_forbidden` (403).

The older profile-scoped routes (`GET /auth/mfa/factors`,
`POST /auth/mfa/totp/enroll`, `POST /auth/mfa/factor/delete`) go through the
same service: enrollment needs the confirm step, and delete needs `code`.

```js
// Third-party UI: is MFA on for the current user?
const res = await fetch( `/wp-json/workos/v1/users/${ userId }/mfa`, {
	headers: { 'X-WP-Nonce': wpApiSettings.nonce },
	credentials: 'same-origin',
} );
const { enabled, required } = await res.json();
```

## Hooks

**Filters**

| Filter | Arguments | Default |
|---|---|---|
| `workos_mfa_mode` | `string $mode` | `mfa_mode` option, else `plugin` |
| `workos_mfa_required_for_user` | `bool $required, int $user_id, ?Profile $profile` | Flag or role |
| `workos_mfa_decision` | `string $decision, int $user_id, Profile $profile, array $context` | Policy table |
| `workos_mfa_totp_issuer` | `string $issuer, int $user_id` | Site name |
| `workos_mfa_block_message` | `string $message, int $user_id, Profile $profile` | Generic message |
| `workos_mfa_pending_ttl` | `int $ttl` | 600 seconds |
| `workos_mfa_allow_password_fallback` | `bool $allow, int $user_id` | `false` |
| `workos_mfa_can_manage` | `bool $can, int $user_id` | `true` for the account owner |
| `workos_mfa_can_manage_user` | `bool $can, int $user_id` | `edit_user` on the target |

**Actions**

| Action | Arguments | When |
|---|---|---|
| `workos_mfa_enrolled` | `int $user_id, array $factor` | A new factor is confirmed |
| `workos_mfa_factor_removed` | `int $user_id, string $factor_id` | One factor is removed |
| `workos_mfa_reset` | `int $user_id, int $removed` | Every factor is removed |
| `workos_mfa_required_changed` | `int $user_id, bool $required` | The require flag changes |
| `workos_mfa_challenge_succeeded` | `int $user_id` | The plugin's sign-in challenge is answered |
| `workos_mfa_challenge_failed` | `int $user_id, string $error_code` | A wrong code at the plugin's sign-in challenge |
| `workos_mfa_blocked` | `int $user_id, Profile $profile` | A sign-in is refused by MFA policy |

## Activity log

| Event | Metadata |
|---|---|
| `mfa.enrolled` | `factor_id`, `factor_type` |
| `mfa.removed` | `factor_id` |
| `mfa.reset` | `removed`, `actor_id` |
| `mfa.required_set`, `mfa.required_cleared` | `actor_id` |
| `mfa.challenge_succeeded`, `mfa.challenge_failed` | `factor_type` |
| `mfa.blocked` | `profile` |

## Don't do this

- Don't store TOTP secrets or QR codes in WordPress. WorkOS holds them; the
  plugin returns them once, at enrollment.
- Don't write `_workos_mfa_enabled` yourself. It mirrors WorkOS, and
  `list_factors()` overwrites it.
- Don't let a profile with `enforce: never` stand in for "MFA off for this
  user". It blocks users who have MFA. Use `workos_mfa_decision` for
  exceptions.

## Internal references

| Concept | Source |
|---|---|
| Modes and options | `src/WorkOS/Mfa/Settings.php` |
| Decision table | `src/WorkOS/Mfa/Policy.php` |
| Factors, flags, hooks, events | `src/WorkOS/Mfa/FactorService.php` |
| Helpers | `src/includes/functions-helpers.php` |
| WorkOS MFA errors | `src/WorkOS/Auth/AuthKit/MfaStep.php` |
| Sign-in completion | `src/WorkOS/Auth/AuthKit/LoginCompleter.php` |
| Parked sign-ins | `src/WorkOS/Mfa/PendingLogin.php` |
| Sign-in routes | `src/WorkOS/REST/Auth/Mfa.php` |
| Password-only guard | `src/WorkOS/Mfa/PasswordGuard.php` |
| Enroll and challenge steps | `src/js/authkit/flows.tsx` (`MfaEnroll`, `MfaChallenge`) |
| Account routes | `src/WorkOS/Mfa/RestApi.php` |
| Account panel | `src/js/mfa-settings/index.tsx`, `src/WorkOS/Mfa/{Assets,Shortcode,UserProfilePanel}.php` |
| WorkOS calls | `src/WorkOS/Api/Client.php` (`list_auth_factors`, `enroll_totp_factor`, `challenge_auth_factor`, `verify_auth_challenge`, `delete_auth_factor`) |
