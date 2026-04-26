# Extending the Login UI

The `integration-workos` plugin renders its login screen as a single-page React application (the **AuthKit shell**). This document describes the three public extension surfaces:

1. **WordPress SlotFill** — inject React elements into named slots in the login UI.
2. **`workos_authkit_enqueue_assets` action** — enqueue per-profile CSS and/or JavaScript files alongside the AuthKit bundle.
3. **PHP filters** — last-mile mutation of the data the shell renders.

The same surfaces are available everywhere the AuthKit shell appears: the wp-login.php takeover, the `[workos:login]` shortcode, and the `/workos/login/{slug}` rewrite.

---

## 1. SlotFill: injecting elements into the React UI

The AuthKit shell ships its app inside a `SlotFillProvider` and a `PluginArea` scoped to `workos-authkit`. To register a UI extension, build a small React bundle that calls `registerPlugin` with a `Fill` targeting one of the named slots below.

### Available slots

Every slot's `fillProps` carries the active step and profile context so a Fill can render conditionally:

```ts
{
    step: Step;            // 'pick' | 'password' | 'magic_send' | 'magic_verify' | 'mfa' | 'signup' | …
    profileSlug: string;   // 'default', 'members-area', etc.
    methods: AuthMethod[]; // enabled sign-in methods on this profile
    flow?: string;         // present on flow-specific slots, e.g. 'password', 'magic_send'
}
```

| Slot name | Where it renders |
| --- | --- |
| `workos.authkit.beforeHeader` | Top of every flow card, above the logo |
| `workos.authkit.afterHeader` | Below the heading/subheading, above the form |
| `workos.authkit.beforeForm` | Inside the form, above the first field |
| `workos.authkit.afterForm` | Inside the form, below the last field, above the primary button |
| `workos.authkit.afterPrimaryAction` | Inside the form, below the primary submit button |
| `workos.authkit.beforeFooter` | Above secondary links (back, forgot password, etc.) |
| `workos.authkit.afterFooter` | At the very bottom of every flow card |
| `workos.authkit.belowCard` | Outside the card, full-width below the form. Default fill renders the standard wp-login.php links ("Lost your password?" and "← Go to {Site}"); registering any Fill in this slot replaces the default. |
| `workos.authkit.methodPicker.beforeMethods` | MethodPicker only — above the method buttons |
| `workos.authkit.methodPicker.afterMethods` | MethodPicker only — below the method buttons |

The slot name constants are exported from `src/js/authkit/slots.tsx`.

### Example

Two files — a PHP loader plus a small JS bundle.

**`my-extension/my-extension.php`**

```php
<?php
add_action( 'workos_authkit_enqueue_assets', function ( $profile ) {
    wp_enqueue_script(
        'my-authkit-extension',
        plugins_url( 'build/index.js', __FILE__ ),
        [
            'workos-authkit',  // ensures we load after AuthKit's bundle
            'wp-plugins',
            'wp-components',
            'wp-element',
        ],
        '1.0',
        true
    );
} );
```

**`my-extension/src/index.tsx`**

```tsx
import { Fill } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

registerPlugin( 'my-extension', {
    scope: 'workos-authkit',
    render: () => (
        <Fill name="workos.authkit.afterForm">
            { ( { step, profileSlug } ) =>
                step === 'password' && profileSlug === 'members-area' ? (
                    <p>
                        Need help signing in? <a href="/support">Contact us</a>.
                    </p>
                ) : null
            }
        </Fill>
    ),
} );
```

`registerPlugin` must be called with `scope: 'workos-authkit'` — only plugins registered to that scope are mounted by the AuthKit `PluginArea`, which keeps block-editor extensions from accidentally activating on the login screen.

---

## 2. `workos_authkit_enqueue_assets`: per-profile CSS and JS

Use this WordPress action to ship CSS and/or JavaScript files alongside the AuthKit shell. The active `Profile` instance is passed as the only argument so you can gate by profile slug, organization, methods, or any other field.

### CSS-only example

