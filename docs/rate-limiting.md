# Rate Limiting

Two independent limits protect the public auth endpoints: one on who is asking, one on who receives the security email that asking produces.

This document is the source of truth for how requests to `/wp-json/workos/v1/auth/*` are throttled, how to tune it per deployment, and which parts of the design exist for reasons that aren't obvious from the code. If you are adding an endpoint that can cause an email to be sent, read [Adding a send path](#adding-a-send-path) before you write it.

> **Plugin requirement:** unreleased as of 1.0.8. Earlier versions shipped a single per-endpoint limiter with no per-recipient limit at all.

## Why two limits

A requester limit bounds how fast one caller can hit an operation. It cannot bound how much mail one mailbox receives, because that is a property of the recipient: several operations can each send to the same address under their own separate allowance, address variants like `alice+1@` would otherwise earn a fresh allowance each, and a per-minute window with nothing behind it makes the per-minute rate sustainable forever.

So the two dimensions are separate classes. `HttpRateLimit` answers "is this caller asking too fast?", per operation. `OutboundEmailRateLimiter` answers "has this mailbox received enough?", with one counter shared by every path that can send, so rotating between endpoints buys nothing.

Sign-up is limited more tightly than sign-in because it is the higher-leverage operation: it creates an account, and it does not require the address being registered to be verified. Verifying a credential is bounded by the account already existing.

## At a glance

|  | `HttpRateLimit` | `OutboundEmailRateLimiter` |
| --- | --- | --- |
| Counts | requests | messages sent |
| Keyed on | caller IP, and the subject acted on | the recipient mailbox |
| Scope | per operation | one counter shared by every send path |
| Windows | one, 60s | three, layered: 5min / hour / day |
| Refuses with | `429 workos_rate_limited` | `429`, or silence on enumeration-safe routes |
| Instances | one; non-default postures pass their limits at the call site | one |

Both sit on a shared `RateLimiter`, which owns windows and verdicts, and a `CounterStore`, which owns storage.

## Configuration

Requester limits are class constants, not settings. The default posture lives on `HttpRateLimit` (10 per IP, 5 per subject, 60s window); operations that differ declare their own constants next to the endpoint that owns the reasoning (`Signup::CREATE_PER_IP`, `OAuth::URL_PER_IP`). Changing one is a code change, deliberately: a per-endpoint constant is a public contract to support forever, and nobody tunes thirteen of them.

The operator-tunable settings are the ones with a genuine deployment story: how much mail one mailbox may receive, and which proxy header to trust. Define them in `wp-config.php`, or set them as real environment variables; constants win.

| Constant | Default | Applies to |
| --- | --- | --- |
| `WORKOS_EMAIL_LIMIT_5MIN` | 3 | messages to one mailbox per 5 minutes |
| `WORKOS_EMAIL_LIMIT_HOUR` | 10 | messages to one mailbox per hour |
| `WORKOS_EMAIL_LIMIT_DAY` | 20 | messages to one mailbox per day |
| `WORKOS_CLIENT_IP_HEADER` | *(unset)* | trusted proxy header, e.g. `CF-Connecting-IP` |

Anything missing, non-numeric or non-positive falls back to the default, so a typo degrades to the shipped value rather than to no limit.

The three email windows are layered on purpose. A single fixed window is burstable at its boundary by construction: send the maximum, wait for the boundary, send the maximum again. The short window keeps a mistyped address usable; the hour and day windows are the actual ceiling.

## Where limits apply

Every public auth route is limited by requester. A subset also counts as a send.

| Operation | Endpoint | Subject | Counts as a send |
| --- | --- | --- | --- |
| `password` | `POST /auth/password/authenticate` | email | only when WorkOS reports the address unverified |
| `pw_reset` | `POST /auth/password/reset/start` | email | yes |
| `pw_reset_confirm` | `POST /auth/password/reset/confirm` | — | no |
| `magic_send` | `POST /auth/magic/send` | email | yes |
| `magic_verify` | `POST /auth/magic/verify` | email | no |
| `signup` | `POST /auth/signup/create` | email | yes |
| `signup_verify` | `POST /auth/signup/verify` | — | no |
| `mfa_challenge` | `POST /auth/mfa/challenge` | factor ID | no |
| `mfa_verify` | `POST /auth/mfa/verify` | — | no |
| `invitation_lookup` | `GET /auth/invitation/{token}` | — | no |
| `invitation_accept` | `POST /auth/invitation/accept` | — | no |
| `oauth_url` | `GET /auth/oauth/authorize-url` | — | no |
| `pw_reset_admin` | `POST /admin/users/{id}/password-reset` | user ID | no — see below |

The change-email flow (`POST /users/{id}/email-change`) keeps its own hourly per-IP and per-user limits from plugin options, and counts **two** sends: one to the new address, one notice to the account's own.

