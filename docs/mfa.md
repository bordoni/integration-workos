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

## Sign-in errors from WorkOS

When WorkOS requires MFA, `authenticate` answers with an error, not a
success:

- `mfa_challenge`: the sign-in form shows the code step
  (`{ mfa_required, pending_authentication_token, factors }`). The profile's
  factor allowlist applies.
- `mfa_enrollment`: refused with `403 workos_authkit_mfa_enrollment_required`
  for now.

Sign-in paths that can't collect a code (headless `wp-login.php`, the login
button's form) refuse with `403 workos_mfa_step_unsupported`. They never fall
back to the WordPress password, since WorkOS already accepted the password and
only the second factor is missing.

## Hooks

**Filters**

| Filter | Arguments | Default |
|---|---|---|
| `workos_mfa_mode` | `string $mode` | `mfa_mode` option, else `plugin` |
| `workos_mfa_required_for_user` | `bool $required, int $user_id, ?Profile $profile` | Flag or role |
| `workos_mfa_decision` | `string $decision, int $user_id, Profile $profile, array $context` | Policy table |
| `workos_mfa_totp_issuer` | `string $issuer, int $user_id` | Site name |

**Actions**

| Action | Arguments | When |
|---|---|---|
| `workos_mfa_enrolled` | `int $user_id, array $factor` | A new factor is confirmed |
| `workos_mfa_factor_removed` | `int $user_id, string $factor_id` | One factor is removed |
| `workos_mfa_reset` | `int $user_id, int $removed` | Every factor is removed |
| `workos_mfa_required_changed` | `int $user_id, bool $required` | The require flag changes |

## Activity log

| Event | Metadata |
|---|---|
| `mfa.enrolled` | `factor_id`, `factor_type` |
| `mfa.removed` | `factor_id` |
| `mfa.reset` | `removed`, `actor_id` |
| `mfa.required_set`, `mfa.required_cleared` | `actor_id` |

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
| WorkOS calls | `src/WorkOS/Api/Client.php` (`list_auth_factors`, `enroll_totp_factor`, `challenge_auth_factor`, `verify_auth_challenge`, `delete_auth_factor`) |
