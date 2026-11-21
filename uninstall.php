<?php

// Remove everything the plugin stored. Debug logs may contain emails and IP addresses.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

delete_option('AutoLoginWithCloudflare_auth_domain');
delete_option('AutoLoginWithCloudflare_aud');
delete_option('AutoLoginWithCloudflare_redirect_login_page');
delete_option('AutoLoginWithCloudflare_debug_mode');
delete_transient('AutoLoginWithCloudflare_jwks');
delete_transient('AutoLoginWithCloudflare_debug_logs');
delete_metadata('user', 0, '_last_cf_access_time', '', true);
delete_metadata('user', 0, '_last_cf_access_ip', '', true);
