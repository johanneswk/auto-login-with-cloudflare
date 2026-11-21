<?php

/**
 * Plugin Name:        Auto Login for Cloudflare Access
 * Plugin URI:         https://github.com/johanneswk/auto-login-for-cloudflare-access
 * Description:        Log users in to WordPress automatically when they have signed in through Cloudflare Access.
 * Version:            1.0.0
 * Requires at least:  6.3
 * Requires PHP:       8.0
 * Author:             Johannes Kistemaker
 * Author URI:         https://github.com/johanneswk/
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        auto-login-for-cloudflare-access
 *
 * Based on "Auto Login with Cloudflare" by Kan-Ru Chen (https://github.com/kanru/auto-login-with-cloudflare).
 * Copyright (C) 2021 Kan-Ru Chen
 * Copyright (C) 2025-2026 Johannes Kistemaker
 *
 * Not affiliated with or endorsed by Cloudflare, Inc.
 *
 * @package AutoLoginWithCloudflare
 */

namespace AutoLoginWithCloudflare;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/settings.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

// Optional overrides in wp-config.php (names kept for compatibility with earlier versions):
// define('WP_CF_ACCESS_AUTH_DOMAIN', 'yourteam.cloudflareaccess.com');
// define('WP_CF_ACCESS_JWT_AUD', '');
// define('WP_CF_ACCESS_REDIRECT_LOGIN', true);
// define('WP_CF_ACCESS_DEBUG_MODE', true);

const CACHE_KEY     = 'AutoLoginWithCloudflare_jwks';
const DEBUG_LOG_KEY = 'AutoLoginWithCloudflare_debug_logs';

// Cloudflare Access signs with RS256 only; nothing else is ever accepted.
const JWT_ALG = 'RS256';
// Tolerated clock skew in seconds for exp/nbf/iat.
const JWT_LEEWAY = 5;
// Minimum age of the cached key set before an unknown kid may trigger a refetch.
const JWKS_MIN_REFRESH = 5 * 60;

function get_config($constant, $option)
{
    return defined($constant) ? constant($constant) : get_option($option);
}

/**
 * Reduce input such as "https://Team.cloudflareaccess.com/" to a bare hostname.
 * Returns '' when it is not a plausible public hostname.
 */
function normalize_domain($value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }
    $host = wp_parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    return is_string($host) && preg_match('/^(?:' . $label . '\.)+[a-z]{2,63}$/', $host) ? $host : '';
}

function get_auth_domain(): string
{
    return normalize_domain(get_config('WP_CF_ACCESS_AUTH_DOMAIN', 'AutoLoginWithCloudflare_auth_domain'));
}

function get_jwt_aud()
{
    return get_config('WP_CF_ACCESS_JWT_AUD', 'AutoLoginWithCloudflare_aud');
}

function get_redirect_login()
{
    return get_config('WP_CF_ACCESS_REDIRECT_LOGIN', 'AutoLoginWithCloudflare_redirect_login_page');
}

function get_debug_mode()
{
    return get_config('WP_CF_ACCESS_DEBUG_MODE', 'AutoLoginWithCloudflare_debug_mode');
}

function is_configured(): bool
{
    return get_auth_domain() && get_jwt_aud();
}

/**
 * Internal buffer accessor. Pass a string to append, null to read.
 */
function debug_buffer(?string $message = null): array
{
    static $buffer = [];
    if ($message !== null) {
        $buffer[] = '[' . current_time('Y-m-d H:i:s') . '] ' . $message;
    }
    return $buffer;
}

// Informational message: only kept when debug mode is on, flushed once on shutdown.
function debug_log(string $message): void
{
    if (get_debug_mode()) {
        debug_buffer($message);
    }
}

