<?php

namespace AutoLoginWithCloudflare;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

function settings_init()
{
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_auth_domain', array(
        'type'              => 'string',
        'sanitize_callback' => __NAMESPACE__ . '\\sanitize_auth_domain',
    ));
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_aud', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_redirect_login_page', array(
        'type'              => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_debug_mode', array(
        'type'              => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));

    add_settings_section(
        'AutoLoginWithCloudflare_section_general',
        __('Application settings', 'auto-login-for-cloudflare-access'),
        '',
        'AutoLoginWithCloudflare'
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_auth_domain',
        __('Auth domain', 'auto-login-for-cloudflare-access'),
        __NAMESPACE__ . '\\field_auth_domain_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_auth_domain',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_aud',
        __('Application audience (AUD) tag', 'auto-login-for-cloudflare-access'),
        __NAMESPACE__ . '\\field_aud_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_aud',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_redirect_login_page',
        __('Redirect login page', 'auto-login-for-cloudflare-access'),
        __NAMESPACE__ . '\\field_redirect_login_page_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_redirect_login_page',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );

    add_settings_section(
        'AutoLoginWithCloudflare_section_debug',
        __('Debug mode', 'auto-login-for-cloudflare-access'),
        __NAMESPACE__ . '\\section_debug_callback',
        'AutoLoginWithCloudflare'
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_debug_mode',
        __('Debug mode', 'auto-login-for-cloudflare-access'),
        __NAMESPACE__ . '\\field_debug_mode_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_debug',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_debug_mode',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );
}

add_action('admin_init', __NAMESPACE__ . '\\settings_init');

// Store a bare hostname; keep the previous value if the input is not one.
function sanitize_auth_domain($value): string
{
    if (trim((string) $value) === '') {
        return '';
    }
    $domain = normalize_domain($value);
    if ($domain === '') {
        add_settings_error(
            'AutoLoginWithCloudflare_auth_domain',
            'invalid_auth_domain',
            __('Invalid auth domain. Use your team domain, e.g. yourteam.cloudflareaccess.com.', 'auto-login-for-cloudflare-access')
        );
        return (string) get_option('AutoLoginWithCloudflare_auth_domain', '');
    }
    return $domain;
}

function section_debug_callback($args)
{
    echo '<p>' . esc_html__('Enable debug mode to see detailed logs about the authentication process.', 'auto-login-for-cloudflare-access') . '</p>';
}

function render_text_field($name, $constant, $option, $args)
{
    $value = defined($constant) ? constant($constant) : get_option($option);
    $disabled = defined($constant);
    ?>
    <input name="<?php echo esc_attr($name); ?>" type="text" id="<?php echo esc_attr($args['label_for']); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" <?php disabled($disabled); ?>>
    <?php
}

function render_checkbox_field($name, $constant, $option, $args, $label = null)
{
    if ($label === null) {
        $label = __('redirect to Cloudflare Access', 'auto-login-for-cloudflare-access');
    }
    $value = defined($constant) ? constant($constant) : get_option($option);
    $disabled = defined($constant);
    ?>
    <label for="<?php echo esc_attr($args['label_for']); ?>">
        <input name="<?php echo esc_attr($name); ?>" type="checkbox" id="<?php echo esc_attr($args['label_for']); ?>" <?php checked((bool) $value); ?> <?php disabled($disabled); ?>>
        <?php echo esc_html($label); ?>
    </label>
    <?php
}

function field_auth_domain_cb($args)
{
    render_text_field('AutoLoginWithCloudflare_auth_domain', 'WP_CF_ACCESS_AUTH_DOMAIN', 'AutoLoginWithCloudflare_auth_domain', $args);
}

function field_aud_cb($args)
{
    render_text_field('AutoLoginWithCloudflare_aud', 'WP_CF_ACCESS_JWT_AUD', 'AutoLoginWithCloudflare_aud', $args);
}

function field_redirect_login_page_cb($args)
{
    render_checkbox_field('AutoLoginWithCloudflare_redirect_login_page', 'WP_CF_ACCESS_REDIRECT_LOGIN', 'AutoLoginWithCloudflare_redirect_login_page', $args);
}

function field_debug_mode_cb($args)
{
    render_checkbox_field('AutoLoginWithCloudflare_debug_mode', 'WP_CF_ACCESS_DEBUG_MODE', 'AutoLoginWithCloudflare_debug_mode', $args, __('Enable debug mode', 'auto-login-for-cloudflare-access'));
}

add_action('admin_menu', function () {
    add_options_page(
        esc_html__('Auto Login for Cloudflare Access', 'auto-login-for-cloudflare-access'),
        esc_html__('Auto Login for Cloudflare Access', 'auto-login-for-cloudflare-access'),
        'manage_options',
        'AutoLoginWithCloudflare',
        function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            
            // Handle clear debug logs
            if (isset($_POST['clear_debug_logs']) && check_admin_referer('AutoLoginWithCloudflare_clear_logs')) {
                clear_debug_logs();
                echo '<div class="notice notice-success"><p>' . esc_html__('Debug logs cleared.', 'auto-login-for-cloudflare-access') . '</p></div>';
            }
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('AutoLoginWithCloudflare');
                    do_settings_sections('AutoLoginWithCloudflare');
                    submit_button(__('Save Settings', 'auto-login-for-cloudflare-access'));
                    ?>
                </form>
                
                <?php if (get_debug_mode()): ?>
                    <div style="margin-top: 30px; padding: 20px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">
                        <h2><?php echo esc_html__('Debug Logs', 'auto-login-for-cloudflare-access'); ?></h2>
                        <p style="color: #666;"><?php echo esc_html__('Last 100 debug events are shown below:', 'auto-login-for-cloudflare-access'); ?></p>
                        
                        <?php
                        $debug_logs = get_debug_logs();
                        
                        if (empty($debug_logs)):
                            echo '<p style="color: #999; font-style: italic;">' . esc_html__('No debug logs yet. Trigger the authentication flow to see logs.', 'auto-login-for-cloudflare-access') . '</p>';
                        else:
                            echo '<div style="background: #fff; padding: 15px; border: 1px solid #ccc; border-radius: 3px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5;">';
                            foreach (array_reverse($debug_logs) as $log):
                                // Color-code: green for success, red for failures
                                $color = '#000';
                                if (stripos($log, 'success') !== false) {
                                    $color = '#388e3c';
                                } elseif (preg_match('/fail|invalid|missing|mismatch|not found|error/i', $log)) {
                                    $color = '#d32f2f';
                                }
                                ?>
                                <div style="color: <?php echo esc_attr($color); ?>; padding: 3px 0; border-bottom: 1px solid #f0f0f0;">
                                    <?php echo esc_html($log); ?>
                                </div>
                                <?php
                            endforeach;
                            echo '</div>';
                        endif;
                        ?>
                        
                        <form method="post" style="margin-top: 15px;">
                            <?php wp_nonce_field('AutoLoginWithCloudflare_clear_logs'); ?>
                            <button type="submit" name="clear_debug_logs" class="button button-secondary"><?php echo esc_html__('Clear Debug Logs', 'auto-login-for-cloudflare-access'); ?></button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 30px; padding: 15px; background: #fffbea; border-left: 4px solid #ffb81c; border-radius: 3px;">
                        <p style="margin: 0;"><strong><?php echo esc_html__('Debug mode is disabled.', 'auto-login-for-cloudflare-access'); ?></strong> <?php echo esc_html__('Enable the debug checkbox above to see detailed logs about the authentication process.', 'auto-login-for-cloudflare-access'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
    );
});
