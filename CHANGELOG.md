# Changelog

## [1.0.5] - 2026-05-18

### Added

- **WorkOS → Users admin page** ([CONS-273](https://linear.app/nexcess/issue/CONS-273/re-enable-workos-emails-for-affected-portal-users))
  — new submenu under WorkOS that mounts a paginated, searchable React
  list of WorkOS users for the active environment. Each row exposes an
  "Open in WorkOS" deep-link that takes the admin straight to the user's
  Dashboard page (`https://dashboard.workos.com/{env}/users/{id}/details`),
  where the per-user "Re-enable email" action lives. Gated by
  `manage_options`. Backed by `GET /wp-json/workos/v1/admin/users`, which
  proxies `Api\Client::list_users()` with sanitized `limit` (1..100),
  cursor (`after`/`before`), `email` substring, and `organization_id`
  pass-through, and enriches each user record with a server-computed
  `dashboard_url` so the React side doesn't reconstruct it.

  **Why list-only:** WorkOS exposes the "Re-enable email" action only
  through the Dashboard — there is no public REST endpoint or webhook
  event for email suppression / bounce state as of this release
  (verified against the WorkOS API reference, the User schema, the
  Events catalogue, and the public workos-node / -python / -ruby / -go
  SDK sources). This page builds the foundation; once WorkOS ships an
  API, a row + bulk action can be wired in without reworking the UI.

## [1.0.4] - 2026-05-14

### Fixed

- **Logged-out screen leaks classic wp-login** (#18) — both
  `LoginTakeover::should_takeover()` and
  `Login::maybe_redirect_to_authkit()` short-circuited on
  `?loggedout=true`, so the native "you have been logged out" screen
  rendered with the wp-login username/password field. Legacy
  customers misread that as a still-working classic sign-in and kept
  trying to use it even though their credentials live in WorkOS. The
  bypasses are removed; with a `custom_path`-equipped default
  profile, `/wp/wp-login.php?loggedout=true` now 302s to
  `/login/?loggedout=true` via the existing `redirect_to_custom_path`
  forwarder. The `?fallback=1`, `?workos=0`, and
  `action=logout|lostpassword|rp|...` bypasses are unchanged.
- **Password reset URLs HTML-encoded in WorkOS emails** (#17) —
  `wp_login_url()` runs through the `login_url` / `home_url` filters;
  a host-side filter piped it through `esc_url()`, encoding `&` →
  `&amp;`. WorkOS emailed the URL verbatim, so reset links arrived
  as `?workos_action=…&amp;profile=…&amp;token=…` and broke. The
  plugin now decodes HTML entities in `build_password_reset_url()`
  before POSTing to WorkOS. A regression wpunit test fails without
  the fix.
- **Infinite login redirect loops** (#15) — auth redirects now send
  no-cache / no-store headers so a cached redirect response cannot
  trap the browser in a redirect cycle when the user navigates back
  to a previously visited URL.
- **Unchecking auth methods or MFA factors did not persist** (#14) —
  `update_profile` in `Admin/LoginProfiles/RestApi.php` ran
  `array_replace_recursive($existing, $params)` so partial PUTs only
  needed to send touched fields. For numerically-indexed lists that
  function merges by key and never removes entries that exist in the
  base but are absent from the override, so unchecking `oauth_google`
  on a profile with `['password', 'magic_code', 'oauth_google']` left
  `oauth_google` at index 2 of the merged result. After the merge,
  `methods` and `mfa.factors` are now explicitly overwritten from the
  incoming payload when the client sent them — `array_key_exists()`
  is used (not `isset()`) so an empty array (uncheck-everything save)
  is honored. Scalars and associative branches like `signup`,
  `branding`, and the `mfa` envelope itself were unaffected.

### Tests

- Adds two cases in `AuthKitLoginProfilesRestApiTest`:
  `test_update_methods_payload_replaces_existing` and
  `test_update_mfa_factors_payload_replaces_existing` — start with
  multiple values, PUT with fewer, assert dropped values are gone in
  both the response and a fresh repository read; the second also
  asserts the sibling `mfa.enforce` survives the partial merge.
- Adds a wpunit regression covering HTML-entity decoding in
  `build_password_reset_url()`.

## [1.0.3] - 2026-05-12

### Fixed

- **`organization_selection_required` recovery** — AuthKit login flows
  (magic code, password, MFA verify, invitation accept) now recover
  transparently when WorkOS responds with the
  `organization_selection_required` error. `LoginCompleter::complete()`
  accepts the WP_Error directly, looks up the Profile's pinned
  `organization_id` (falling back to `Config::get_organization_id()`),
  and re-authenticates via the
  `urn:workos:oauth:grant-type:organization-selection` grant. Users no
  longer see the WorkOS-side "The user must choose an organization to
  finish their authentication." message when their Login Profile has
  an org pinned.
- **Auto-enroll for legacy users** — when the pinned org is missing
  from WorkOS's candidate list AND a matching local WP user already
  exists, the plugin self-heals by creating the WorkOS organization
  membership (`POST /user_management/organization_memberships`) and
  retrying the org-selection grant. The flow strictly requires the
  authenticated `user_id` to be present in the WorkOS error body — if
  it's absent (or no matching WP user exists), the request is rejected
  with `workos_authkit_pinned_org_mismatch` instead of guessing via an
  email lookup that can collide on shared addresses. Successful
  enrollments and `entity_already_exists` short-circuits are logged
  via `workos_log()` so prod incidents have a breadcrumb.
- **OAuth callback shares the recovery** — `/workos/callback`
  (`Login::handle_callback`) now routes the `authenticate_with_code`
  result through `LoginCompleter::complete()`, so it gets the same
  `organization_selection_required` recovery, MFA gating, and
  post-login bookkeeping as the AuthKit REST endpoints. Legacy
  AuthKit-redirect callbacks (no profile slug in `state`) pass the
  new `$honor_profile_redirect = false` flag so the state-supplied
  `redirect_to` still wins — the default profile's
  `post_login_redirect` will not silently override it for that flow.

### Tests

- Adds `AuthKitLoginCompleterOrgSelectionTest` (9 wpunit cases)
  covering the recovery branches: silent retry when the pinned org is
  in the candidate list, self-heal via membership creation for
  pre-existing WP users, idempotent `entity_already_exists` handling,
  refusal when the error body omits `user_id`, refusal when no
  matching WP user exists, the no-pinned-org bail, the legacy
  `$honor_profile_redirect = false` contract, the default
  profile-wins contract, and pass-through of unrelated WorkOS errors.
  Plus a Client-level case asserting the new
  `authenticate_with_organization_selection` grant body.

## [1.0.2] - 2026-05-11

### Added

- **WordPress password fallback** — when WorkOS rejects a password, the
  auth endpoint can now retry against `wp_authenticate()` to cover users
  whose passwords were never synced to WorkOS (e.g. accounts that existed
  before the plugin was installed). On success the WP user is linked /
  synced to WorkOS and, by default, the password is written through to
  WorkOS so future logins authenticate directly. A new
  **Require Email Confirmation on Fallback** setting (option
  `wp_password_fallback_email_confirmation`) switches the post-fallback
  step to a magic-code email instead of syncing the plaintext password.
  Gated by the existing `allow_password_fallback` setting (default on).
- **wp-config.php constant seeder** — defining `WORKOS_*` (or
  env-scoped `WORKOS_{PRODUCTION|STAGING}_*`) constants in `wp-config.php`
  now seeds the corresponding settings into the database on boot, so the
  admin UI reflects them and runtime reads stay on the existing options
  layer. Covers string credentials (`WORKOS_CLIENT_ID`, `WORKOS_API_KEY`,
  …), the new boolean toggles (`WORKOS_ALLOW_PASSWORD_FALLBACK`,
  `WORKOS_WP_PASSWORD_FALLBACK_EMAIL_CONFIRMATION`), and array values
  (`WORKOS_REDIRECT_URLS`). Skipped via a stored hash check
  (`workos_constants_hash` option) when nothing has changed — steady-state
  cost is one autoloaded `get_option()` call per request.

### Fixed

- **REST nonce header** — auth endpoints under `/wp-json/workos/v1/auth/*`
  now read the nonce from `X-WorkOS-Nonce` instead of `X-WP-Nonce`. The
  prior name collided with the header WP core and other plugins consume,
  which could cause the wrong nonce to be validated when multiple
  scripts attached to the same request. The bundled React shell sends
  the new header automatically; external clients calling these endpoints
  directly need to update their header name.

### Tests

- Adds `ConfigSyncConstantsTest` (11 wpunit cases) covering the new
  constant seeder: string / bool / array maps, env-specific override
  precedence, empty-string skip, the hash short-circuit, and the
  trailing in-memory cache reset. The fixture defines `WORKOS_*`
  constants in `setUp()`; because PHP cannot undefine a constant and
  Codeception 5 ignores PHPUnit's process-isolation directives, the
  class is tagged `@group constants` and CI now runs it as a dedicated
  `codecept run` invocation. Default `slic run wpunit` skips the group.

## [1.0.1] - 2026-05-01

### Added

- Organization tab — manual **Refresh** button next to the organization
  dropdown that re-fetches the WorkOS organization list on demand via the
  admin REST endpoint (`GET /workos/v1/admin/profiles/organizations?refresh=1`),
  bypassing the 5-minute transient cache. The dropdown is visually blocked
  with a spinner while the request is in flight; the previously selected
  organization is preserved across the refresh when it still exists.
- `?refresh` query parameter on
  `GET /wp-json/workos/v1/admin/profiles/organizations` — drops the shared
  `workos_organizations_cache_{env}` transient before fetching from WorkOS.

### Fixed

- Organization tab — fix "Save Settings" being blocked by an invalid hidden
  form control (`org_name`). The Create Organization Thickbox modal is now
  rendered at `admin_footer` so its inner `<form>` no longer nests inside
  the outer settings form, preventing HTML5 constraint validation on the
  hidden required field from aborting the parent form's submission.
- Active environment — `workos_active_environment` is now the single source
  of truth for the active environment. The admin Settings UI wrote the
  user's selection to a standalone option while the runtime auth flow read
  from `workos_global['active_environment']`, so picking "Production"
  saved successfully but the runtime kept loading staging credentials and
  redirecting users to the staging AuthKit. `Config::get_active_environment()`
  / `set_active_environment()` now read and write the standalone option,
  with a one-time migration (db_version 2 → 3) that copies any legacy
  value out of `workos_global` and unsets it.

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
- **Entry points** — `[workos:login]` shortcode and
  `/workos/login/{profile}` rewrite both render the same shell.
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
- **Per-profile custom paths** — non-default profiles can declare an
  arbitrary URL path (`/members`, `/team/login`, …) on top of the
  canonical `/workos/login/{slug}` rewrite. `FrontendRoute` registers
  one `add_rewrite_rule` per non-empty `custom_path` and triggers a
  single soft `flush_rewrite_rules( false )` only when the set
  changes (signature stored in the `workos_custom_paths_signature`
  option). Reserved segments (`wp-admin`, `wp-includes`, `workos`,
  `login`, …) and shape-unsafe input are rejected at save time.
- **Logo modes** — `branding.logo_mode` accepts `default`, `custom`,
  or `none`. The render-time fallback chain is per-profile attachment
  (`custom`) → WordPress Site Icon → bundled core
  `admin_url('images/wordpress-logo.svg')` (`default`) → no logo
  rendered (`none`). Legacy rows that stored only an attachment id
  lazy-upgrade to `custom` on first read; the editor exposes a
  three-way toggle so admins can hide the logo, opt back into the
  fallback chain, or pick a custom image.
- **WP-admin color palette presets** — the primary-color picker in
  the Login Profile editor defaults its swatches to the WordPress
  admin color scheme so new profiles match standard WP look out of
  the box without hand-picking hex values.
- **Embed & URLs section in the Login Profile editor** — copyable
  `<input>` fields for the canonical `/workos/login/{slug}` URL, the
  optional custom-path URL, and the `[workos:login profile="…"]`
  shortcode. `Profile::to_editor_array()` exposes `login_url` and
  `custom_url` server-side so subdir installs render the correct
  values without client-side stitching.
- **`workos.authkit.belowCard` SlotFill slot** — tenth slot, renders
  outside the login card (full-width, below the form). The default
  fill emits the standard wp-login.php "Lost your password?" /
  "← Go to {Site}" links so the takeover matches core out of the
  box; a registered Fill replaces the default when present. See
  `docs/extending-the-login-ui.md` for the full slot inventory.
- **`workos_login_profile_deleted` action** — fires from
  `ProfileRepository::delete()` after a profile is removed via the
  admin REST API. Mirrors `workos_login_profile_saved` and is what
  `FrontendRoute` listens to in order to invalidate its custom-path
  signature.
- **Default-profile wp-login.php redirect** — when the reserved
  `default` profile owns a non-empty `custom_path`, hitting
  `/wp-login.php?action=login` now 302s to `home_url('/' . $custom_path . '/')`
  with every inbound `$_GET` arg preserved (so `redirect_to`,
  `interim-login`, `reauth`, `instance`, `wp_lang`, etc. survive the
  bounce). Existing escape hatches (`?loggedout`, `?fallback=1`,
  `LoginBypass`, non-`login` actions) short-circuit before the
  redirect. `RESERVED_PATHS` dropped `login` and `admin` so the
  obvious clean-URL choices are usable.
- **Custom-path toggle UX** — the editor hides the Custom Path text
  field behind a "Use a custom URL path" checkbox; flipping it off
  clears the stored value. Available on every profile, default
  included.
- **Already-signed-in guard** — visitors who hit
  `/wp-login.php?action=login`, `/workos/login/{slug}`, or any custom
  path while logged in are 302'd straight to their post-login
  destination through the new `Auth\AuthKit\LoginRedirector` helper
  (precedence: profile `post_login_redirect` → validated
  `redirect_to` → `admin_url()`). The `[workos:login]` shortcode
  can't redirect mid-content, so it renders an inline
  "You're already signed in as {name}. [Continue]" notice pointing
  at the same destination.
- **`forward_query_args` per-profile toggle** (default `false`) —
  appends inbound query args (`utm_*`, `ref`, custom params) to the
  post-login destination URL. WP / plugin internals (`redirect_to`,
  `_wpnonce`, `interim-login`, `loggedout`, `reauth`, `instance`,
  `wp_lang`, `action`, `fallback`, anything starting with `workos_`)
  are always stripped. Server-side via `LoginRedirector`, client-side
  via `src/js/authkit/redirect.ts` `forwardQueryArgs()` (same
  allowlist mirrored on both ends). Surfaces in the editor as a
  checkbox under "Redirect after login".

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