// Authentication failure or login: always goes to the PHP error log (like sshd to syslog).
function log_event(string $message): void
{
    error_log('AutoLoginWithCloudflare: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- authentication audit trail
    debug_log($message);
}

add_action('shutdown', function () {
    $buffer = debug_buffer();
    if (empty($buffer)) {
        return;
    }
    // Stored oldest first; keep the newest 100 entries.
    $existing = get_transient(DEBUG_LOG_KEY) ?: [];
    set_transient(DEBUG_LOG_KEY, array_slice(array_merge($existing, $buffer), -100), WEEK_IN_SECONDS);
});

function get_debug_logs(): array
{
    return get_transient(DEBUG_LOG_KEY) ?: [];
}

function clear_debug_logs(): void
{
    delete_transient(DEBUG_LOG_KEY);
}

/**
 * Fetch the JWKS from Cloudflare. Returns the "keys" array, or [] on failure.
 */
function fetch_jwks(): array
{
    // wp_safe_remote_get refuses internal/loopback targets should the domain be misconfigured.
    $response = wp_safe_remote_get('https://' . get_auth_domain() . '/cdn-cgi/access/certs', [
        'timeout'             => 5,
        'redirection'         => 0,
        'limit_response_size' => 65536,
    ]);

    if (is_wp_error($response)) {
        log_event('Failed to fetch JWKS - ' . $response->get_error_message());
        return [];
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        log_event('Failed to fetch JWKS - HTTP ' . wp_remote_retrieve_response_code($response));
        return [];
    }

    $jwks = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        log_event('Invalid JWKS response - ' . json_last_error_msg());
        return [];
    }

    debug_log('JWKS refreshed successfully from ' . get_auth_domain());
    return $jwks['keys'];
}

/**
 * Return the cached JWKS "keys" array, fetching it when absent.
 * If $kid is not in the cached set (Cloudflare rotated its key), refetch,
 * but at most once per JWKS_MIN_REFRESH so bogus kids cannot hammer Cloudflare.
 * Failures are cached briefly so an outage does not add a remote call to every request.
 */
function get_jwks(string $kid): array
{
    $cache = get_transient(CACHE_KEY);
    $valid = is_array($cache) && isset($cache['time'], $cache['keys']) && is_array($cache['keys']);

    if ($valid && !in_array($kid, array_column($cache['keys'], 'kid'), true)
        && $cache['time'] < time() - JWKS_MIN_REFRESH) {
        $valid = false;
    }

    if (!$valid) {
        $cache = ['time' => time(), 'keys' => fetch_jwks()];
        set_transient(CACHE_KEY, $cache, $cache['keys'] ? HOUR_IN_SECONDS : JWKS_MIN_REFRESH);
    }

    return $cache['keys'];
}

/**
 * Turn raw JWKS keys into php-jwt Keys, keeping only RSA keys bound to RS256.
 * Restricting the set here, rather than inspecting the token header, pins the
 * algorithm: php-jwt rejects any token whose alg differs from its key's alg.
 */
function parse_keys(array $jwks): array
{
    $jwks = array_values(array_filter($jwks, function ($k) {
        return is_array($k)
            && isset($k['kid']) && is_string($k['kid']) && $k['kid'] !== ''
            && ($k['kty'] ?? '') === 'RSA'
            && ($k['alg'] ?? JWT_ALG) === JWT_ALG;
    }));

    if (!$jwks) {
        return [];
    }
    return JWK::parseKeySet(['keys' => $jwks], JWT_ALG);
}

/**
 * Verify the token and return the email claim, or null.
 * php-jwt checks: segment count, signature, alg/key binding, kid lookup, exp, nbf, iat.
 * We additionally require exp and check aud, iss and email.
 */
function validate_jwt(string $token): ?string
{
    try {
        $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $token, 2)[0]));
        $kid    = is_object($header) && isset($header->kid) && is_string($header->kid) ? $header->kid : '';
        if ($kid === '') {
            log_event('Missing kid in JWT header');
            return null;
        }

        $keys = parse_keys(get_jwks($kid));
        if (!$keys) {
            log_event('No usable RS256 keys in JWKS');
            return null;
        }

        // JWT::$leeway is global to every plugin sharing php-jwt; restore it afterwards.
        $leeway       = JWT::$leeway;
        JWT::$leeway  = JWT_LEEWAY;
        try {
            $claims = JWT::decode($token, $keys);
        } finally {
            JWT::$leeway = $leeway;
        }
    } catch (\Throwable $e) {
        log_event('JWT decode failed - ' . $e->getMessage());
        return null;
    }

    // php-jwt only checks exp when present; a token without it would never expire.
    if (!isset($claims->exp)) {
        log_event('Missing expiry (exp) claim in JWT');
        return null;
    }

    $aud          = $claims->aud ?? null;
    $expected_aud = get_jwt_aud();
    if (!(is_array($aud) ? in_array($expected_aud, $aud, true) : $aud === $expected_aud)) {
        log_event('JWT audience validation failed (expected: ' . $expected_aud . ')');
        return null;
    }

    $expected_iss = 'https://' . get_auth_domain();
    if (($claims->iss ?? null) !== $expected_iss) {
        log_event('JWT issuer mismatch (expected: ' . $expected_iss . ')');
        return null;
    }

    $email = $claims->email ?? null;
    if (!is_string($email) || !is_email($email)) {
        log_event('Missing or invalid email claim in JWT');
        return null;
    }

    debug_log('JWT valid for ' . $email);
    return $email;
}

