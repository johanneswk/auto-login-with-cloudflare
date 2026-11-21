=== Auto Login for Cloudflare Access ===
Contributors: johanneswk
Tags: cloudflare access, sso, login, zero trust, jwt
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Single sign-on for WordPress behind Cloudflare Access: users who passed Access are logged in automatically by email address.

== Description ==

Protect `/wp-admin` (or your whole site) with a Cloudflare Access self-hosted application. When a visitor has signed in through Access, this plugin verifies the Access token (JWT) that Cloudflare sends with every request and logs the visitor in to the WordPress account with the same email address.

* No passwords: users sign in with any identity provider configured in Cloudflare Access.
* Strict token validation: RS256 signature against Cloudflare's published keys, expiry, audience (AUD) and issuer are all checked.
* Handles Cloudflare's signing key rotation automatically.
* Optional redirect of `wp-login.php` to Cloudflare Access, and logout from Access when logging out of WordPress.
* Built-in debug log on the settings page for troubleshooting.
* Settings can be locked in `wp-config.php`.

Users are never created: the email address must already belong to a WordPress user.

This plugin is a fork of "Auto Login with Cloudflare" by Kan-Ru Chen, which is no longer available. When you activate this plugin, the settings of that plugin are copied over; deactivate and delete the old plugin afterwards.

This plugin is not affiliated with or endorsed by Cloudflare, Inc. Cloudflare and Cloudflare Access are trademarks of Cloudflare, Inc.

Development happens on [GitHub](https://github.com/johanneswk/auto-login-for-cloudflare-access).

== Installation ==

1. In Cloudflare Zero Trust, create a self-hosted Access application that protects at least `/wp-admin` of your site. See the [Cloudflare documentation](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/).
2. Install and activate the plugin.
3. Go to **Settings → Auto Login for Cloudflare Access** and enter:
   * **Auth domain**: your team domain, for example `yourteam.cloudflareaccess.com`.
   * **Application audience (AUD) tag**: found in the Access application's overview.
4. Optionally enable **Redirect login page** so `wp-login.php` sends users through Cloudflare Access.

Instead of using the settings page you can define the values in `wp-config.php`. Defined values take precedence and cannot be changed from the admin:

`define('WP_CF_ACCESS_AUTH_DOMAIN', 'yourteam.cloudflareaccess.com');`
`define('WP_CF_ACCESS_JWT_AUD', 'your-aud-tag');`
`define('WP_CF_ACCESS_REDIRECT_LOGIN', true);`
`define('WP_CF_ACCESS_DEBUG_MODE', false);`

== Frequently Asked Questions ==

= A user is not logged in. How do I find out why? =

Enable debug mode on the settings page and reload a protected page. Every step of the validation is shown in the debug log. Authentication failures are also written to the PHP error log.

= How do I send the WordPress login page to Cloudflare Access? =

Enable **Redirect login page**. Visitors to `wp-login.php` are redirected to `/wp-admin/`, where Cloudflare Access asks them to sign in. Make sure your Access application protects `/wp-admin`, otherwise this causes a redirect loop.

= Is my server still reachable without Cloudflare? =

That depends on your hosting. Tokens are always verified, but for the best protection only allow traffic to your server from Cloudflare, for example with Cloudflare Tunnel or a firewall allowlist.

= Does the plugin create WordPress users? =

No. The email address from Cloudflare Access must match an existing user.

= What data does the debug log store? =

Up to 100 recent messages, including email addresses, user names and IP addresses, for at most one week. Clear it with the **Clear Debug Logs** button. Everything is removed when the plugin is deleted.

== Screenshots ==

1. Settings page with debug mode and the debug log.

== External services ==

This plugin connects to Cloudflare Access, a service by Cloudflare, Inc., to verify that a visitor has signed in.

When a request carries a Cloudflare Access token, the plugin downloads the public signing keys of your Access team from `https://<your auth domain>/cdn-cgi/access/certs`. The keys are cached for up to one hour. Only this request is made; no visitor or site data is sent to Cloudflare by the plugin. Your site is already behind Cloudflare Access, which handles the sign-in itself.

* Cloudflare Website and Online Services Terms of Use: https://www.cloudflare.com/website-terms/
* Cloudflare Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Changelog ==

= 1.0.0 =
* First release in the WordPress.org directory, continuing "Auto Login with Cloudflare" by Kan-Ru Chen.
* Settings of the original plugin are copied over on activation.
* Strict Cloudflare Access token validation with firebase/php-jwt 7.2.2: RS256 signature, required expiry, audience, issuer and email.
* Automatic handling of Cloudflare signing key rotation and outages.
* Validated settings, debug log on the settings page, and full cleanup when the plugin is deleted.