```php
add_action( 'workos_authkit_enqueue_assets', function ( $profile ) {
    if ( $profile->get_slug() !== 'members-area' ) {
        return;
    }
    wp_enqueue_style(
        'members-area-login',
        plugins_url( 'login.css', __FILE__ ),
        [ 'workos-authkit' ],
        '1.0'
    );
} );
```

### JS example (analytics / tracking pixel)

```php
add_action( 'workos_authkit_enqueue_assets', function ( $profile ) {
    wp_enqueue_script(
        'login-analytics',
        plugins_url( 'analytics.js', __FILE__ ),
        [],
        '1.0',
        true
    );
} );
```

In `analytics.js`, wait for the AuthKit shell to mount before measuring:

```js
document.addEventListener( 'workos-authkit:mounted', ( event ) => {
    const { profileSlug } = event.detail;
    // your analytics call here
} );
```

The `workos-authkit:mounted` `CustomEvent` fires once, on `document`, after `createRoot().render()` completes. It carries `{ profileSlug }` in `event.detail`.

### Scoping CSS

Two stable selectors are available:

- **`#workos-authkit-root`** — the React mount point. Present in every entry point.
- **`body.workos-authkit-body`** — present on the full-page renderer (wp-login takeover, `/workos/login/{slug}` rewrite). Each profile also gets a `workos-profile-{slug}` body class. Add more via the `workos_authkit_body_classes` filter.

```css
body.workos-profile-members-area #workos-authkit-root .wa-card {
    background: #f7f3ff;
}
```

---

## 3. PHP filter reference

| Filter | Signature | Purpose |
| --- | --- | --- |
| `workos_authkit_branding` | `( array $branding, Profile $profile ) : array` | Override the resolved branding. Keys: `logo_url`, `primary_color`, `heading`, `subheading`. Useful for plugging in alternative logo fallbacks. |
| `workos_authkit_profile_data` | `( array $data, Profile $profile ) : array` | Mutate the JSON payload sent to the React shell. Use when a SlotFill plugin needs additional client-side config. |
| `workos_authkit_body_classes` | `( string[] $classes, Profile $profile ) : string[] ` | Add/remove CSS classes on the full-page renderer's `<body>`. |

| Action | Signature | Purpose |
| --- | --- | --- |
| `workos_authkit_enqueue_assets` | `( Profile $profile )` | Enqueue per-profile CSS/JS (see §2). |
| `workos_login_profile_saved` | `( Profile $profile )` | Fires after a profile is created or updated through the admin REST API. |
| `workos_login_profile_deleted` | `( Profile $profile )` | Fires after a profile is deleted through the admin REST API. The plugin's own `FrontendRoute` listens to this (and `_saved`) to invalidate its custom-path rewrite signature. |
| `workos_user_authenticated` | `( int $user_id, array $workos_response )` | Fires after a successful AuthKit sign-in completes a WordPress login. |

---

## Logo modes & fallback chain

Each Login Profile carries a `branding.logo_mode` field with three valid
values that decide *whether* and *which* logo the AuthKit shell renders.
Admins pick the mode under **WP Admin → WorkOS → Login Profiles →
(profile) → Branding → Logo**.

| `logo_mode` | Renderer behavior |
| --- | --- |
| `custom` | Uses the per-profile `branding.logo_attachment_id`. If the attachment is missing or invalid, falls through to `default`. |
| `default` *(default for new profiles)* | Resolves through the fallback chain below — no per-profile asset needed. |
| `none` | The logo `<img>` is omitted entirely. The login card has no header image. |

### Fallback chain (only consulted when `logo_mode = default`)

1. **WordPress Site Icon** — set under WP Admin → Settings → General → Site Icon.
2. **Bundled WordPress logo** — `admin_url( 'images/wordpress-logo.svg' )`, the same SVG core ships with WP itself. Guarantees an unbranded install still looks reasonable instead of showing a missing-image icon.

`logo_mode = custom` skips both fallback steps; if the attachment is
missing, the renderer drops back to the `default` chain rather than
omitting the logo silently.

