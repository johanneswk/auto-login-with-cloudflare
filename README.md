# Auto Login with Cloudflare

**Contributors:** [johanneswk](https://github.com/johanneswk), [kanru](https://github.com/kanru)  
**Tags:** cloudflare, jwt, login, sso, composer  
**Requires at least:** WordPress 5.0  
**Tested up to:** 6.8  
**Requires PHP:** 8.0  
**Stable tag:** 2.0.0  
**License:** GPL-2.0+  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.txt

A simple way to allow single sign-on to your WordPress site when using Cloudflare Access. Now Composer-based and maintained.

---

## Description

Enable Cloudflare Access self-hosted application to protect your `/wp-admin` folder. Add your auth domain and aud settings from Cloudflare Access. Authenticated users will be automatically logged in to WordPress if their email address matches.

Follow [Cloudflare documentation](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/) to set up Access.

You can also define configs in `wp-config.php`:

```php
define('WP_CF_ACCESS_AUTH_DOMAIN', 'yourdomain.cloudflareaccess.com');
define('WP_CF_ACCESS_JWT_AUD', 'examplef2nat0rkar2866wn829a0x2ztdg');
define('WP_CF_ACCESS_REDIRECT_LOGIN', true);
```

> This plugin is not affiliated with nor developed by Cloudflare. All trademarks, service marks and company names are the property of their respective owners.

---

## Frequently Asked Questions

### How do I redirect the WP login page at `/wp-login.php` to Cloudflare Access?

Enable the "Redirect login page" option and all future logins will be redirected to `/wp-admin` and trigger Access authentication.

### Why do I get an infinite redirect loop after enabling the redirect login page option?

The option assumes that the `/wp-admin` folder is protected by Cloudflare Access. If the folder is not protected, then auto-login will fail and redirect back to the login page, causing the redirect loop.

### How do I install the plugin?

You can download the latest `.zip` from the [GitHub releases page](https://github.com/johanneswk/auto-login-with-cloudflare/releases) and upload it via the WordPress plugin installer. No Composer required for end users.

---

## Screenshots

1. Settings page for Cloudflare Access integration

---

## Changelog

### 2.0.0
- This release marks the start of maintenance under a new fork by Johannes Kistemaker, based on the original plugin by Kan-Ru Chen.
- Add support for php-jwt v6.11.x and Composer-based dependency management
- Fix error on plugin activation with php-jwt v6+ by switching to Composer autoloading
- Improve error handling and settings page accessibility
- Remove legacy git submodule and cog.toml/manifest.txt files
- Remove "Buy me a coffee" link from plugin actions
- Update plugin metadata: author, links, and version
- Update namespace and codebase for clarity and maintainability
- Clean up unused code and improve code consistency
- Update documentation and changelog to reflect new fork and maintenance
- Add GitHub Actions autobuild: automatically creates a prerelease zip archive (`auto-login-with-cloudflare-{version}.zip`) and GitHub release on every push for easier testing and installation

### 1.1.4
- Fix redirect issue for non-default wp-admin urls

### 1.1.3
- Tested with WordPress 6.1.1

### 1.1.2
- Tested with WordPress 5.9
- Fixed errors when activated in multi-site enabled installation

### 1.1.1
- Show an error message when user does not exist to prevent redirect loop

### 1.0.0
- Tested with WordPress 5.8
- Update php-jwt to 5.4
- Stable. No major change planned

### 0.9.3
- Tested with WordPress 5.7

### 0.9.2
- Update minimum requirements
- Update php-jwt to 5.2.1

### 0.9.0
- First beta release.