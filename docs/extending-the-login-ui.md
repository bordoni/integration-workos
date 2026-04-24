# Extending the Login UI

The `integration-workos` plugin renders its login screen as a single-page React application (the **AuthKit shell**). This document describes the three public extension surfaces:

1. **WordPress SlotFill** — inject React elements into named slots in the login UI.
2. **`workos_authkit_enqueue_assets` action** — enqueue per-profile CSS and/or JavaScript files alongside the AuthKit bundle.
3. **PHP filters** — last-mile mutation of the data the shell renders.

The same surfaces are available everywhere the AuthKit shell appears: the wp-login.php takeover, the `[workos_login_v2]` shortcode, the `workos/login-form` block, and the `/workos/login/{slug}` rewrite.

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
| `workos.authkit.methodPicker.beforeMethods` | MethodPicker only — above the method buttons |
| `workos.authkit.methodPicker.afterMethods` | MethodPicker only — below the method buttons |

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
| `workos_user_authenticated` | `( int $user_id, array $workos_response )` | Fires after a successful AuthKit sign-in completes a WordPress login. |

---

## Logo fallback chain

The AuthKit shell renders a logo at the top of every flow card. The URL is resolved at render time in this order:

1. **Per-profile logo** — set under WP Admin → WorkOS → Login Profiles → (profile) → Branding → Logo. Stored as a WordPress media attachment ID.
2. **WordPress Site Icon** — set under WP Admin → Settings → General → Site Icon. Used when no per-profile logo is set.
3. **No logo** — if neither is set, the logo `<img>` is omitted entirely.

To override the chain, hook `workos_authkit_branding`:

```php
add_filter( 'workos_authkit_branding', function ( $branding, $profile ) {
    if ( $profile->get_slug() === 'members-area' && empty( $branding['logo_url'] ) ) {
        $branding['logo_url'] = plugins_url( 'members-logo.svg', __FILE__ );
    }
    return $branding;
}, 10, 2 );
```
