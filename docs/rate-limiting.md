# Rate Limiting

This document covers how `integration-workos` throttles its public endpoints, how the limits are shaped, and which rules apply where.

It is written for two audiences in parallel: a developer adding or changing an endpoint, and an LLM agent reading it as the source of truth for code-gen. If you are adding a route that can cause an email to be sent, read [Adding a send path](#adding-a-send-path) before writing it.

> **Plugin requirement:** unreleased as of 1.0.8. Earlier versions shipped a single per-endpoint limiter with one 60-second window and no per-recipient limit.

---

## At a glance

Two questions get asked on every mutation, and they are not the same question:

- **Is this caller asking too fast?** Bounded per operation, on a single 60-second window.
- **Has this mailbox received enough?** Bounded across every path that can send, on layered windows.

A requester limit cannot answer the second one. Several operations can each mail the same address under their own separate allowance, and a per-minute window with nothing behind it makes that rate sustainable forever. So sends carry a second, longer-horizon limit keyed on the recipient.

Everything lives in `src/WorkOS/RateLimit/`:

| Class | Responsibility |
|---|---|
| `RateLimiter` | Interface. One bucket, one subject, one limit, one window. |
| `BaseRateLimiter` | Window arithmetic, key derivation, the `429` shape. |
| `DatabaseRateLimiter` | Counters in transients. |
| `ObjectCacheRateLimiter` | Counters in the object cache, incremented atomically. |
| `TieredRateLimiter` | Stacks windows into a policy. Owns the policy constants. |
| `Controller` | Binds the implementation and the wrapper. |

Client IP resolution lives outside this module, in `WorkOS\Http\ClientIp`, because the activity log and the audit log need the same answer. See [Who the caller is](#who-the-caller-is).

```mermaid
flowchart LR
    E[Endpoint] --> T[TieredRateLimiter]
    T -->|one window at a time| R{RateLimiter}
    R --> O[ObjectCacheRateLimiter]
    R --> D[DatabaseRateLimiter]
    O -.->|persistent object cache| C[(Object cache)]
    D -.->|otherwise| W[(wp_options)]
```

Endpoints depend on `TieredRateLimiter`. It knows about policies; the limiter under it only counts. `RateLimit\Controller` picks the implementation once at boot based on `wp_using_ext_object_cache()`, and is registered ahead of the features that consume it.

## Policies

A policy is a list of `[ limit, window_seconds ]` tiers. One tier behaves exactly like a plain per-minute limit; several tiers stack.

| Constant | Tiers | Used for |
|---|---|---|
| `REQUEST_IP` | 10 / min | Per-IP request throttle |
| `REQUEST_SUBJECT` | 5 / min | Per-email, per-user, or per-factor request throttle |
| `SEND_RECIPIENT` | 3 per 5 min, 5 per hour, 10 per 8 hours | Who receives the mail |
| `SEND_IP` | 10 per 5 min, 30 per hour, 100 per 8 hours | Who triggered the send |

Why the send policies layer: a single fixed window is burstable across its boundary by construction. Spend the maximum, wait for the rollover, spend it again. The short tier keeps a mistyped address usable within a few minutes; the hour and 8-hour tiers are the actual ceiling.

Why the request policies do not: a daily cap on sign-in attempts locks out legitimate users who mistype a password, and the abuse it would prevent is already covered by the send limits. Adding tiers there is a one-line edit if that changes.

Two endpoints declare their own request policy instead of using the shared ones, each next to the endpoint that owns the reasoning:

| Constant | Tiers | Why |
|---|---|---|
| `Signup::RATE_LIMIT_CREATE_IP` / `_CREATE_EMAIL` | 5 / min, 3 / min | Tighter: signup creates an account for an address nobody has proven they own. |
| `Signup::RATE_LIMIT_VERIFY_IP` | 10 / min | Verify is cheap and users retype codes. |
| `OAuth::RATE_LIMIT_URL_IP` | 20 / min | Looser: building an authorize URL sends nothing and costs no WorkOS call. |

These are class constants, not settings. There is no admin UI and no filter. Retuning is a code change, deliberately: the numbers are a support contract, and the earlier per-feature options (`change_email_rate_limit_*`, removed) were four knobs nobody tuned, where a stored `0` silently meant "unlimited".

## Where limits apply

Every public auth route is throttled by requester. A subset also counts as a send.

| Endpoint | Request subject | Request policy | Send policy |
|---|---|---|---|
| `POST /auth/password/authenticate` | email | shared | yes, reserved — see [Reserve and refund](#reserve-and-refund) |
| `POST /auth/password/reset/start` | email | shared | yes (`otp_send`) |
| `POST /auth/password/reset/confirm` | — | shared (IP only) | no |
| `POST /auth/magic/send` | email | shared | yes (`otp_send`) |
| `POST /auth/magic/verify` | email | shared | no |
| `POST /auth/signup/create` | email | `Signup` constants | no — see below |
| `POST /auth/signup/verify` | — | `Signup` constants (IP only) | no |
| `POST /auth/mfa/challenge` | factor ID | shared | no |
| `POST /auth/mfa/verify` | — | shared (IP only) | no |
| `GET /auth/invitation/{token}` | — | shared (IP only) | no |
| `POST /auth/invitation/accept` | — | shared (IP only) | no |
| `GET /auth/oauth/authorize-url` | — | `OAuth` constant | no |
| `POST /users/{id}/email-change` | user ID | — | yes |
| `POST /admin/users/{id}/password-reset` | user ID | shared | no |

"Shared" means `REQUEST_IP` plus, where there is a subject, `REQUEST_SUBJECT`.

`magic_send` and `pw_reset` share the `otp_send` bucket prefix, so rotating between those two endpoints buys an attacker nothing. `password` uses its own `password_otp` prefix: it reserves before it knows whether a send happens, and sharing would let heavy magic-code use lock an account out of password sign-in.

Two routes deliberately have no send policy. `signup` sends one verification email per created account, and a duplicate `create_user` fails at WorkOS before the send is reached, so it is not repeatable per address. `pw_reset_admin` requires `edit_user` on the target and mails that account's own address, so the recipient is never caller-chosen.

Send buckets are charged **before** deciding whether the send actually fires. That keeps `429` behavior identical for existing and unknown accounts, so bucket state cannot be used to enumerate which addresses have accounts.

## Reserve and refund

Most send paths know they are about to send. `password/authenticate` does not.

WorkOS decides: authenticating an account whose email is unverified makes it mail a one-time verification code as a side effect, and it only tells us afterwards, via an `email_verification_required` error. By the time the response is in hand, the email has already gone out.

So that path claims the slot before the call and hands it back when the outcome shows nothing was sent:

```php
$reserved = $this->rate_limit(
    [
        [ self::BUCKET_PASSWORD_OTP_IP, $ip, TieredRateLimiter::SEND_IP ],
        [ self::BUCKET_PASSWORD_OTP_RECIPIENT, $email, TieredRateLimiter::SEND_RECIPIENT ],
    ]
);
if ( is_wp_error( $reserved ) ) {
    return $reserved;
}

$workos_response = workos()->api()->authenticate_with_password( /* … */ );

if ( ! $this->triggered_verification_email( $workos_response ) ) {
    $this->rate_limiter->refund( self::BUCKET_PASSWORD_OTP_IP, $ip, TieredRateLimiter::SEND_IP );
    $this->rate_limiter->refund( self::BUCKET_PASSWORD_OTP_RECIPIENT, $email, TieredRateLimiter::SEND_RECIPIENT );
}
```

Counting afterwards instead would leave a gap the width of the HTTPS round trip, and every concurrent request arriving inside it would read the same number and pass. Refunding means wrong passwords and completed logins cost a recipient nothing, so the bucket only accumulates on outcomes that actually mailed.

One imprecision is deliberate: if an operator disables WorkOS-side verification emails, `email_verification_required` still charges a slot even though nothing was mailed. That setting is not visible from here, and charging is the safe direction.

## How counting works

A counter is identified by a **bucket** naming the operation and a **subject** naming what is limited. Subjects are hashed into the key, so no address or IP is stored in plaintext.

The window is part of the key: `floor( now / window )`. A new window is simply a different key, which means expiry is the storage TTL and `retry_after` is arithmetic, with no stored start time and no reset bookkeeping.

One consequence worth knowing: windows are aligned to the clock rather than starting at a caller's first attempt. A caller arriving at `:59` gets a fresh window one second later.

Tiers are charged shortest window first, and the first exhausted tier returns immediately. A burst stopped by the short tier therefore never inflates the longer ones. A refused attempt still counts against the tier that refused it, so hammering keeps that window pinned until it rolls.

### Who the caller is

Every per-IP bucket is only as good as the address behind it, so resolution has one owner: `WorkOS\Http\ClientIp`, shared by rate limiting, the activity log, and the audit log.

By default that is `REMOTE_ADDR` and nothing else. The webserver sets it and a caller cannot forge it.

Behind a CDN, `REMOTE_ADDR` is the edge, so every visitor collapses into one bucket and the proxy's own header carries the real client. That header is authoritative only if the request actually arrived through the proxy: if the origin is reachable directly, the header is caller-supplied, and trusting it is worse than ignoring it, because anyone can mint a fresh bucket per request by changing a string. So it is never auto-detected. An operator names it, asserting that the origin only accepts proxied traffic:

```php
define( 'WORKOS_CLIENT_IP_HEADER', 'CF-Connecting-IP' );
```

A real environment variable of the same name works too. When the header holds a list, the **right-most public** entry wins: `X-Forwarded-For` appends on each hop, so entries to the right were written by infrastructure and everything to the left is whatever the client sent. Private and reserved entries are skipped while walking leftwards, and an entirely private list (a local proxy in development) falls back to the right-most valid entry.

When nothing usable is found, resolution returns `0.0.0.0` so a bucket still has a stable subject.

### Storage

`ObjectCacheRateLimiter` is used when WordPress reports a persistent object cache. On a real backend `wp_cache_incr` is an atomic in-place increment, so two concurrent requests get distinct counts and a limit cannot be overspent.

`DatabaseRateLimiter` is the fallback. Incrementing is a read-modify-write, so a genuinely concurrent burst can overspend a window: several requests read the same count and all write the same value back. There is no fix at this layer without a lock. The longer tiers bound what that costs, and WorkOS's own back-end limits sit behind them.

To check which one a site is using:

```sh
wp eval 'var_dump( wp_using_ext_object_cache() );'
```

## Adding a send path

1. Take the slot before sending, with `SEND_IP` and `SEND_RECIPIENT`.
2. Reuse `BaseEndpoint::BUCKET_OTP_SEND_IP` / `_RECIPIENT` unless the path has a reason not to share the allowance. Bucket names are constants so the sharing survives a rename.
3. Charge it before any "should we actually send?" branch, so a `429` never depends on whether the account exists.
4. If you only learn afterwards that nothing went out, refund both buckets.

## Don't do this

### Don't give a new send endpoint its own recipient bucket.

Four endpoints with four separate allowances against one inbox add up to four times the mail. Share `otp_send` so rotating between endpoints buys nothing.

### Don't check the limit and send later.

`attempt()` counts first for a reason. A read-only check followed by a send leaves a window in which concurrent callers all pass.

### Don't skip the charge when the send is conditional.

If the bucket is only charged on the branch that really sends, the `429` boundary differs between known and unknown addresses, and that difference is an account-enumeration oracle.

### Don't add a per-feature limit option.

That is what was removed. One policy, one place, applied to every path that mails a caller-chosen address.

## Tests

| File | Covers |
|---|---|
| `tests/wpunit/RateLimiterTest.php` | The single-window contract, run against **both** implementations via a data provider. |
| `tests/wpunit/TieredRateLimiterTest.php` | Tier ordering and charging, against an in-memory fake limiter, plus a guard on the shipped policy constants. |
| `tests/wpunit/AuthKitRestPasswordTest.php` | Reserve/refund on authenticate; reset-start send caps; the shared bucket. |
| `tests/wpunit/AuthKitRestMagicSessionTest.php` | Magic-send caps and the shared bucket. |

`ObjectCacheRateLimiter` is only selected in production when a persistent cache exists, so the provider in `RateLimiterTest` is what exercises the atomic path in CI at all.

## Internal references

- `src/WorkOS/RateLimit/` — the module.
- `src/WorkOS/REST/Auth/BaseEndpoint.php` — the `rate_limit()` helper endpoints call.
- `src/WorkOS/Http/ClientIp.php` — caller identity, shared with the logs.
- [`docs/change-email.md`](change-email.md) — the change-email flow's use of the send policy.
- [`docs/password-reset.md`](password-reset.md) — the reset endpoints.