`pw_reset_admin` deliberately doesn't count as a send. It requires `edit_user` on the target and mails that account's own address, so the recipient is never caller-chosen. It is not the exposure the per-recipient limit exists for.

## How counting works

A counter is identified by a **bucket** naming the operation and a **subject** naming what is being limited. Subjects are hashed into the key, so no address or IP is stored in plaintext.

The window is part of the key: `floor( now / window )`. A new window is simply a different key, which means expiry is arithmetic rather than something the storage layer has to get right. A counter that outlives its TTL can only affect a window that never comes round again.

Storage is the object cache when WordPress reports one, transients otherwise. That choice is made once in `RateLimit\Controller`. On a real cache backend `incr` is atomic, so the limit holds under concurrency; the transient path is a read-modify-write and says so in its docblock, and the layered hour and day windows bound what a short-window race can slip through.

### Reserve and release

Most send paths know they are about to send, so they count once and are done.

`password/authenticate` doesn't. WorkOS decides: it mails a verification code as a side effect of authenticating an unverified account, and only tells us in the response. So that path **claims the slot before the call** and hands it back if the response shows nothing was sent:

```php
$mail_slot = $this->outbound_email->reserve( $email );
if ( is_wp_error( $mail_slot ) ) {
    return $mail_slot;
}

// …WorkOS call…

$mail_slot->release(); // nothing was sent
```

Counting afterwards instead would leave a gap the width of the HTTPS round trip, and every concurrent request arriving inside it reads the same number and passes.

The `Reservation` carries the instant it was taken, and `release()` replays it rather than reading the clock again. Without that, a request whose round trip crosses a window boundary would credit the *next* window while leaving the original charged.

## Adding a send path

1. Take a slot before sending: `$this->outbound_email->reserve( $address )`.
2. If it returns a `WP_Error`, don't send. Return the 429, unless the route is enumeration-safe.
3. If you only learn afterwards that nothing went out, call `release()` on the handle.
4. One call per **message**, not per request. Two recipients means two reservations.

## Don't do this

### ❌ Don't give a new endpoint its own recipient counter.

Four endpoints with four separate allowances against one inbox add up. Every send path shares `OutboundEmailRateLimiter`, and rotating between them must buy nothing.

### ❌ Don't check the limit and send later.

`reserve()` counts first for a reason. A read-only check followed by a send leaves a window in which concurrent callers all pass, and the counter you were protecting never moves until it's too late.

### ❌ Don't read the clock twice around a reservation.

`release()` takes the instant from the `Reservation`. If you re-derive it, a boundary crossing mid-request refunds a window that was never charged and leaves the charged one spent.

### ❌ Don't return 429 from `magic/send` or `password/reset/start`.

Those routes answer identically for addresses that exist and addresses that don't. A 429 tells the caller their address is real, which hands back the account enumeration those uniform responses exist to deny. Skip the send silently and return the normal 200, including the timing floor on `reset/start`.

### ❌ Don't bucket on the raw address.

`alice@gmail.com`, `alice+1@gmail.com` and `a.l.i.c.e@gmail.com` are one mailbox. Always pass addresses through `AddressCanonicalizer` (`OutboundEmailRateLimiter` does this for you), or one mailbox has an unlimited supply of fresh counters.

### ❌ Don't read `X-Forwarded-For` or `CF-Connecting-IP` without `WORKOS_CLIENT_IP_HEADER` set.

`ClientIp` uses `REMOTE_ADDR` unless an operator names a header explicitly. A forwarded-for header is only authoritative if the request actually came through the proxy that set it; if the origin is reachable directly, trusting one lets any caller mint a fresh bucket per request just by changing a string. That is strictly worse than a shared bucket.

Even when the header is trusted, list values are read from the **right**: each hop appends, so everything right of the client was written by infrastructure and everything left of it is whatever the client sent. Private and reserved entries are skipped so internal hops don't shadow the client.

### ❌ Don't call `time()` in limiter code.

Use the injected `Clock`. It's what makes window rollover testable at all.

## Scope

Each mailbox gets its own counter, so the per-recipient limit bounds each mailbox individually; total volume across many mailboxes is bounded only by the per-IP limits. Mail WorkOS sends for flows the plugin doesn't proxy is out of scope entirely.

## Testing

Time-dependent behaviour uses the `Time` Codeception module, enabled in the wpunit suite. It swaps the container's `Clock` for one that only moves when told, and restores the real clock after each test.

```php
$helper = $this->getModule( '\Helper\Time' );
$clock  = $helper->freezeTime( 1_800_000_030 );

$helper->travelSeconds( 60 ); // next window
```

For subjects you construct directly, pass a `FrozenClock` to the `RateLimiter` instead.

Relative timestamps can't test any of this: the window boundary is derived from the clock, so `time() - 10` moves the data, not the boundary.
