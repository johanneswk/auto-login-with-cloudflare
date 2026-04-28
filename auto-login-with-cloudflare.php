<?php

/**
 * @link               https://github.com/johanneswk/auto-login-with-cloudflare
 * @since              0.9.0
 * @package            AutoLoginWithCloudflare
 *
 * @wordpress-plugin
 * Plugin Name:        Auto Login with Cloudflare
 * Plugin URI:         https://github.com/johanneswk/auto-login-with-cloudflare
 * Description:        Allow login to Wordpress when using Cloudflare Access.
 * Version:            2.1.3
 * Author:             Johannes Kistemaker
 * Author URI:         https://github.com/johanneswk/
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least:  6.3
 * Requires PHP:       8.0
 * Tested up to:       6.9
 * Text Domain:        auto-login-with-cloudflare
 * Domain Path:        /languages
 */

namespace AutoLoginWithCloudflare;

if (!defined('WPINC')) {
    die;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/settings.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

// define('WP_CF_ACCESS_AUTH_DOMAIN', '');
// define('WP_CF_ACCESS_JWT_AUD', '');
// define('WP_CF_ACCESS_REDIRECT_LOGIN', true);

define('WP_CF_ACCESS_CACHE_KEY', 'AutoLoginWithCloudflare_jwks');
define('WP_CF_ACCESS_DEBUG_LOG_KEY', 'AutoLoginWithCloudflare_debug_logs');

function get_config($constant, $option)
{
    return defined($constant) ? constant($constant) : get_option($option);
}

function get_auth_domain()
{
    return get_config('WP_CF_ACCESS_AUTH_DOMAIN', 'AutoLoginWithCloudflare_auth_domain');
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

/**
 * Internal buffer accessor. Pass a string to append, null to read.
 * Using a static variable avoids polluting the global namespace.
 */
function debug_buffer(?string $message = null): array
{
    static $buffer = [];
    if ($message !== null) {
        $buffer[] = '[' . current_time('Y-m-d H:i:s') . '] ' . $message;
    }
    return $buffer;
}

// Buffer debug messages and flush once on shutdown
function debug_log($message)
{
    if (!get_debug_mode()) return;

    debug_buffer($message);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('AutoLoginWithCloudflare: ' . $message);
    }
}

add_action('shutdown', function () {
    if (!get_debug_mode()) return;
    $buffer = debug_buffer();
    if (empty($buffer)) return;
    $existing = get_transient(WP_CF_ACCESS_DEBUG_LOG_KEY) ?: [];
    $merged   = array_slice(array_merge($buffer, $existing), 0, 100);
    set_transient(WP_CF_ACCESS_DEBUG_LOG_KEY, $merged, WEEK_IN_SECONDS);
});

function get_debug_logs()
{
    return get_transient(WP_CF_ACCESS_DEBUG_LOG_KEY) ?: array();
}

function clear_debug_logs()
{
    delete_transient(WP_CF_ACCESS_DEBUG_LOG_KEY);
}

function refresh_keys()
{
    $response = wp_remote_get(esc_url_raw('https://' . get_auth_domain() . '/cdn-cgi/access/certs'));

    if (is_wp_error($response)) {
        $error_msg = $response->get_error_message();
        error_log('AutoLoginWithCloudflare: Failed to fetch JWKS - ' . $error_msg);
        debug_log('Failed to fetch JWKS - ' . $error_msg);
        return null;
    }

    $jwks = json_decode(wp_remote_retrieve_body($response), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($jwks)) {
        $error_msg = json_last_error_msg();
        error_log('AutoLoginWithCloudflare: Invalid JWKS response - ' . $error_msg);
        debug_log('Invalid JWKS response - ' . $error_msg);
        return null;
    }

    debug_log('JWKS refreshed successfully from ' . get_auth_domain());
    set_transient(WP_CF_ACCESS_CACHE_KEY, $jwks, HOUR_IN_SECONDS);
    return $jwks;
}

/**
 * Decode and validate JWT token, return email if valid
 * Validates: signature, algorithm, kid, aud, iss, iat, email
 */
function validate_jwt(string $token, array $keys): ?string
{
    // Set JWT leeway for exp/nbf validation - 5 seconds is best practice
    JWT::$leeway = defined('WP_CF_ACCESS_JWT_LEEWAY') ? constant('WP_CF_ACCESS_JWT_LEEWAY') : 5;
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        $msg = 'Invalid JWT format - expected 3 parts, got ' . count($parts);
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Decode header with RFC 4648-compliant base64url padding
    $header_b64 = $parts[0];
    $remainder = strlen($header_b64) % 4;
    if ($remainder) {
        $header_b64 .= str_repeat('=', 4 - $remainder);
    }
    
    $jwt_header = json_decode(base64_decode(strtr($header_b64, '-_', '+/')), true);
    if (!is_array($jwt_header)) {
        $msg = 'Failed to decode JWT header';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Enforce RS256 algorithm for security
    if (($jwt_header['alg'] ?? '') !== 'RS256') {
        $msg = 'Invalid JWT algorithm - expected RS256, got ' . ($jwt_header['alg'] ?? 'none');
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Require kid - don't fall back to all keys
    $kid = $jwt_header['kid'] ?? null;
    if (!$kid || !isset($keys[$kid])) {
        $msg = 'Missing or invalid kid in JWT header' . ($kid ? ' (kid not in JWKS keyset)' : '');
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    $jwt_decoded = null;
    try {
        $jwt_decoded = JWT::decode($token, $keys[$kid]);
    } catch (\Throwable $e) {
        $msg = 'JWT decode failed - ' . $e->getMessage();
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Validate iat (issued at) claim
    if (!isset($jwt_decoded->iat)) {
        $msg = 'Missing issued-at (iat) claim in JWT';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Token shouldn't be from the future (clock skew protection, using JWT leeway)
    if ($jwt_decoded->iat > time() + JWT::$leeway) {
        $msg = 'Token iat is in the future (issued ' . date('Y-m-d H:i:s', $jwt_decoded->iat) . ')';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    debug_log('iat claim valid, issued at ' . date('Y-m-d H:i:s', $jwt_decoded->iat));
    
    // Validate aud (audience) — inline the verification
    $aud          = $jwt_decoded->aud ?? null;
    $expected_aud = get_jwt_aud();
    if (!(is_array($aud) ? in_array($expected_aud, $aud, true) : $aud === $expected_aud)) {
        $msg = 'JWT audience validation failed (expected: ' . $expected_aud . ')';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    debug_log('aud claim valid');
    
    if (!isset($jwt_decoded->iss)) {
        $msg = 'Missing issuer (iss) claim in JWT';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    
    // Normalize issuer comparison (trim trailing slashes)
    $expected_iss = rtrim('https://' . get_auth_domain(), '/');
    $token_iss = rtrim($jwt_decoded->iss, '/');
    if ($token_iss !== $expected_iss) {
        $msg = 'Issuer mismatch - expected ' . $expected_iss . ', got ' . $token_iss;
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    debug_log('iss claim valid');
    
    if (!isset($jwt_decoded->email)) {
        $msg = 'Missing email claim in JWT';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return null;
    }
    debug_log('email claim present: ' . $jwt_decoded->email);
    
    return $jwt_decoded->email;
}

// Called on wp_loaded hook to handle Cloudflare Access JWT authentication
function login()
{
    if (!get_auth_domain() || !get_jwt_aud()) {
        return;
    }

    // Skip processing if user is already logged in (avoid unnecessary validation on every page)
    $current_user = wp_get_current_user();
    if ($current_user->ID !== 0) {
        return; // User already authenticated, no need to process JWT
    }

    $jwks = get_transient(WP_CF_ACCESS_CACHE_KEY);
    if (!$jwks) {
        $jwks = refresh_keys();
    }
    
    // Validate JWKS structure before parsing
    if (!$jwks || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        $msg = 'Invalid or empty JWKS structure. Aborting login process.';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        return;
    }

    // Prefer header (cannot be read by JavaScript) but accept cookie as fallback
    $cf_auth_jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? $_COOKIE['CF_Authorization'] ?? null;
    
    if (!$cf_auth_jwt) {
        debug_log('No CF JWT found in header or cookie');
        return;
    }
    
    debug_log('CF JWT found, validating...');

    try {
        $keys = JWK::parseKeySet($jwks);
        $email = validate_jwt($cf_auth_jwt, $keys);
        
        if (!$email) {
            $msg = 'JWT validation did not return an email';
            error_log('AutoLoginWithCloudflare: ' . $msg);
            debug_log($msg);
            return;
        }
        
        $user = get_user_by('email', $email);
        if (!$user) {
            $msg = 'User not found with email: ' . $email;
            error_log('AutoLoginWithCloudflare: ' . $msg);
            debug_log($msg);
            if (get_redirect_login()) {
                wp_die(
                    __('<strong>Error</strong>: The user does not exist in this site. Please contact the site admin.', 'auto-login-with-cloudflare'),
                    __('User not found', 'auto-login-with-cloudflare'),
                    array(
                        'response' => 403,
                        'link_url' => '/cdn-cgi/access/logout',
                        'link_text' => __('Logout the current user.', 'auto-login-with-cloudflare'),
                        'exit' => true,
                    )
                );
            }
            return;
        }
        
        wp_set_auth_cookie($user->ID);
        wp_set_current_user($user->ID);
        do_action('wp_login', $user->user_login, $user);
        
        // Log successful authentication with audit info
        $cf_ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'] ?? 'unknown');
        $msg = 'Successfully logged in user: ' . $user->user_login . ' (ID: ' . $user->ID . ', IP: ' . $cf_ip . ')';
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
        
        // Store authentication metadata
        update_user_meta($user->ID, '_last_cf_access_time', current_time('mysql'));
        update_user_meta($user->ID, '_last_cf_access_ip', $cf_ip);
        
        // Respect original destination if provided, otherwise go to admin
        $redirect_to = !empty($_REQUEST['redirect_to']) ? wp_sanitize_redirect(wp_unslash($_REQUEST['redirect_to'])) : admin_url();
        wp_safe_redirect($redirect_to);
        exit;
    } catch (\Throwable $e) {
        $msg = 'Login error - ' . $e->getMessage();
        error_log('AutoLoginWithCloudflare: ' . $msg);
        debug_log($msg);
    }
}

function login_redirect()
{
    if (!get_auth_domain() || !get_jwt_aud()) {
        return;
    }

    // Only redirect if not already logged in to prevent redirect loops
    if (get_redirect_login() && !is_user_logged_in()) {
        wp_redirect(admin_url());
        exit;
    }
}

add_action('wp_loaded', __NAMESPACE__ . '\\login', 1);
add_action('login_form_login', __NAMESPACE__ . '\\login_redirect');
add_action('wp_logout', function () {
    wp_safe_redirect('/cdn-cgi/access/logout');
    exit;
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('auto-login-with-cloudflare', false, basename(dirname(__FILE__)) . '/languages/');
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($actions) {
    $actions[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=AutoLoginWithCloudflare')) . '">' . __('Settings', 'auto-login-with-cloudflare') . '</a>';
    return $actions;
});