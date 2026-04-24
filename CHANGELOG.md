# Changelog

## [1.0.0] - 2026-04-23

### Added

#### Custom AuthKit — WordPress-hosted login experience

A React login shell mounted inside WordPress, replacing the redirect to
WorkOS's hosted AuthKit page for first-factor methods that don't
inherently require one. All WorkOS API calls stay server-side; the
browser only ever talks to `/wp-json/workos/v1/auth/*`.

- **Login Profiles** — new `workos_login_profile` CPT + React admin
  editor at WorkOS → Login Profiles. Each profile picks enabled sign-in
  methods, pins an organization server-side, toggles signup /
  invitation / password-reset flows, configures MFA policy and factor
  allowlist, and carries branding (logo, primary color, headings).
  Reserved `default` profile drives wp-login.php takeover.
- **wp-login.php takeover** — `action=login` renders the React shell.
  `logout`, `register`, `lostpassword`, `resetpass`, `confirmaction`,
  `postpass`, `?fallback=1`, `?workos=0` still pass through to WP
  defaults.
- **Entry points** — `[workos_login_v2]` shortcode, `workos/login-form`
  block, and `/workos/login/{profile}` rewrite all render the same
  shell.
- **Profile routing rules** — ordered `redirect_to` glob /
  `referrer_host` / `user_role` matchers pick which profile applies to
  a request; first match wins, falls back to the `default` profile.
- **Sign-in methods** — email + password, magic code, social OAuth
  (Google, Microsoft, GitHub, Apple), passkey. Legacy AuthKit redirect
  mode remains per-profile selectable for SSO (SAML/OIDC).
- **Self-serve sign-up** — in-app account creation + email code
  verification, gated by `signup.enabled` on the profile.
- **Invitation acceptance** — clicking a WorkOS invitation email lands
  in the React shell, which consumes the token atomically via WorkOS's
  invitation-token grant (caller cannot substitute email or force
  verified state).
- **In-app password reset** — request + confirm handled by the React
  shell; reset links route to `/wp-login.php?workos_action=reset-password`
  which the takeover hands to the confirm step.
- **MFA** — TOTP + SMS + WebAuthn/passkey. Factor enrollment,
  challenge, and verify are all in-app. Profile `mfa.enforce=always`
  rejects single-step logins; `mfa.factors[]` allowlist is applied at
  challenge time.
- **WorkOS Radar** integration — browser SDK produces an action token
  on sensitive interactions; the token rides along as
  `X-WorkOS-Radar-Action-Token` on every server-side auth call, so
  WorkOS can score risk equivalently to the hosted AuthKit.
- **Public REST surface** at `/wp-json/workos/v1/auth/*`:
  `password/{authenticate,reset/start,reset/confirm}`,
  `magic/{send,verify}`, `signup/{create,verify}`,
  `invitation/{token,accept}`, `oauth/authorize-url`,
  `mfa/{challenge,verify,factors,totp/enroll,sms/enroll,factor/delete}`,
  `session/{refresh,logout}`, `nonce`. Every mutation is guarded by a
  profile-scoped WP nonce + per-IP and per-email rate limits.
- **Admin REST** at `/wp-json/workos/v1/admin/profiles` (gated by
  `manage_options`) — full CRUD for Login Profiles consumed by the
  React admin editor. Adds a sibling
  `GET /admin/profiles/organizations` endpoint that returns the
  active environment's WorkOS organizations (reusing the same
  `workos_organizations_cache_{env}` transient as the settings page)
  so the editor can present a searchable organization picker instead
  of asking admins to paste `org_01…` IDs by hand.
- **Pinned-organization picker** in the Login Profile editor — a
  select populated from WorkOS with a "Custom ID…" escape hatch for
  orgs that aren't in the fetched list (legacy or paginated-out
  records keep working). Falls back to a plain text input if WorkOS
  is unconfigured or the API is unreachable. The Login Profiles list
  now shows organization names instead of raw IDs.

#### Browser internationalization

- Every user-facing string in the React/TS/JS bundles (AuthKit shell,
  Login Profile editor, onboarding, role mapping, redirect URLs,
  login button block + frontend) now goes through
  `@wordpress/i18n`'s `__`/`_n`/`sprintf` with the
  `integration-workos` text domain. Every enqueued script calls
  `wp_set_script_translations()` (block editor translations come
  from `block.json` `textdomain`), and the previously unbundled
  `login-button-frontend.js` now ships through webpack so its
  `wp-i18n` dependency is declared in its `.asset.php`.

#### Login UI extensibility