// The original "Auto Login with Cloudflare" (namespace Wpcfajal) bundles an old php-jwt
// that would be loaded instead of ours, so stay inactive while it is active.
function legacy_plugin_active(): bool
{
    return function_exists('Wpcfajal\\login');
}

// On activation, adopt the settings of the original plugin if this one has none yet.
function migrate_legacy_options(): void
{
    foreach (['auth_domain', 'aud', 'redirect_login_page'] as $name) {
        $legacy = get_option('wpcfajal_' . $name);
        if ($legacy !== false && get_option('AutoLoginWithCloudflare_' . $name) === false) {
            update_option('AutoLoginWithCloudflare_' . $name, $legacy);
        }
    }
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\\migrate_legacy_options');

add_action('admin_notices', function () {
    if (legacy_plugin_active() && current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Auto Login for Cloudflare Access is inactive while the old "Auto Login with Cloudflare" plugin is active. Deactivate and delete the old plugin; its settings have been copied over.', 'auto-login-for-cloudflare-access') . '</p></div>';
    }
});

// Called on wp_loaded to log in visitors that carry a valid Cloudflare Access JWT.
function login()
{
    if (!is_configured() || is_user_logged_in() || legacy_plugin_active()) {
        return;
    }

    // Prefer the header Cloudflare injects (not readable by JavaScript); accept the cookie as fallback.
    // The token is verified cryptographically below; sanitizing never alters a well-formed JWT.
    if (isset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']) && is_string($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'])) {
        $token = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']));
    } elseif (isset($_COOKIE['CF_Authorization']) && is_string($_COOKIE['CF_Authorization'])) {
        $token = sanitize_text_field(wp_unslash($_COOKIE['CF_Authorization']));
    } else {
        return;
    }
    if ($token === '') {
        return;
    }

    debug_log('CF JWT found, validating...');

    $email = validate_jwt($token);
    if ($email === null) {
        return;
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        log_event('User not found with email: ' . $email);
        if (get_redirect_login()) {
            wp_die(
                wp_kses_post(__('<strong>Error</strong>: The user does not exist in this site. Please contact the site admin.', 'auto-login-for-cloudflare-access')),
                esc_html__('User not found', 'auto-login-for-cloudflare-access'),
                array(
                    'response'  => 403,
                    'link_url'  => '/cdn-cgi/access/logout',
                    'link_text' => esc_html__('Logout the current user.', 'auto-login-for-cloudflare-access'),
                )
            );
        }
        return;
    }

    wp_set_auth_cookie($user->ID);
    wp_set_current_user($user->ID);
    do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core login hook

    $cf_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])) : 'unknown';
    log_event('Successfully logged in user: ' . $user->user_login . ' (ID: ' . $user->ID . ', IP: ' . $cf_ip . ')');

    // wp_safe_redirect only allows local/allowed hosts and falls back to admin_url().
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect target, as in wp-login.php
    $redirect_to = isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to']) ? sanitize_url(wp_unslash($_REQUEST['redirect_to'])) : '';
    wp_safe_redirect($redirect_to !== '' ? $redirect_to : admin_url());
    exit;
}

function login_redirect()
{
    // Only redirect if not already logged in to prevent redirect loops
    if (is_configured() && get_redirect_login() && !is_user_logged_in()) {
        wp_safe_redirect(admin_url());
        exit;
    }
}

add_action('wp_loaded', __NAMESPACE__ . '\\login', 1);
add_action('login_form_login', __NAMESPACE__ . '\\login_redirect');

// End the Cloudflare Access session too, without cutting other logout handlers short.
add_filter('logout_redirect', function ($redirect_to) {
    return is_configured() ? '/cdn-cgi/access/logout' : $redirect_to;
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($actions) {
    $actions[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=AutoLoginWithCloudflare')) . '">' . esc_html__('Settings', 'auto-login-for-cloudflare-access') . '</a>';
    return $actions;
});
