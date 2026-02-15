=== WorkOS Identity ===
Contributors: liquidweb
Tags: sso, identity, workos, authentication, directory-sync
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.

== Description ==

WorkOS Identity integrates your WordPress site with [WorkOS](https://workos.com) for enterprise-grade identity management:

* **Single Sign-On (SSO)** — AuthKit redirect and headless API authentication flows.
* **Directory Sync** — Automatic user provisioning and deprovisioning via SCIM.
* **Role Mapping** — Map WorkOS organization roles to WordPress roles.
* **Organization Management** — Multi-tenant organization support.
* **Audit Logging** — Forward WordPress events to WorkOS Audit Logs.
* **REST API Authentication** — Verify WorkOS access tokens for headless/API usage.

== Installation ==

1. Upload the `workos` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WorkOS > Settings** and enter your API Key and Client ID from the [WorkOS Dashboard](https://dashboard.workos.com).
4. Configure your webhook endpoint in the WorkOS Dashboard using the URL shown on the settings page.

== Frequently Asked Questions ==

= Where do I get my API credentials? =

Sign up at [workos.com](https://workos.com) and find your API Key and Client ID in the dashboard.

= Can users still log in with passwords? =

Yes, if "Password Fallback" is enabled in settings. Users can access the standard login form via `?fallback=1`.

== Changelog ==

= 1.0.0-dev =
* Initial release with SSO, directory sync, role mapping, organizations, audit logging, and REST token auth.
