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
| Passkey and SSO sign-ins | Count as MFA (`mfa_trust_sso` option for SSO, filter `workos_mfa_satisfied_by_sign_in`) | Site, code |
| Sign-in step | `POST /auth/mfa/enroll`, `/auth/mfa/challenge`, `/auth/mfa/verify` and the React shell | Visitors |
| Password-only sign-ins | Refused for users with MFA (`workos_mfa_allow_password_fallback`) | Site |
| Account panel | Profile screen, `[workos:mfa]` shortcode, `/users/{id}/mfa…` routes | Users |
| Admin tools | **WorkOS → Settings → Authentication**, the user-edit screen, `PUT …/mfa/required`, `POST …/mfa/reset`, `wp workos mfa` | Admins, Support |
| Diagnostics | "Two-Step Verification" check on **WorkOS → Diagnostics** | Admins |
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
| `status( $user_id )` | `{ enabled, required, must_use, factors }`, read from WorkOS. `required` is the admin flag; `must_use` adds roles and filters |
| `list_factors( $user_id )` | Confirmed factors from WorkOS. Updates the mirrored flag to match: set when WorkOS has one, cleared when it has none (see below) |
| `start_enrollment( $user_id )` | Creates a TOTP factor and returns `{ factor_id, challenge_id, qr_code, secret, otpauth_uri }`. The factor stays unconfirmed, and doesn't count, until confirmed. One at a time per account |
| `confirm_enrollment( $user_id, $factor_id, $code, $challenge_id = '' )` | Checks the first code and turns MFA on |
| `check_code( $user_id, $factor_id, $code, $challenge_id = '' )` | Checks a code, counted against the account's wrong-code limit; no hooks |
| `require_current_factor( $user_id, $factor_id, $code )` | `true` when the user has no factor yet; otherwise needs a current code from one of theirs |
| `verify_code( $factor_id, $code, $challenge_id = '' )` | Checks a code with no limit. A challenge passed in must belong to the factor |
| `delete_factor( $user_id, $factor_id )` | Removes one of the user's factors (404 for anyone else's) |
| `reset( $user_id )` | Removes every factor, for a lost authenticator |
| `set_required( $user_id, $required )` | Sets or clears the require flag |

An unconfirmed factor is deleted from WorkOS when the user starts over, or
after a day. If WorkOS can't delete it, it stays tracked as unconfirmed.

`_workos_mfa_enabled` mirrors WorkOS, which is the source of truth. It's set
when a factor is confirmed or found in WorkOS, and cleared when the user
removes their last factor, an admin resets them, or WorkOS answers with no
factors. So removing a user's factors in the WorkOS dashboard (or another app)
turns their MFA off in WordPress at the next check: their next sign-in, or
their account panel. WordPress logs `mfa.removed_in_workos`, fires
`workos_mfa_removed_in_workos` and emails the user. A failed WorkOS call
never clears the flag. The require flag and required roles don't change, so a
user who must use MFA enrolls again at their next sign-in.

The reset (admin screen, `wp workos mfa reset`, `workos_mfa_reset()`) does
the same and also signs the user out everywhere.

### PHP helpers