- **WordPress SlotFill** — the AuthKit React shell mounts inside a
  `SlotFillProvider` + `PluginArea scope="workos-authkit"`. Nine named
  slots (`workos.authkit.beforeHeader`, `afterHeader`, `beforeForm`,
  `afterForm`, `afterPrimaryAction`, `beforeFooter`, `afterFooter`,
  `methodPicker.beforeMethods`, `methodPicker.afterMethods`) let other
  plugins inject React elements via `registerPlugin()` + `<Fill>`.
  Each slot's `fillProps` carries the active `step`, `profileSlug`,
  enabled `methods`, and the current `flow`.
- **`workos_authkit_enqueue_assets` action** — fires on every render
  with the active `Profile` so extenders can `wp_enqueue_style()` /
  `wp_enqueue_script()` per-profile CSS/JS, depending on the
  `workos-authkit` handle for ordering.
- **New PHP filters** — `workos_authkit_branding` (override the
  resolved branding before it lands in the data-profile JSON),
  `workos_authkit_profile_data` (mutate the JSON payload sent to the
  React shell), `workos_authkit_body_classes` (extend body CSS classes
  on the full-page renderer; `workos-profile-{slug}` is added by
  default).
- **`workos-authkit:mounted` DOM event** — dispatched on `document`
  after the React shell mounts, carrying `{ profileSlug }` in
  `event.detail` so non-React extenders can observe the lifecycle.
- **Logo control** — Login Profile editor now exposes a logo picker
  (uses `wp.media`). When no per-profile logo is set, the AuthKit shell
  falls back to the WordPress Site Icon (Settings → General).
- **`docs/extending-the-login-ui.md`** — developer guide covering all
  three extension surfaces with working examples.

#### Public API for third-party integrations

- **`WorkOS\User`** — read-only helper class for querying WorkOS state
  on a WP user without reaching into `get_user_meta()` directly. Methods:
  `is_sso()`, `has_active_session()`, `get_workos_id()`,
  `get_access_token()`, `get_refresh_token()`, `get_session_id()`,
  `get_organization_id()`, `snapshot()`. All accept a `$user_id` argument
  that defaults to the current user and handle unauthenticated requests
  safely. Meta keys are exposed as class constants (`META_WORKOS_ID`,
  `META_ACCESS_TOKEN`, etc.) for SQL/REST callers.
- **Global function shortcuts** — `workos_is_sso_user()`,
  `workos_has_active_session()`, `workos_get_user_id()`,
  `workos_get_access_token()` wrap the class methods for terse
  procedural code.

#### Base platform

- AuthKit redirect and headless API authentication flows.
- Directory sync (SCIM) user provisioning and deprovisioning.
- Role mapping between WorkOS organization roles and WordPress roles.
- Organization management with multi-site support.
- Audit logging bridge to WorkOS Audit Logs.
- REST API authentication via WorkOS access tokens (JWT).
- Admin settings page with full configuration UI.
- Webhook receiver with signature verification.

### Changed

- Browser code (`src/js/authkit/*`, `src/js/admin-profiles/*`) is
  TypeScript + TSX. `@wordpress/scripts` v30 transpiles `.ts`/`.tsx`
  natively; `bun run lint:ts` runs strict type checking.
- `Auth\AuthKit\FrontendRoute::register_rewrite()` is the canonical
  registration point for the `/workos/login/{profile}` rule; called
  both from activation and the `init` hook (same convention as
  `Auth\Login::register_rewrite()`).

### Security

Findings surfaced by the phase-6 `/security-review` on the custom
AuthKit branch and fixed before launch:

- **Signature-verification bypass in `REST\TokenAuth` removed.** The
  previous lazy-refresh path trusted the `sub` claim of an unverified
  JWT to pick a user and exchange their stored refresh token. Clients
  that need to rotate tokens must now use `POST /auth/session/refresh`,
  which is authenticated via the WP auth cookie.
- **Invitation acceptance no longer allows account takeover.** The
  endpoint now issues a single atomic call to WorkOS with
  `grant_type=urn:workos:oauth:grant-type:invitation_token`. Callers
  can no longer substitute an arbitrary email or force
  `email_verified=true`; the invitation token is authoritatively bound
  to its original recipient by WorkOS.
- **MFA factor delete enforces ownership.** `POST /auth/mfa/factor/delete`
  verifies the factor belongs to the caller before forwarding to
  WorkOS; mismatched factor IDs return 404.
- **Profile MFA policy is enforced at login.** `LoginCompleter` rejects
  single-step success when `mfa.enforce=always`, and denies pending
  factors whose `type` is not in the profile's `mfa.factors` allowlist.