Legacy rows that pre-date `logo_mode` (only `logo_attachment_id` was
stored) lazy-upgrade on first read: a non-zero attachment id implies
`custom`, anything else implies `default`. The previous behavior is
preserved without a migration.

### Overriding the chain from PHP

To force a specific URL regardless of mode, hook `workos_authkit_branding`:

```php
add_filter( 'workos_authkit_branding', function ( $branding, $profile ) {
    if ( $profile->get_slug() === 'members-area' && empty( $branding['logo_url'] ) ) {
        $branding['logo_url'] = plugins_url( 'members-logo.svg', __FILE__ );
    }
    return $branding;
}, 10, 2 );
```

Returning an empty string for `logo_url` from this filter has the same
effect as `logo_mode = none`: the renderer omits the `<img>` element.

---

## Custom URLs per profile

Every Login Profile has three entry points pointing at the same React
shell, so an extension can link to whichever fits its UX best:

1. **Canonical URL** — `https://yoursite.com/workos/login/{slug}/`. Always
   registered for every profile.
2. **Custom path** — tick **Use a custom URL path** in the editor and
   fill in e.g. `members` or `team/login`; `https://yoursite.com/members/`
   then mounts the same shell. `FrontendRoute` registers one rewrite
   rule per non-empty `custom_path` and triggers a single soft
   `flush_rewrite_rules( false )` only when the set actually changes
   (signature stored in `workos_custom_paths_signature`). Reserved core
   paths (`wp-admin`, `wp-includes`, `wp-content`, `wp-json`, `workos`,
   `feed`, `comments`, `trackback`) are rejected at save time;
   `login`, `admin`, and `signin` are intentionally allowed so the
   default profile can claim them.
3. **Shortcode** — `[workos:login profile="members"]` mounts the same
   shell anywhere a shortcode renders (post body, widget, page builder).
   Without the `profile` attribute it falls back to the reserved
   `default` profile.

The Login Profile editor's **Embed & URLs** section exposes copyable
input fields for all three so admins don't have to assemble URLs by
hand.

### Default-profile redirect from wp-login.php

When the reserved `default` profile owns a non-empty `custom_path`,
`/wp-login.php?action=login` 302s to `home_url('/' . $custom_path . '/')`
with every inbound `$_GET` preserved (so `redirect_to`, `interim-login`,
`reauth`, `instance`, `wp_lang`, etc. survive). The standard escape
hatches still short-circuit the redirect: `?loggedout`, `?fallback=1`
(when `allow_password_fallback` is on), `LoginBypass`, and any action
other than `login`.

### Already-signed-in visitors

Visitors who hit any AuthKit surface while logged in are routed by
`Auth\AuthKit\LoginRedirector::for_visitor( Profile $profile )` with
this precedence:

1. `profile.post_login_redirect` (admin's stored intent).
2. Validated `?redirect_to` query arg (passes `wp_validate_redirect()`).
3. `admin_url()` (WP convention).

`LoginTakeover` and `FrontendRoute` 302 the response. The
`[workos:login]` shortcode can't redirect mid-content, so it renders an
inline "You're already signed in as {name}. [Continue]" notice that
links to the same destination.

### Forwarding query args (`forward_query_args`)

Every Login Profile carries a `forward_query_args` boolean (default
`false`, surfaced as a checkbox under "Redirect after login"). When on,
inbound query args (e.g. `utm_source`, `ref`, custom params) get
appended to the post-login destination URL on both the already-signed-in
redirect (PHP, via `LoginRedirector::with_forwarded_args()`) and the
React-shell `handleSuccess` navigation (TS, via
`src/js/authkit/redirect.ts` `forwardQueryArgs()`).

The internal-arg allowlist is mirrored on both sides. These keys are
**always** stripped — never forwarded:

```
redirect_to, _wpnonce, interim-login, loggedout,
reauth, instance, wp_lang, action, fallback,
anything starting with "workos_"
```

Toggle this on for marketing flows that need attribution to survive
through login; leave it off (the default) when you want the destination
URL to stay clean.