```php
workos_mfa_get_status( $user_id = 0 );          // array|WP_Error — { enabled, required, factors }
workos_mfa_is_enabled( $user_id = 0 );          // bool — the user turned MFA on (mirrored flag, no WorkOS call)
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
| `workos_mfa_invalid_code` | 400 | Wrong, expired or malformed code, or a challenge for another factor |
| `workos_mfa_code_required` | 403 | Adding an authenticator to an account that has one, without a current code |
| `workos_mfa_too_many_codes` | 429 | Too many wrong codes for the account (5 in 15 minutes, 30 a day) |
| `workos_mfa_busy` | 429 | Another code check or enrollment is running for the account |
| `workos_mfa_invalid_user` | 404 | `workos_mfa_set_required()` got an unknown user |
| `workos_mfa_invalid_response` | 502 | WorkOS answered an enrollment without a factor |

## Passkey and SSO sign-ins

Some sign-ins already include a second factor, so the plugin's code step is
skipped for them. WorkOS's authenticate response says how the user signed in
(`authentication_method`), and the server reads it from WorkOS itself.

| Method | Skips the code step | Why |
|---|---|---|
| `Passkey` | Always | WorkOS only accepts a passkey with user verification (fingerprint, face or PIN), so it is both factors. WorkOS's own MFA counts it the same way |
| `SSO` | When **SSO and Two-Step Verification** is on (default, option `mfa_trust_sso`, filter `workos_mfa_trust_sso`) | The company's identity provider runs its own MFA. WorkOS's own MFA never applies to SSO users either |
| Anything else (`Password`, `MagicAuth`, `GoogleOAuth`, …) | No | |

It applies in every mode, so an `enforce: always` profile accepts a passkey
sign-in too. A user who must use MFA and has no authenticator isn't asked to
enroll after a passkey sign-in. When the policy would otherwise have asked for
a code or an enrollment, the plugin logs `mfa.step_skipped` and fires
`workos_mfa_step_skipped`.

```php
// Ask SSO users from one organization for a code anyway.
add_filter( 'workos_mfa_satisfied_by_sign_in', function ( bool $satisfied, string $method, array $response ) {
    if ( 'SSO' === $method && 'org_untrusted' === ( $response['organization_id'] ?? '' ) ) {
        return false;
    }
    return $satisfied;
}, 10, 3 );
```

### Offering passkeys

WorkOS runs passkey sign-ins on its hosted page only; there is no API for the
plugin's own form. A Login Profile with the **Passkey** method shows a
**Sign in with a passkey** button that sends the visitor to the hosted page
(`GET /auth/oauth/authorize-url?provider=passkey` returns a
`provider=authkit` URL), and the callback finishes the sign-in like any
other. To set it up:

1. Turn on Passkeys in the WorkOS dashboard (**Authentication**). In
   production, set up an AuthKit custom domain first: a passkey is bound to
   the domain it was created on.
2. Turn on WorkOS's progressive enrollment (**Authentication → Passkeys**). It
   offers password users a passkey at their next hosted sign-in, and it's how
   existing users get one: on staging, the hosted sign-up page didn't offer a
   passkey without it.
3. Tick **Passkey** on the Login Profile.

The hosted page offers every sign-in method turned on in WorkOS, not only
passkeys. A password sign-in there still gets the plugin's code step.
Users manage their passkeys in WorkOS; an admin can delete one from the user's
page in the WorkOS dashboard.

## Sign-in

Every AuthKit sign-in (password, magic code, OAuth, the hosted-AuthKit
callback) ends in `LoginCompleter::complete()`, which hands the browser one of
two MFA steps:

| Who asked | Response | The browser then |
|---|---|---|
| WorkOS (`mfa_challenge`), or the plugin's policy (`challenge`) | `{ mfa_required, mfa_token, enroll: false, factors }` | Challenges one of `factors` and verifies with `mfa_token` |
| The plugin's policy (`enroll`), or WorkOS (`mfa_enrollment`) | `{ mfa_required, mfa_token, enroll: true, factors: [] }` | Enrolls, then verifies the first code with `mfa_token` |

Nothing reaches the browser but the `mfa_token`. WorkOS's response (tokens
included) or its pending token waits server-side in `WorkOS\Mfa\PendingLogin`:
bound to the Login Profile, used once, capped at 5 wrong codes, and gone 10
minutes after the first factor however often it's touched (filter
`workos_mfa_pending_ttl`). The parked response is encrypted with a key derived
from the token, which the server doesn't keep. No WordPress session exists
until the code checks out.

In `plugin` mode the policy asks WorkOS for the user's factors at every
sign-in, so a factor added outside WordPress is challenged too, and one
removed there no longer is. Only factors
the profile allows can answer; a user who turned MFA on but has none the
profile can use is asked to enroll.

A profile the policy blocks answers `403 workos_mfa_blocked` (message filter
`workos_mfa_block_message`, action `workos_mfa_blocked`).

### Routes

All three take the profile and its nonce (`X-WorkOS-Nonce`), like every
`/auth` route.

| Route | Body | Returns |
|---|---|---|
| `POST /auth/mfa/enroll` | `mfa_token` | `201 { factor_id, challenge_id, qr_code, secret, otpauth_uri }`. Calling it again replaces the unfinished factor |
| `POST /auth/mfa/challenge` | `mfa_token`, `factor_id` | `{ challenge_id, expires_at }`. Only the sign-in's own factors |
| `POST /auth/mfa/verify` | `mfa_token`, `authentication_challenge_id`, `code` | `{ user, redirect_to }` with the session set. Only challenges this sign-in asked for |

Before 1.1.0, `/challenge` took any `factor_id` and `/verify` took WorkOS's
`pending_authentication_token`. Both now need an `mfa_token`.

| Code | Status | Cause |
|---|---|---|
| `workos_authkit_mfa_expired` | 403 | Unknown, used or expired `mfa_token`, or another profile's |
| `workos_authkit_factor_not_found` | 404 | The factor isn't one this sign-in may challenge |
| `workos_authkit_challenge_not_found` | 404 | The challenge wasn't issued for this sign-in |
| `workos_mfa_invalid_code` | 400 | Wrong code |
| `workos_authkit_mfa_attempts_exceeded` | 403 | Fifth wrong code; start over |
| `workos_mfa_too_many_codes` | 429 | Too many wrong codes for the account, across sign-ins |
| `workos_mfa_blocked` | 403 | The profile can't satisfy the user's MFA |
| `workos_authkit_mfa_enrollment_required` | 403 | WorkOS asked for an enrollment without saying which user |

Rate limits: `mfa_enroll_ip` / `mfa_enroll_token`, `mfa_challenge_ip` /
`mfa_challenge_factor`, `mfa_verify_ip` / `mfa_verify_token`, and
`mfa_code_account` across every surface (see [`rate-limiting.md`](rate-limiting.md)).

### Hosted AuthKit

The hosted page runs WorkOS's own MFA, but not the plugin's policy. When the
policy wants a code after the callback, the sign-in is parked and the visitor
lands on `/workos/login/{profile}/?workos_mfa=<code>`, which renders only the
MFA step, even for a profile in redirect mode. The code in the URL works once,
for a minute, and only to put the real `mfa_token` into the page; the page
drops it from the address bar and sends `Referrer-Policy: no-referrer`.

### Password-only sign-ins

`wp-login.php` with `?fallback=1`, `wp_signon()`, headless mode and the login
button take a username and password only, so they can't ask for a code. In
`plugin` mode they refuse (`403 workos_mfa_step_unsupported`) a user who has a
factor or must use MFA, and, for a user linked to WorkOS, one the default
Login Profile would ask a code of (`enforce: always`). An MFA error from WorkOS
never falls back to the WordPress password. Accounts not linked to WorkOS
aren't held to the profile setting, so turning on `always` can't lock out
local administrators. Application passwords are exempt, for the user they
belong to only (an XML-RPC `system.multicall` can't borrow one for another
user). To allow it anyway:

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
the first code checks out. Removing a factor needs a current code from it, and
adding a second one needs a current code from an existing one, so a session
someone else got hold of can't turn MFA off or add an authenticator of its
own. Adding or removing an authenticator emails the account and signs out its
other sessions.

### Routes

Cookie-authenticated with the core `wp_rest` nonce (`X-WP-Nonce`), so any UI
can use them.

| Route | Who | Body | Returns |
|---|---|---|---|
| `GET /users/{id}/mfa` | The owner, or `edit_user` (`workos_mfa_can_manage_user`) | — | `{ enabled, required, must_use, factors }` |
| `POST /users/{id}/mfa/enroll` | The owner (`workos_mfa_can_manage`) | `factor_id` and `code` when the account already has a factor | `201 { factor_id, challenge_id, qr_code, secret, otpauth_uri }` |
| `POST /users/{id}/mfa/confirm` | The owner | `factor_id`, `code`, `challenge_id` | Status |
| `DELETE /users/{id}/mfa/factors/{factor_id}` | The owner | `code` | Status |

Rate limits per user: `mfa_enroll_user`, `mfa_confirm_user`, `mfa_delete_user`,
plus the account's wrong-code limit. Errors add `workos_mfa_not_logged_in`
(401) and `workos_mfa_forbidden` (403).

The older profile-scoped routes (`GET /auth/mfa/factors`,
`POST /auth/mfa/totp/enroll`, `POST /auth/mfa/sms/enroll`,
`POST /auth/mfa/factor/delete`) go through the same service and the same
rules: `workos_mfa_can_manage`, a current code to add a second factor, the
confirm step for TOTP, and `code` to delete.

```js
// Third-party UI: is MFA on for the current user?
const res = await fetch( `/wp-json/workos/v1/users/${ userId }/mfa`, {
	headers: { 'X-WP-Nonce': wpApiSettings.nonce },
	credentials: 'same-origin',
} );
const { enabled, required } = await res.json();
```

## Admin tools

- **Settings:** **WorkOS → Settings → Authentication** has the mode
  (**Two-Step Verification**) and the roles that must use it, per environment.
- **User-edit screen:** for another linked user, shows whether MFA is on, a
  **Require** checkbox, and **Lost authenticator** (removes every factor on
  save). Shown to anyone with `edit_user` on the target, or whom
  `workos_mfa_can_manage_user` allows, e.g. a Support role. Changes apply
  once the profile saves, and a reset WorkOS refuses shows as a notice.
  A reset emails the user, signs them out everywhere and revokes their stored
  WorkOS session.
- **Not on yourself:** WordPress lets every user edit their own account, so
  the admin tools and routes never apply to your own account. Your own
  factors go through the account panel, which asks for a code.
- **Routes** (same permission):

  | Route | Body | Returns |
  |---|---|---|
  | `PUT /users/{id}/mfa/required` | `required` (bool) | `{ required, enabled }` |
  | `POST /users/{id}/mfa/reset` | — | `{ removed }` |

- **WP-CLI:**

  ```
  wp workos mfa status <user> [--format=json]
  wp workos mfa require <user>
  wp workos mfa unrequire <user>
  wp workos mfa reset <user> [--yes]
  ```

  `<user>` is an ID, email or login.

- **Diagnostics:** the "Two-Step Verification" check lists factors for a user
  that can't exist; a "not found" answer means the factor API is reachable with
  these keys. It also says which mode is active.

## Hooks

**Filters**

| Filter | Arguments | Default |
|---|---|---|
| `workos_mfa_mode` | `string $mode` | `mfa_mode` option, else `plugin` |
| `workos_mfa_trust_sso` | `bool $trust` | `mfa_trust_sso` option, else `true` |
| `workos_mfa_satisfied_by_sign_in` | `bool $satisfied, string $method, array $response, Profile $profile` | `true` for `Passkey`, and for `SSO` when trusted |
| `workos_mfa_required_for_user` | `bool $required, int $user_id, ?Profile $profile` | Flag or role |
| `workos_mfa_decision` | `string $decision, int $user_id, Profile $profile, array $context` | Policy table |
| `workos_mfa_totp_issuer` | `string $issuer, int $user_id` | Site name |
| `workos_mfa_block_message` | `string $message, int $user_id, Profile $profile` | Generic message |
| `workos_mfa_pending_ttl` | `int $ttl` | 600 seconds |
| `workos_mfa_allow_password_fallback` | `bool $allow, int $user_id` | `false` |
| `workos_mfa_can_manage` | `bool $can, int $user_id` | `true` for the account owner |
| `workos_mfa_can_manage_user` | `bool $can, int $user_id` | `edit_user` on the target, never yourself |
| `workos_mfa_accept_bearer_token` | `bool $passed, int $user_id, array $payload` | Whether the token's session signed in on this site |
| `workos_mfa_notify_user` | `bool $send, int $user_id, string $event` | `true` (`enrolled`, `removed`, `reset`, `removed_in_workos`) |
| `workos_bearer_token_user` | `int $wp_user_id, array $payload` | The user the token's `sub` maps to; 0 refuses (not MFA-specific) |
| `workos_relink_protected` | `bool $protected, int $wp_user_id` | The Re-link Protection setting; MFA turns it on for users with MFA (not MFA-specific) |

**Actions**

| Action | Arguments | When |
|---|---|---|
| `workos_mfa_enrolled` | `int $user_id, array $factor` | A new factor is confirmed |
| `workos_mfa_factor_removed` | `int $user_id, string $factor_id` | One factor is removed |
| `workos_mfa_reset` | `int $user_id, int $removed` | Every factor is removed |
| `workos_mfa_removed_in_workos` | `int $user_id` | WorkOS has no factors left for a user WordPress had as enrolled, and WordPress turns MFA off to match |
| `workos_mfa_required_changed` | `int $user_id, bool $required` | The require flag changes |
| `workos_mfa_challenge_succeeded` | `int $user_id` | The plugin's sign-in challenge is answered |
| `workos_mfa_challenge_failed` | `int $user_id, string $error_code` | A wrong code at the plugin's sign-in challenge |
| `workos_mfa_blocked` | `int $user_id, Profile $profile` | A sign-in is refused by MFA policy |
| `workos_mfa_step_skipped` | `int $user_id, string $method, Profile $profile` | A passkey or trusted SSO sign-in skips a code or enrollment the policy would have asked for |

## Activity log

| Event | Metadata |
|---|---|
| `mfa.enrolled` | `factor_id`, `factor_type`, `actor_id` |
| `mfa.removed` | `factor_id`, `actor_id` |
| `mfa.reset` | `removed`, `actor_id` |
| `mfa.removed_in_workos` | — |
| `mfa.required_set`, `mfa.required_cleared` | `actor_id` |
| `mfa.challenge_succeeded`, `mfa.challenge_failed` | `factor_type` |
| `mfa.blocked` | `profile` |
| `mfa.step_skipped` | `method`, `profile` |

## Security model

What the plugin guarantees in `plugin` mode, for a user who has or must use
MFA:

- **No session before the code.** Every sign-in that can set a session ends in
  `LoginCompleter`, which parks it until the code checks out. Paths that can't
  ask for a code (password-only forms, headless mode, the login button)
  refuse instead. WordPress's own password check can't finish one either.
- **Bearer tokens from elsewhere don't count.** Any app on the same WorkOS
  environment can get an access token for the user, and it verifies here.
  REST only accepts a token whose session (`sid`) signed in on this site
  (`WorkOS\Mfa\VerifiedSessions`, filter `workos_mfa_accept_bearer_token`).
- **Only the sign-in's own factors.** The browser never holds WorkOS's pending
  token. It can challenge only the factors the server listed for its sign-in,
  and verify only challenges it was given. A code must come from the factor
  its challenge belongs to.
- **Guessing is bounded per account.** 5 wrong codes per sign-in, and 5 per 15
  minutes and 30 per day per account across sign-ins, the account panel and
  the legacy routes (`WorkOS\Mfa\CodeGuard`). One code check per account runs
  at a time, so parallel requests can't overspend it. WorkOS adds its own:
  3 wrong codes per challenge, one verification per challenge, and a used
  code won't verify again.
- **A borrowed session can't change MFA.** Removing a factor, or adding one to
  an account that has one, needs a current code. The admin tools never apply
  to your own account. Changes email the account and sign out its other
  sessions.
- **The account can't be moved to another identity.** A user with MFA is never
  re-linked to a different WorkOS user by email; an admin can still with
  `wp workos user link --force`.
- **WorkOS stays the source of truth.** Factors added outside WordPress are
  challenged; factors removed there turn MFA off in WordPress, with an email
  to the user and an activity-log event. Users who must use MFA enroll again.

Known limits:

- Enrolling at sign-in trusts whoever passed the first factor. A user who must
  use MFA and hasn't enrolled can have it set up by someone holding their
  password. They get an email when it happens; enroll required users promptly.
- Codes are 6 digits and WorkOS accepts a few around the current one, so the
  per-account daily cap is what keeps guessing out of reach.
- In `workos` mode WorkOS enforces MFA, which doesn't cover SSO users, and
  Bearer tokens aren't gated.
- SSO sign-ins trust the identity provider's MFA by default. Turn
  **SSO and Two-Step Verification** off if an identity provider doesn't
  enforce it.

## Don't do this

- Don't store TOTP secrets or QR codes in WordPress. WorkOS holds them; the
  plugin returns them once, at enrollment.
- Don't write `_workos_mfa_enabled` yourself. Change factors in WorkOS or
  through the plugin, and the flag follows.
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
